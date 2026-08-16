<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\ClientProduct;
use App\Models\ErpAccount;
use App\Services\Integrations\Factories\MarketplaceFactory;
use App\Services\Integrations\Erps\BlingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sincroniza estoque e preco de um produto em todos os marketplaces vinculados.
 *
 * Logica de estoque simulado:
 * - Product.virtual_stock_qty = estoque inflado (ex: 1000) mostrado no marketplace
 * - Product.safety_margin_stock = threshold (ex: 20) — quando estoque real chega aqui, sincroniza o real
 * - Product.zero_out_margin_stock = zera (ex: 10) — quando chega aqui, manda 0
 * - Product.effectiveStock calcula automaticamente qual valor enviar
 *
 * NOV-081: adiciona pausa/reativacao automatica de anuncio por estoque:
 * - effectiveStock == 0  => pausa anuncio + marca paused_reason='stock_zero'
 * - effectiveStock >  0 E paused_reason='stock_zero' => reativa anuncio + limpa paused_reason
 * - Bling nao usa MarketplaceFactory mas tambem nao e mais ignorado — usa BlingService.pushStock
 *
 * Triggers:
 * - Inventory model observer (quando estoque muda)
 * - Manual via painel do fornecedor
 * - inventory:reconcile (a cada 30min — equivalente ao auditoria_estoque_zerado.php do legado)
 */
class SyncInventoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public readonly ?int $productId = null,
        public readonly ?int $supplierId = null
    ) {}

    public function handle(): void
    {
        // GATE DE SEGURANCA (29/05/2026): apos correcao do bug effective_stock que zerava
        // estoque <=10 e pausou ~35k anuncios, este job fica DESLIGADO ate decisao manual.
        // Pra religar: setar MARKETPLACE_SYNC_INVENTORY_ENABLED=true no .env + config:clear.
        // Ver feedback_effective_stock_nao_zera.
        if (!config('marketplace.sync_inventory_enabled', false)) {
            Log::info('[SyncInventory] job desligado por config (gate)', [
                'productId'  => $this->productId,
                'supplierId' => $this->supplierId,
            ]);
            return;
        }

        if ($this->productId) {
            $this->syncProduct(Product::with(['inventory', 'clientProducts.marketplaceAccount'])->find($this->productId));
        } elseif ($this->supplierId) {
            $this->syncSupplier();
        }
    }

    private function syncSupplier(): void
    {
        $products = Product::where('supplier_id', $this->supplierId)
            ->where('is_active', true)
            ->whereHas('clientProducts', fn($q) => $q->whereNotNull('external_listing_id'))
            ->with(['inventory', 'clientProducts.marketplaceAccount'])
            ->get();

        $synced = 0;
        $skipped = 0;

        foreach ($products as $product) {
            if ($this->syncProduct($product)) {
                $synced++;
            } else {
                $skipped++;
            }
        }

        Log::info('[SyncInventory] Supplier sync completo', [
            'supplier_id' => $this->supplierId,
            'synced'      => $synced,
            'skipped'     => $skipped,
        ]);
    }

    private function syncProduct(?Product $product): bool
    {
        if (!$product) {
            return false;
        }

        $realStock = $product->effectiveStock;
        // MUL-234: publishedStock passa a ser calculado POR SELLER dentro do loop (fake dinamico).
        // Bling ERP continua recebendo $realStock.
        $listings = $product->clientProducts()
            ->whereNotNull('external_listing_id')
            ->whereHas('marketplaceAccount', fn($q) => $q->where('status', 'active'))
            ->with('marketplaceAccount')
            ->get();

        if ($listings->isEmpty()) {
            return false;
        }

        $success = true;

        foreach ($listings as $listing) {
            $account = $listing->marketplaceAccount;

            try {
                // NOV-081 FIX 3: Bling nao usa MarketplaceFactory (ERP, nao marketplace de vendas).
                // Delegado ao BlingService.pushStock — nao mais ignorado silenciosamente.
                if ($account->platform === 'bling') {
                    $this->syncBlingStock($account, $product, $listing, $realStock);
                    continue;
                }

                $service = MarketplaceFactory::make($account);

                $price = $listing->custom_price ?: $product->price;

                // MUL-234: fake por seller — cada listing recebe seu proprio published stock
                $publishedStock = $product->publishedStock($listing->client_id);

                // NOV-079: usar external_listing_id como identificador principal
                $externalId = (string) ($listing->external_listing_id
                    ?: $listing->ml_external_item_id
                    ?: $listing->shopee_external_item_id
                    ?: $listing->custom_sku
                    ?: $product->sku);

                // NOV-081: logica de pausa/reativacao por estoque zero.
                // MUL-226-14: decide pelo PUBLICADO — piso atingido publica 0 e pausa o anuncio.
                if ($publishedStock === 0) {
                    $result = $this->handleZeroStock($service, $account, $externalId, $price, $listing);
                } elseif ($publishedStock > 0 && $listing->paused_reason === 'stock_zero') {
                    $result = $this->handleRestoreStock($service, $account, $externalId, $publishedStock, $price, $listing);
                } else {
                    $result = $service->syncInventoryAndPrice($account, $externalId, $publishedStock, (float) $price);
                }

                if ($result) {
                    $listing->update(['last_sync_at' => now(), 'sync_status' => 'synced']);
                } else {
                    $listing->update(['sync_status' => 'failed', 'last_sync_error' => 'syncInventoryAndPrice retornou false']);
                    $success = false;
                }

            } catch (\Exception $e) {
                $listing->update([
                    'sync_status'     => 'failed',
                    'last_sync_error' => $e->getMessage(),
                ]);
                $success = false;

                Log::error('[SyncInventory] Erro ao sincronizar', [
                    'product_id'        => $product->id,
                    'client_product_id' => $listing->id,
                    'platform'          => $account->platform,
                    'error'             => $e->getMessage(),
                ]);
            }
        }

        if ($success) {
            Log::info('[SyncInventory] Produto sincronizado em todos os canais', [
                'product_id'      => $product->id,
                'effective_stock' => $realStock,
                'published_stock' => 'per-seller (MUL-234)',
                'listings_count'  => $listings->count(),
            ]);
        }

        return $success;
    }

    /**
     * NOV-081: Trata estoque zerado — envia qty=0 e pausa o anuncio.
     * Nao repausa anuncio ja pausado por outro motivo (manual, review, needs_reauth).
     */
    private function handleZeroStock($service, $account, string $externalId, $price, ClientProduct $listing): bool
    {
        $result = $service->syncInventoryAndPrice($account, $externalId, 0, (float) $price);

        // So pausa se o anuncio esta ativo — nao sobrescreve pausa manual ou por review
        if (in_array($listing->listing_status, ['active', 'published', 'synced', null, ''])) {
            if (method_exists($service, 'pauseItem')) {
                try {
                    $service->pauseItem($account, $externalId);
                } catch (\Throwable $e) {
                    Log::warning('[SyncInventory] Falha ao pausar anuncio', [
                        'client_product_id' => $listing->id,
                        'platform'          => $account->platform,
                        'error'             => $e->getMessage(),
                    ]);
                }
            }

            $listing->update([
                'listing_status' => 'paused',
                'paused_reason'  => 'stock_zero',
            ]);

            Log::info('[SyncInventory] Anuncio pausado por estoque zero', [
                'client_product_id' => $listing->id,
                'platform'          => $account->platform,
                'external_id'       => $externalId,
            ]);
        }

        return $result;
    }

    /**
     * NOV-081: Reativa anuncio pausado especificamente por estoque zero.
     * Anuncios pausados por outros motivos (manual, review) nao sao tocados.
     */
    private function handleRestoreStock($service, $account, string $externalId, int $effectiveStock, $price, ClientProduct $listing): bool
    {
        $result = $service->syncInventoryAndPrice($account, $externalId, $effectiveStock, (float) $price);

        if (method_exists($service, 'activateItem')) {
            try {
                $service->activateItem($account, $externalId);
            } catch (\Throwable $e) {
                Log::warning('[SyncInventory] Falha ao reativar anuncio', [
                    'client_product_id' => $listing->id,
                    'platform'          => $account->platform,
                    'error'             => $e->getMessage(),
                ]);
            }
        }

        $listing->update([
            'listing_status' => 'active',
            'paused_reason'  => null,
        ]);

        Log::info('[SyncInventory] Anuncio reativado apos reposicao de estoque', [
            'client_product_id' => $listing->id,
            'platform'          => $account->platform,
            'external_id'       => $externalId,
            'effective_stock'   => $effectiveStock,
        ]);

        return $result;
    }

    /**
     * NOV-081 FIX 3 / ESTAB-0.4: Sincroniza estoque no Bling via BlingService.
     *
     * IMPORTANTE (27/06/2026): BlingService::pushStock() tem type-hint ErpAccount,
     * mas contas Bling sao cadastradas como MarketplaceAccount (tabela marketplace_accounts).
     * Nao ha nenhum registro em erp_accounts. O guard abaixo impede o TypeError que
     * gerava ~1.558 erros/hora em producao.
     *
     * TODO (NOV-140): migrar contas Bling para ErpAccount ou criar interface StockSyncable
     * comum a ambos os models para reabilitar este path de forma tipada.
     */
    private function syncBlingStock($account, Product $product, ClientProduct $listing, int $effectiveStock): void
    {
        // GUARD ESTAB-0.4: BlingService::pushStock() exige ErpAccount (type-hint na assinatura).
        // Contas Bling estao armazenadas como MarketplaceAccount — passar MarketplaceAccount
        // gera TypeError fatal (~1.558 erros/hora). Enquanto nao houver ErpAccount Bling,
        // registramos warning e saimos sem executar o push.
        $blingPushEnabled = (bool) config('services.bling.push_enabled');
        if (! ($account instanceof ErpAccount)
            && ! ($account instanceof \App\Models\MarketplaceAccount && $blingPushEnabled)) {
            Log::warning('[SyncInventory] syncBlingStock: account nao e ErpAccount — pushStock ignorado (NOV-140)', [
                'account_id'   => $account->id ?? null,
                'account_type' => get_class($account),
                'platform'     => $account->platform ?? 'unknown',
                'product_id'   => $product->id,
            ]);
            $listing->update([
                'sync_status'     => 'skipped',
                'last_sync_error' => 'Bling: conta MarketplaceAccount aguarda migracao para ErpAccount (NOV-140)',
            ]);
            return;
        }

        try {
            /** @var BlingService $blingService */
            $blingService = app(BlingService::class);

            $blingService->pushStock($account, $product, $effectiveStock);

            $listing->update(['last_sync_at' => now(), 'sync_status' => 'synced']);

            Log::info('[SyncInventory] Bling stock sincronizado', [
                'product_id' => $product->id,
                'account_id' => $account->id,
                'stock'      => $effectiveStock,
            ]);
        } catch (\Throwable $e) {
            $listing->update([
                'sync_status'     => 'failed',
                'last_sync_error' => 'Bling: ' . $e->getMessage(),
            ]);
            Log::error('[SyncInventory] Erro ao sincronizar Bling', [
                'product_id' => $product->id,
                'account_id' => $account->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
