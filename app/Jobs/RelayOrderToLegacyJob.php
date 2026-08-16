<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Webhooks\LegacyMLRelayService;
use App\Services\GoolhubBridgeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * RelayOrderToLegacyJob — NOV-150-B
 *
 * Relay assincrono de pedido criado via webhook-first para o sistema legado (goolhub.io).
 * Mantido para compatibilidade backward: o legado ainda processa pedidos ML/Shopee.
 *
 * O job usa os servicos de relay ja existentes:
 *   - ML:     LegacyMLRelayService::relayIfLegacy() — mesmo flow que o WebhookDispatcherService
 *   - Shopee: GoolhubBridgeService::relayShopeeEvent() — mesmo flow que ShopeeWebhookController
 *
 * Assim, nao ha duplicacao de logica — este job apenas chama o que ja existe,
 * garantindo que o legado receba o evento mesmo quando o Order foi criado pelo
 * caminho webhook-first (zero-latencia) antes do relay ocorrer.
 *
 * Retries: 3 tentativas com backoff exponencial (30s, 5min, 30min).
 * Fila: default (nao bloqueia o hot path).
 *
 * Idempotencia no legado: o bridge goolhub.io usa o ml_order_id / order_sn
 * como chave de deduplicacao — receber o mesmo pedido 2x nao duplica no legado.
 */
class RelayOrderToLegacyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 30;
    public int $backoff = 30; // segundos entre retries (Laravel dobra automaticamente)

    public function __construct(
        protected readonly int    $orderId,
        protected readonly string $marketplace, // 'mercadolivre' | 'shopee'
    ) {
        $this->onQueue('legacy'); // NOV-199: carga do legado nao compete com a fila default
    }

    public function handle(
        LegacyMLRelayService $legacyMLRelayService,
        GoolhubBridgeService  $bridgeService,
    ): void {
        // Buscar o pedido sem TenantSupplierScope (contexto de job)
        $order = Order::withoutGlobalScopes()
            ->with(['marketplaceAccount.client'])
            ->find($this->orderId);

        if (! $order) {
            Log::warning('[RelayOrderToLegacyJob] Pedido nao encontrado', [
                'order_id'    => $this->orderId,
                'marketplace' => $this->marketplace,
            ]);
            return;
        }

        match ($this->marketplace) {
            'mercadolivre' => $this->relayML($order, $legacyMLRelayService),
            'shopee'       => $this->relayShopee($order, $bridgeService),
            default        => Log::warning('[RelayOrderToLegacyJob] Marketplace nao suportado para relay', [
                'marketplace' => $this->marketplace,
                'order_id'    => $this->orderId,
            ]),
        };
    }

    /**
     * Relay de pedido ML para o legado via LegacyMLRelayService.
     * Usa o mesmo endpoint bridge que o WebhookDispatcherService ja usa.
     */
    private function relayML(Order $order, LegacyMLRelayService $legacyMLRelayService): void
    {
        $mlOrderId  = $order->marketplace_order_id ?? $order->external_order_id;
        $mlUserId   = $order->marketplaceAccount?->ml_user_id;

        if (! $mlOrderId || ! $mlUserId) {
            Log::info('[RelayOrderToLegacyJob][ML] Pedido sem ml_order_id ou ml_user_id, sem relay', [
                'order_id' => $this->orderId,
            ]);
            return;
        }

        // Monta payload minimo compativel com o que o WebhookDispatcherService enviaria
        $payload = [
            'topic'    => 'orders_v2',
            'resource' => "/orders/{$mlOrderId}",
            'user_id'  => (int) $mlUserId,
        ];

        $legacyOrderId = $legacyMLRelayService->relayIfLegacy(
            'orders_v2',
            "/orders/{$mlOrderId}",
            (string) $mlUserId,
            $payload
        );

        Log::info('[RelayOrderToLegacyJob][ML] Relay concluido', [
            'order_id'        => $this->orderId,
            'ml_order_id'     => $mlOrderId,
            'legacy_order_id' => $legacyOrderId,
        ]);
    }

    /**
     * Relay de pedido Shopee para o legado via GoolhubBridgeService.
     * Usa o mesmo endpoint bridge que o ShopeeWebhookController ja usa.
     */
    private function relayShopee(Order $order, GoolhubBridgeService $bridgeService): void
    {
        $orderSn = $order->marketplace_order_id ?? $order->external_order_id;
        $shopId  = $order->shop_id
                ?? $order->marketplaceAccount?->shop_id;

        if (! $orderSn || ! $shopId) {
            Log::info('[RelayOrderToLegacyJob][Shopee] Pedido sem order_sn ou shop_id, sem relay', [
                'order_id' => $this->orderId,
            ]);
            return;
        }

        // Monta payload code=3 (ORDER_STATUS_UPDATE) compativel com o que o legado espera
        $data = [
            'ordersn' => $orderSn,
            'status'  => strtoupper($order->status ?? 'READY_TO_SHIP'),
            'shop_id' => (int) $shopId,
        ];

        $result = $bridgeService->relayShopeeEvent(3, $data);

        if ($result['success']) {
            Log::info('[RelayOrderToLegacyJob][Shopee] Relay concluido com sucesso', [
                'order_id' => $this->orderId,
                'order_sn' => $orderSn,
            ]);
        } else {
            // Falha no relay — logar e deixar o retry do job tratar
            Log::warning('[RelayOrderToLegacyJob][Shopee] Falha no relay', [
                'order_id' => $this->orderId,
                'order_sn' => $orderSn,
                'error'    => $result['error'] ?? 'unknown',
            ]);

            // Lancar excecao para que o job seja recolocado na fila (retry)
            throw new \RuntimeException(
                "[RelayOrderToLegacyJob][Shopee] Bridge falhou: " . ($result['error'] ?? 'unknown')
            );
        }
    }

    /**
     * Callback quando o job esgota as tentativas (failed).
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('[RelayOrderToLegacyJob] Job falhou apos todas as tentativas', [
            'order_id'    => $this->orderId,
            'marketplace' => $this->marketplace,
            'error'       => $exception->getMessage(),
        ]);
    }
}
