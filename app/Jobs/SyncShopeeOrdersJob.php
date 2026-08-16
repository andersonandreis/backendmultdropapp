<?php

namespace App\Jobs;

use App\Models\MarketplaceAccount;
use App\Models\Order;
use App\Services\Financial\AutoPayService;
use App\Services\WebhookOrderService;
use App\Services\Integrations\Marketplaces\ShopeeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * SyncShopeeOrdersJob
 *
 * Disparado automaticamente via MarketplaceAccountObserver quando:
 * - Uma conta Shopee e criada (created)
 * - Status muda para "active" (updated + isDirty status)
 *
 * Busca pedidos READY_TO_SHIP desde account.data_inicial_import
 * (fallback: created_at da conta; ultimo fallback: 7 dias).
 * De-duplica por marketplace_order_id OU external_order_id (pedidos vindos do legado).
 * Atualiza tracking/status se pedido ja existe e status mudou.
 * MUL-092: filtro ready_to_ship + dedup cruzado com legado.
 */
class SyncShopeeOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    public function __construct(
        public readonly int $accountId
    ) {}

    public function handle(ShopeeService $shopee): void
    {
        $account = MarketplaceAccount::find($this->accountId);

        if (! $account) {
            Log::channel('marketplace')->warning('[SyncShopeeOrdersJob] Conta nao encontrada', [
                'account_id' => $this->accountId,
            ]);
            return;
        }

        if ($account->platform !== 'shopee') {
            return;
        }

        // SEL-357: conta espelho readonly — nao sincronizar da API Shopee real
        if (($account->mirror_mode ?? 'active') === 'readonly') {
            Log::channel('marketplace')->info('[SyncShopeeOrdersJob] Conta espelho readonly (SEL-357) — skip sync real', [
                'account_id'            => $this->accountId,
                'mirror_source_backend' => $account->mirror_source_backend,
            ]);
            return;
        }

        // MUL-212 F2: guard por instalacao (banco) — cobre cron, observer e dispatch manual
        $cfg = app(\App\Services\InstallationConfig::class);
        if (! $cfg->pullsOrders('shopee') || $cfg->skipsCentralAccountPull((bool) $account->centrally_managed)) {
            Log::channel('marketplace')->info('[SyncShopeeOrdersJob] Pull desativado nesta instalacao (MUL-212 F2) — skip', [
                'account_id'        => $this->accountId,
                'centrally_managed' => (bool) $account->centrally_managed,
            ]);
            return;
        }

        if (! $account->shop_id || ! $account->access_token) {
            Log::channel('marketplace')->warning('[SyncShopeeOrdersJob] shop_id ou access_token ausente — abortando', [
                'account_id' => $this->accountId,
                'status'     => $account->status,
            ]);
            return;
        }

        if ($account->sync_blocked_at !== null) {
            Log::channel('marketplace')->warning('[SyncShopeeOrdersJob] Conta bloqueada — abortando', [
                'account_id' => $this->accountId,
            ]);
            return;
        }

        if (! $account->supplier_id) {
            Log::channel('marketplace')->warning('[SyncShopeeOrdersJob] supplier_id ausente — abortando sem criar pedidos', [
                'account_id' => $this->accountId,
                'client_id'  => $account->client_id,
            ]);
            return;
        }

        Log::channel('marketplace')->info('[SyncShopeeOrdersJob] Iniciando sync de pedidos', [
            'account_id' => $this->accountId,
            'shop_id'    => $account->shop_id,
            'client_id'  => $account->client_id,
        ]);

        // MUL-092: respeitar data_inicial_import da conta (fallback: created_at, ultimo fallback: 7 dias).
        // Sem esse filtro, o cron horario acaba puxando historico inteiro e duplica pedidos
        // que ja existem no legado. data_inicial_import e configuravel pelo seller em /integracoes.
        $sinceDate = $account->data_inicial_import
            ? \Carbon\Carbon::parse($account->data_inicial_import)->toDateTimeString()
            : ($account->created_at ? $account->created_at->toDateTimeString() : now()->subDays(7)->toDateTimeString());

        try {
            $orders = $shopee->fetchOrders($account, $sinceDate);
        } catch (\Throwable $e) {
            Log::channel('marketplace')->error('[SyncShopeeOrdersJob] fetchOrders excecao', [
                'account_id' => $this->accountId,
                'error'      => $e->getMessage(),
            ]);
            $this->fail($e);
            return;
        }

        if (empty($orders)) {
            Log::channel('marketplace')->info('[SyncShopeeOrdersJob] Nenhum pedido retornado pela API', [
                'account_id' => $this->accountId,
            ]);
            // Bug fix 2026-08-10: atualiza last_sync_at mesmo sem pedidos
            $account->update(['last_sync_at' => now()]);
            return;
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($orders as $rawOrder) {
            $orderSn = $rawOrder['order_sn'] ?? null;
            if (! $orderSn) {
                continue;
            }

            $shopeeStatus = strtolower($rawOrder['order_status'] ?? '');

            // MUL-092: importar SOMENTE pedidos com status 'A Enviar' (READY_TO_SHIP).
            // Motivo: evitar duplicar com pedidos ja no legado. Depois que o cliente
            // conecta, so importar o que ainda precisa ser processado (a enviar).
            // Alinha com ImportMarketplaceAccountDataJob::importShopeeOrders.
            if ($shopeeStatus !== 'ready_to_ship') {
                $skipped++;
                continue;
            }

            // MUL-092: dedup robusto — cruzar com pedidos do legado (external_order_id)
            // e com pedidos criados via OAuth (marketplace_order_id). Sem cruzar com
            // external_order_id, seller com Shopee ja no legado ganha o pedido 2x.
            // Ver auditoria MUL-092: storetotao (client 12) — 64 pedidos duplicados
            // do legado (external_order_id = order_sn) apos conectar Shopee OAuth.
                        // MUL-187: sem filtro de source — pedido pode ja existir via Bling/legado
            // com o mesmo marketplace_order_id; filtrar por source criava duplicata zerada.
$existing = Order::where('client_id', $account->client_id)
                ->where(function ($q) use ($orderSn) {
                    $q->where('marketplace_order_id', $orderSn)
                      ->orWhere('external_order_id', $orderSn);
                })
                ->first();

            if ($existing) {
                // Pedido ja existe — atualizar status e tracking se mudou
                $alreadyPaid = in_array($existing->status, ['paid', 'shipped', 'completed', 'delivered']);

                if ($alreadyPaid) {
                    $skipped++;
                    continue;
                }

                $existing->update([
                    'status'           => $this->mapShopeeStatus($shopeeStatus),
                    'canonical_status' => $this->mapShopeeStatus($shopeeStatus),
                    'tracking_number'  => $rawOrder['tracking_no'] ?? $existing->tracking_number,
                    'carrier_name'     => $rawOrder['package_list'][0]['shipping_carrier'] ?? $rawOrder['shipping_carrier'] ?? $existing->carrier_name,
                    'updated_at'       => now(),
                ]);
                $updated++;
            } else {
                // MUL-197: pedido nasce RASCUNHO (is_draft=1) e so vira pedido normal na
                // PROMOCAO (DraftOrderPromoter), quando completo: customer_name, total>0,
                // itens, paid_at. Rascunho nao dispara fanout/efeitos (OrderObserver
                // suprime) nem AutoPay — ambos rodam na promocao.
                // MUL-329: data real da venda, em horario LOCAL (era ->utc). Mesmo padrao do pay_time abaixo.
                $marketplaceCreatedAt = ! empty($rawOrder["create_time"])
                    ? \Carbon\Carbon::createFromTimestamp((int) $rawOrder["create_time"])->setTimezone(config('app.timezone'))
                    : null;

                $payTime = ! empty($rawOrder['pay_time'])
                    ? \Carbon\Carbon::createFromTimestamp((int) $rawOrder['pay_time'])->setTimezone(config('app.timezone'))
                    : null;

                $newOrder = Order::create([
                    'client_id'              => $account->client_id,
                    'supplier_id'            => $account->supplier_id,
                    'marketplace_account_id' => $account->id,
                    'source'                 => 'shopee',
                    'marketplace_order_id'   => $orderSn,
                    'order_number'           => $orderSn,
                    'status'                 => $this->mapShopeeStatus($shopeeStatus),
                    'canonical_status'       => $this->mapShopeeStatus($shopeeStatus),
                    'total'                  => $rawOrder['total_amount'] ?? 0,
                    'customer_name'          => $this->extractCustomerName($rawOrder),
                    'paid_at'                => $payTime,
                    "marketplace_created_at" => $marketplaceCreatedAt,
                    'buyer_username'         => $rawOrder['buyer_username'] ?? null,
                    'tracking_number'        => $rawOrder['tracking_no'] ?? null,
                    'carrier_name'           => $rawOrder['package_list'][0]['shipping_carrier'] ?? $rawOrder['shipping_carrier'] ?? null,
                    'raw_payload'            => json_encode($rawOrder),
                    'is_draft'               => true,
                    'draft_reason'           => 'awaiting_validation',
                    'created_at'             => now(),
                    'updated_at'             => now(),
                ]);
                $created++;

                // MES-043: sincronizar itens do pedido a partir do raw_payload
                // item_list ja vem no fetchOrders (response_optional_fields inclui item_list)
                try {
                    WebhookOrderService::upsertShopeeItemsFromPayload($newOrder, $rawOrder, $account);
                } catch (\Throwable $e) {
                    Log::channel('marketplace')->warning('[SyncShopeeOrdersJob] upsertItems falhou (nao critico)', [
                        'order_sn' => $orderSn,
                        'error'    => $e->getMessage(),
                    ]);
                }

                // MUL-197: promocao imediata quando o payload ja veio completo.
                // Fanout order.created + AutoPay (idempotente) saem do promoter.
                // Incompleto: fica rascunho e o EnrichShopeeOrderJob busca o resto
                // via get_order_detail (retry/refresh/backoff).
                try {
                    [$promoted] = app(\App\Services\Orders\DraftOrderPromoter::class)->promote($newOrder, 'sync_shopee_job');
                    if (! $promoted) {
                        \App\Jobs\EnrichShopeeOrderJob::dispatch($newOrder->id)->delay(now()->addSeconds(60));
                    }
                } catch (\Throwable $e) {
                    Log::channel('marketplace')->warning('[SyncShopeeOrdersJob] promocao/enrich dispatch falhou', [
                        'order_sn' => $orderSn,
                        'error'    => $e->getMessage(),
                    ]);
                }
            }
        }

        $account->update(['last_sync_at' => now()]);

        Log::channel('marketplace')->info('[SyncShopeeOrdersJob] Sync concluido', [
            'account_id' => $this->accountId,
            'shop_id'    => $account->shop_id,
            'total_api'  => count($orders),
            'created'    => $created,
            'updated'    => $updated,
            'skipped'    => $skipped,
        ]);
    }

    /**
     * MUL-197: nome do comprador do recipient_address do payload, ignorando
     * mascaras da Shopee (pedidos antigos voltam "*****" — sem alfanumerico).
     */
    private function extractCustomerName(array $rawOrder): ?string
    {
        $name = trim((string) ($rawOrder['recipient_address']['name'] ?? ''));
        if ($name === '' || ! preg_match('/[\p{L}0-9]/u', $name)) {
            return null;
        }
        return $name;
    }

    private function mapShopeeStatus(string $shopeeStatus): string
    {
        return match ($shopeeStatus) {
            'unpaid'        => 'pending',
            'ready_to_ship' => 'processing',
            'processed'     => 'processing',
            'shipped'       => 'shipped',
            'in_cancel'     => 'cancelled',
            'cancelled'     => 'cancelled',
            'to_return'     => 'returned',
            'completed'     => 'completed',
            default         => 'pending',
        };
    }
}
