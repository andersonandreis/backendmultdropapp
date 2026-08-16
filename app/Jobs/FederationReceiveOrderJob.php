<?php

namespace App\Jobs;

use App\Models\FederationOrderNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Webhooks\HubAIOrderWebhookController;

/**
 * NOV-171-C -- Processa notificacao de pedido do hub recebida pelo WL.
 *
 * Roda na queue 'webhooks'. O controller ja criou o registro federation_order_notifications
 * e fez o dedup. Este job executa o pos-processamento.
 *
 * Idempotente: busca pelo hub_delivery_id unico.
 */
class FederationReceiveOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 60;

    public function __construct(
        public readonly string $hubDeliveryId
    ) {}

    public function handle(): void
    {
        /** @var FederationOrderNotification|null $notification */
        $notification = FederationOrderNotification::where('hub_delivery_id', $this->hubDeliveryId)->first();

        if (! $notification) {
            Log::warning('[FederationReceiveOrder] notification nao encontrada', [
                'hub_delivery_id' => $this->hubDeliveryId,
            ]);
            return;
        }

        if ($notification->status === 'done') {
            Log::debug('[FederationReceiveOrder] ja processada, ignorando', [
                'hub_delivery_id' => $this->hubDeliveryId,
            ]);
            return;
        }

        $notification->update(['status' => 'processing']);

        try {
            $payload      = $notification->payload;
            $hubOrderId   = $notification->hub_order_id;
            $originTenant = $notification->origin_tenant;
            $event        = $payload['event'] ?? 'federation.order.update';

            Log::info('[FederationReceiveOrder] processando notificacao de pedido', [
                'hub_order_id'    => $hubOrderId,
                'hub_delivery_id' => $this->hubDeliveryId,
                'origin_tenant'   => $originTenant,
                'event'           => $event,
                'status'          => $payload['status'] ?? null,
            ]);

            // Letra B (INF-053): aplicar sync real no orders local via HubOrderSync
            // Fecha TODO NOV-171-E (nunca implementado). Delega pro mesmo nucleo
            // usado pelo HubAIOrderWebhookController — uma fonte de verdade sobre
            // como aplicar update vindo do hub. Idempotente pelo hub_delivery_id.
            try {
                $ctrl = app(HubAIOrderWebhookController::class);
                $response = $ctrl->handlePayload($payload);
                $syncStatus = $response->getStatusCode();
                $syncBody = json_decode($response->getContent(), true) ?? [];
                Log::info("[FederationReceiveOrder] sync aplicado via handlePayload", [
                    "hub_order_id"    => $hubOrderId,
                    "hub_delivery_id" => $this->hubDeliveryId,
                    "status"          => $syncStatus,
                    "action"          => $syncBody["action"] ?? null,
                    "local_order_id"  => $syncBody["order_id"] ?? null,
                ]);
            } catch (\Throwable $e) {
                Log::warning("[FederationReceiveOrder] sync falhou (nao trava markDone)", [
                    "hub_order_id"    => $hubOrderId,
                    "hub_delivery_id" => $this->hubDeliveryId,
                    "error"           => $e->getMessage(),
                ]);
            }

            $notification->markDone();

        } catch (\Throwable $e) {
            $notification->markFailed();
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        FederationOrderNotification::where('hub_delivery_id', $this->hubDeliveryId)
            ->update(['status' => 'failed']);

        Log::error('[FederationReceiveOrder] falhou apos 3 tentativas', [
            'hub_delivery_id' => $this->hubDeliveryId,
            'error'           => $exception->getMessage(),
        ]);
    }
}
