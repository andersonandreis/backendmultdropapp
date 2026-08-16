<?php

namespace App\Jobs;

use App\Models\BridgeRelayQueue;
use App\Models\MarketplaceAccount;
use App\Models\Order;
use App\Services\GoolhubBridgeService;
use App\Services\Webhooks\LegacyMLRelayService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RecoverPendingOrdersJob
 *
 * Job diario de recuperacao: busca pedidos ML e Shopee com legacy_id NULL
 * mais velhos que 1 hora e tenta relay novamente.
 *
 * Diferente do RepairMLLegacyIdCommand (que e manual), este job:
 *   - Roda automaticamente todo dia as 02:00 via scheduler
 *   - Cobre ML E Shopee
 *   - Enfileira na bridge_relay_queue para backoff controlado
 *   - Nao bloqueia: processa em background
 *
 * Logica:
 *   1. Busca pedidos ML com legacy_id NULL, mais velhos que 1h, com marketplace_account_id
 *   2. Busca pedidos Shopee com legacy_id NULL, mais velhos que 1h
 *   3. Para ML: chama LegacyMLRelayService::relayIfLegacy diretamente
 *      Se falhar: enfileira em bridge_relay_queue
 *   4. Para Shopee: enfileira em bridge_relay_queue para importacao via bridge
 *
 * Agendado em routes/console.php via:
 * Schedule::job(new RecoverPendingOrdersJob)->dailyAt('02:00')->withoutOverlapping()->name('recover-pending-orders');
 */
class RecoverPendingOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 600; // INF-029: aumentado de 300->600s (bridge lento + 678 pedidos)

    public function handle(
        LegacyMLRelayService $mlRelay,
        GoolhubBridgeService $bridge
    ): void {
        // INF-029: guard LEGACY_SYNC_ENABLED -- bridge goolhub.io pode estar fora quando sync desligado.
        // Com 678+ pedidos sem legacy_id: cada HTTP call ate 8s = timeout de 300s facilmente excedido.
        if (! config("app.legacy_sync_enabled", false)) {
            Log::info("[RecoverPendingOrdersJob] LEGACY_SYNC_ENABLED=false -- pulando recovery de bridge");
            return;
        }

        Log::info('[RecoverPendingOrdersJob] Iniciando recovery de pedidos sem legacy_id');

        $mlRecovered    = $this->recoverML($mlRelay);
        $shopeeQueued   = $this->recoverShopee($bridge);

        Log::info('[RecoverPendingOrdersJob] Recovery concluido', [
            'ml_recovered'  => $mlRecovered,
            'shopee_queued' => $shopeeQueued,
        ]);
    }

    private function recoverML(LegacyMLRelayService $mlRelay): int
    {
        $cutoff = now()->subHour();

        $orders = Order::where('source', 'mercadolivre')
            ->whereNull('legacy_id')
            ->whereNotNull('marketplace_account_id')
            ->where('created_at', '<', $cutoff)
            ->with('marketplaceAccount.client')
            ->limit(100)
            ->get();

        if ($orders->isEmpty()) {
            Log::info('[RecoverPendingOrdersJob] Nenhum pedido ML pendente encontrado');
            return 0;
        }

        Log::info('[RecoverPendingOrdersJob] Pedidos ML sem legacy_id encontrados', ['count' => $orders->count()]);

        $recovered = 0;
        $enqueued  = 0;

        foreach ($orders as $order) {
            $account   = $order->marketplaceAccount;
            $client    = $account?->client;
            $legacyId  = $client?->legacy_id_login;

            if (! $account || ! $legacyId) {
                Log::info('[RecoverPendingOrdersJob] ML order sem legacy_id_login', ['order_id' => $order->id]);
                continue;
            }

            $mlOrderId = $order->external_order_id ?? $order->marketplace_order_id;
            if (! $mlOrderId) {
                continue;
            }

            $resource = "/orders/{$mlOrderId}";
            $topic    = 'orders_v2';
            $payload  = [
                'resource'  => $resource,
                'topic'     => $topic,
                'user_id'   => $account->ml_user_id,
                'legacy_id' => $legacyId,
            ];

            // NOV-040 Opcao B: tenta lookup direto no legado (pedidos.nr_canal)
            // antes de bater no bridge. Resolve casos onde bridge esta com chave invalida
            // ou retorna 401, mas o pedido JA existe no legado.
            $directLegacyId = $this->tryDirectLookupML((int) $legacyId, (string) $mlOrderId);
            if ($directLegacyId) {
                $order->updateQuietly(['legacy_id' => $directLegacyId]);
                Log::info('[RecoverPendingOrdersJob] ML recovered via direct lookup', [
                    'order_id'        => $order->id,
                    'ml_order_id'     => $mlOrderId,
                    'legacy_order_id' => $directLegacyId,
                ]);
                $recovered++;
                usleep(50000); // 50ms
                continue;
            }

            try {
                $legacyOrderId = $mlRelay->relayIfLegacy($topic, $resource, (string) $account->ml_user_id, $payload);

                if ($legacyOrderId) {
                    $recovered++;
                } else {
                    // Bridge online mas nao retornou legacy_order_id
                    // Enfileira para retry posterior
                    $existing = BridgeRelayQueue::where('platform', 'mercadolivre')
                        ->where('ml_order_id', $mlOrderId)
                        ->whereIn('status', ['pending', 'processing'])
                        ->exists();

                    if (! $existing) {
                        BridgeRelayQueue::enqueueMLRelay(
                            $topic,
                            $resource,
                            (string) $account->ml_user_id,
                            $legacyId,
                            $payload,
                            $order->id,
                            'recovery: bridge returned no legacy_order_id'
                        );
                        $enqueued++;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[RecoverPendingOrdersJob] ML relay excecao', [
                    'order_id' => $order->id,
                    'error'    => $e->getMessage(),
                ]);

                $existing = BridgeRelayQueue::where('platform', 'mercadolivre')
                    ->where('ml_order_id', $mlOrderId)
                    ->whereIn('status', ['pending', 'processing'])
                    ->exists();

                if (! $existing) {
                    BridgeRelayQueue::enqueueMLRelay(
                        $topic,
                        $resource,
                        (string) $account->ml_user_id,
                        $legacyId,
                        $payload,
                        $order->id,
                        substr($e->getMessage(), 0, 500)
                    );
                    $enqueued++;
                }
            }

            usleep(200000); // 200ms entre pedidos para nao sobrecarregar bridge
        }

        Log::info('[RecoverPendingOrdersJob] ML recovery resultado', [
            'recovered' => $recovered,
            'enqueued'  => $enqueued,
        ]);

        return $recovered + $enqueued;
    }

    /**
     * NOV-040 Opcao B: lookup direto no banco legado por (legacy_id_login + ml_order_id).
     * Retorna o pedidos.id legado se existir, NULL caso contrario.
     * Mais barato que bater no bridge e contorna 401/down.
     */
    private function tryDirectLookupML(int $legacyIdLogin, string $mlOrderId): ?int
    {
        try {
            $integIds = DB::connection('legacy')->table('integracao')
                ->where('id_login', $legacyIdLogin)
                ->where('id_canal', 6)
                ->pluck('id');

            if ($integIds->isEmpty()) {
                return null;
            }

            $legacyOrderId = DB::connection('legacy')->table('pedidos')
                ->whereIn('id_integracao', $integIds)
                ->where('nr_canal', $mlOrderId)
                ->value('id');

            return $legacyOrderId ? (int) $legacyOrderId : null;
        } catch (\Throwable $e) {
            Log::warning('[RecoverPendingOrdersJob] direct lookup exception', [
                'legacy_id_login' => $legacyIdLogin,
                'ml_order_id'     => $mlOrderId,
                'error'           => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function recoverShopee(GoolhubBridgeService $bridge): int
    {
        $cutoff = now()->subHour();

        // Para Shopee: pedidos sem legacy_id e sem order_sn sao mais complexos
        // Aqui enfileiramos pedidos com marketplace_order_id preenchido (order_sn) para
        // importacao via bridge (importOrderByNumber)
        $orders = Order::where('source', 'shopee')
            ->whereNull('legacy_id')
            ->whereNotNull('marketplace_order_id')
            ->whereNotNull('marketplace_account_id')
            ->where('created_at', '<', $cutoff)
            ->with('marketplaceAccount.client')
            ->limit(200)
            ->get();

        if ($orders->isEmpty()) {
            Log::info('[RecoverPendingOrdersJob] Nenhum pedido Shopee pendente encontrado');
            return 0;
        }

        Log::info('[RecoverPendingOrdersJob] Pedidos Shopee sem legacy_id encontrados', ['count' => $orders->count()]);

        $enqueued = 0;
        $direct   = 0;

        foreach ($orders as $order) {
            $account  = $order->marketplaceAccount;
            $client   = $account?->client;
            $legacyId = $client?->legacy_id_login;

            if (! $account || ! $legacyId) {
                continue;
            }

            $orderSn = $order->marketplace_order_id;
            $shopId  = $account->shop_id;

            // Verifica se ja tem na bridge_relay_queue
            $existing = BridgeRelayQueue::where('platform', 'shopee')
                ->where('shopee_order_sn', $orderSn)
                ->whereIn('status', ['pending', 'processing'])
                ->exists();

            if ($existing) {
                continue;
            }

            // Tenta importacao direta via bridge
            try {
                $result = $bridge->importOrderByNumber($legacyId, 3, $orderSn, (string) $shopId);

                if ($result['success']) {
                    $legacyOrderId = $result['data']['pedido_id'] ?? null;
                    if ($legacyOrderId) {
                        $order->updateQuietly(['legacy_id' => (int) $legacyOrderId]);
                        $direct++;
                        continue;
                    }
                }
            } catch (\Throwable $e) {
                // Falhou — enfileira para retry
            }

            BridgeRelayQueue::enqueueShopeeRelay(
                3, // ORDER_STATUS_UPDATE
                (int) $shopId,
                [
                    'ordersn'   => $orderSn,
                    'shop_id'   => $shopId,
                    '_recovery' => true,
                    '_legacy_id' => $legacyId,
                ],
                $order->id,
                'recovery: shopee order without legacy_id'
            );
            $enqueued++;

            usleep(100000); // 100ms
        }

        Log::info('[RecoverPendingOrdersJob] Shopee recovery resultado', [
            'direct'   => $direct,
            'enqueued' => $enqueued,
        ]);

        return $direct + $enqueued;
    }
}
