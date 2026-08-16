<?php

namespace App\Jobs;

use App\Models\ClientProduct;
use App\Models\SyncLog;
use App\Services\Integrations\Factories\MarketplaceFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly int $clientProductId)
    {
    }

    public function handle(): void
    {
        $clientProduct = ClientProduct::with(['product', 'product.media', 'marketplaceAccount'])->find($this->clientProductId);

        if (!$clientProduct) {
            Log::warning("[SyncProducts] ClientProduct #{$this->clientProductId} não encontrado");
            return;
        }

        $account = $clientProduct->marketplaceAccount;
        $product = $clientProduct->product;

        if (!$account || !$product) {
            $this->logSync($clientProduct, 'failed', 'Conta marketplace ou produto não encontrado');
            return;
        }

        // FOR-067: guard — produto ja bloqueado por circuit breaker Shopee
        if ($clientProduct->sync_status === 'blocked_shopee') {
            $this->logSync($clientProduct, 'skipped', 'guard_client_product_blocked: produto bloqueado (shopee_item_abnormal — resolver no Seller Center)');
            return;
        }

        if ($account->status !== 'active') {
            $this->logSync($clientProduct, 'skipped', 'Conta marketplace inativa');
            return;
        }

        try {
            if ($account->platform === 'bling') {
                // NOV-188: Bling nao esta no MarketplaceFactory (e ERP); rotear pro BlingService.
                // Gate por flag pra rollout controlado do export de produto hub->Bling.
                if (!config('services.bling.push_enabled')) {
                    $this->logSync($clientProduct, 'skipped', 'Export Bling desabilitado (BLING_PUSH_ENABLED=false)');
                    return;
                }

                // MUL-226-01: freeze individual (multi-seleção no admin) — checa ANTES do global
                // pra que o unfreeze de catálogo não re-dispare produto congelado individualmente
                if ($clientProduct->sync_status === 'bling_frozen') {
                    $this->logSync($clientProduct, 'skipped', 'product_frozen: produto congelado para export Bling pelo admin');
                    return;
                }

                // MUL-226-01: freeze do catálogo inteiro (setting compartilhada com o painel Lovable).
                // Retomada: o unfreeze re-dispatcha os produtos pulados aqui (via sync_logs catalog_frozen)
                $catalogFrozen = \Illuminate\Support\Facades\DB::table('settings')
                    ->where('key', 'bling_catalog_frozen')->value('value');
                if ($catalogFrozen === '1' || $catalogFrozen === 'true') {
                    $this->logSync($clientProduct, 'skipped', 'catalog_frozen: exportação Bling do catálogo congelada pelo admin');
                    return;
                }

                $service = app(\App\Services\Integrations\Erps\BlingService::class);
            } else {
                $service = MarketplaceFactory::make($account);
            }

            // Shopee: sincronizar shopee_item_id do product com external_listing_id do client_product
            // para evitar que um item_id de outro shop force um "update" incorreto
            if ($account->platform === 'shopee') {
                if (empty($clientProduct->external_listing_id)) {
                    // Novo anuncio para este cliente: forcar add_item (nunca update)
                    $product->shopee_item_id = null;
                } else {
                    // Ja tem anuncio: garantir que o product use o item_id DESTE cliente
                    $product->shopee_item_id = (int) $clientProduct->external_listing_id;
                }
            }

            // FOR-067: circuit breaker Shopee — produto com status anormal (product.error_busi)
            // Conta tentativas recentes (<=30d) via sync_logs antes de chamar a API
            if ($account->platform === 'shopee') {
                $recentAbnormal = SyncLog::where('syncable_type', ClientProduct::class)
                    ->where('syncable_id', $clientProduct->id)
                    ->where('platform', 'shopee')
                    ->where('error_message', 'like', '%abnormal%')
                    ->where('created_at', '>', now()->subDays(30))
                    ->count();

                if ($recentAbnormal >= 3) {
                    $blockMsg = 'shopee_item_abnormal: ' . $recentAbnormal . ' tentativas bloqueadas pela Shopee — resolver no Seller Center';
                    $clientProduct->update([
                        'sync_status'     => 'blocked_shopee',
                        'last_sync_at'    => now(),
                        'last_sync_error' => $blockMsg,
                    ]);
                    SyncLog::create([
                        'syncable_type'   => ClientProduct::class,
                        'syncable_id'     => $clientProduct->id,
                        'platform'        => 'shopee',
                        'action'          => 'circuit_breaker_shopee_abnormal',
                        'direction'       => 'outbound',
                        'status'          => 'skipped',
                        'error_message'   => $blockMsg,
                        'request_payload' => json_encode(['product_id' => $clientProduct->product_id, 'tentativas' => $recentAbnormal]),
                    ]);
                    Log::warning('[SyncProducts] FOR-067: circuit_breaker_shopee_abnormal', [
                        'client_product_id' => $clientProduct->id,
                        'tentativas'        => $recentAbnormal,
                    ]);
                    return; // nao retenta
                }
            }

            $result = $service->syncProduct($account, $product);

            // Erro pode vir como false (anti-ban) OU como array com 'error' (falha API)
            if ($result === false) {
                // FOR-087: buscar palavra exata do sync_log mais recente e propagar ao last_sync_error
                $lastSyncLog = \App\Models\SyncLog::where('syncable_type', \App\Models\Product::class)
                    ->where('syncable_id', $product->id)
                    ->where('status', 'failed')
                    ->where('error_message', 'like', '%BLOQUEIO PREVENTIVO%')
                    ->orderByDesc('created_at')
                    ->value('error_message');

                if ($lastSyncLog && preg_match("/palavra proibida '(.+?)'/u", $lastSyncLog, $matches)) {
                    $word     = $matches[1];
                    $platform = str_contains($lastSyncLog, 'Mercado Livre') ? 'ML' : (str_contains($lastSyncLog, 'Shopee') ? 'Shopee' : 'TikTok Shop');
                    $errorMsg = "Palavra bloqueada: '{$word}' (marketplace {$platform}). Edite titulo/descricao.";
                } else {
                    $errorMsg = 'syncProduct retornou false (possivel bloqueio anti-ban)';
                }

                $clientProduct->update([
                    'sync_status'     => 'failed',
                    'last_sync_at'    => now(),
                    'last_sync_error' => $errorMsg,
                ]);
                $this->logSync($clientProduct, 'failed', $errorMsg);
                return;
            }

            if (is_array($result) && !empty($result['error'])) {
                $errorCode   = $result['error'] ?? '';
                $errorDetail = $errorCode . ': ' . ($result['message'] ?? '');

                // FOR-065: guard categoria nao-folha → status especifico + sem retry
                if ($errorCode === 'guard_category_not_leaf') {
                    $clientProduct->update([
                        'sync_status'     => 'invalid_category',
                        'last_sync_at'    => now(),
                        'last_sync_error' => $errorDetail,
                    ]);
                    // Nao chama logSync aqui — o guard ja gravou em sync_logs (status=skipped)
                    return;
                }

                // FOR-066: guard_account_blocked (kyc_pending) -- sem retry
                if ($errorCode === 'guard_account_blocked') {
                    $clientProduct->update([
                        'sync_status'     => 'error',
                        'last_sync_at'    => now(),
                        'last_sync_error' => $errorDetail,
                    ]);
                    return; // ShopeeService ja gravou em sync_logs
                }



                // FOR-068: error_invalid_logistic_info -- nenhum canal logistico habilitado na shop
                if ($errorCode === 'error_invalid_logistic_info') {
                    $logisticMsg = 'no_shipping_channel: nenhum canal logistico habilitado. Ative ao menos 1 no Seller Center.';
                    $account->update([
                        'status'             => 'no_shipping_channel',
                        'sync_blocked_at'    => now(),
                        'last_error_message' => $logisticMsg,
                    ]);
                    SyncLog::create([
                        'syncable_type'   => ClientProduct::class,
                        'syncable_id'     => $clientProduct->id,
                        'platform'        => $account->platform ?? 'shopee',
                        'action'          => 'guard_no_shipping_channel',
                        'direction'       => 'outbound',
                        'status'          => 'error',
                        'error_message'   => $logisticMsg,
                        'request_payload' => json_encode(['shop_id' => $account->shop_id, 'sku' => $clientProduct->custom_sku ?? 'n/a']),
                    ]);
                    $clientProduct->update([
                        'sync_status'     => 'error',
                        'last_sync_at'    => now(),
                        'last_sync_error' => $logisticMsg,
                    ]);
                    return; // nao retenta
                }

                // FOR-069: error_busi_missing_gtin -- GTIN obrigatorio na categoria Shopee
                if ($errorCode === 'error_busi_missing_gtin') {
                    $clientProduct->update([
                        'sync_status'     => 'missing_gtin',
                        'last_sync_at'    => now(),
                        'last_sync_error' => $errorDetail,
                    ]);
                    return; // ShopeeService ja gravou guard_missing_gtin em sync_logs
                }

                $clientProduct->update([
                    'sync_status'     => 'error',
                    'last_sync_at'    => now(),
                    'last_sync_error' => $errorDetail,
                ]);
                $this->logSync($clientProduct, 'failed', $errorDetail);
                return;
            }

            // Sucesso: salvar external_listing_id
            // ML retorna ['external_id' => ...], Shopee salva em product->shopee_item_id
            // mas também retorna response->item_id que precisamos capturar aqui
            $externalId = $result['external_id'] // ML via MercadoLivreService
                ?? $result['response']['item_id'] // Shopee via ShopeeService
                ?? $result['bling_product_id'] // Bling via BlingService (NOV-188)
                ?? null;

            if ($externalId) {
                $clientProduct->update([
                    'external_listing_id' => (string) $externalId,
                    'sync_status'         => 'synced',
                    'last_sync_at'        => now(),
                    'last_sync_error'     => null,
                    'listing_status'      => 'active',
                ]);
            } else {
                $clientProduct->update([
                    'sync_status'     => 'synced',
                    'last_sync_at'    => now(),
                    'last_sync_error' => null,
                ]);
            }

            $this->logSync($clientProduct, 'success');

            Log::info("[SyncProducts] Produto sincronizado", [
                'client_product_id' => $clientProduct->id,
                'platform'          => $account->platform,
                'external_id'       => $clientProduct->external_listing_id,
            ]);

        } catch (\Exception $e) {
            $clientProduct->update([
                'sync_status'     => 'error',
                'last_sync_at'    => now(),
                'last_sync_error' => $e->getMessage(),
            ]);

            $this->logSync($clientProduct, 'failed', $e->getMessage());

            Log::error("[SyncProducts] Erro ao sincronizar", [
                'client_product_id' => $clientProduct->id,
                'error'             => $e->getMessage(),
            ]);
        }
    }

    private function logSync(ClientProduct $cp, string $status, ?string $error = null): void
    {
        SyncLog::create([
            'syncable_type'   => ClientProduct::class,
            'syncable_id'     => $cp->id,
            'platform'        => $cp->marketplaceAccount?->platform ?? 'unknown',
            'action'          => $cp->external_listing_id ? 'Update Listing' : 'Create Listing',
            'direction'       => 'outbound',
            'status'          => $status,
            'error_message'   => $error,
            'request_payload' => json_encode([
                'custom_title' => $cp->custom_title,
                'custom_sku'   => $cp->custom_sku,
                'product_id'   => $cp->product_id,
            ]),
        ]);
    }
}
