<?php

namespace App\Jobs\Drop;

use App\Models\Drop\DropSupplierOrder;
use App\Models\Drop\DropTrackingUpdate;
use App\Services\Drop\DropOrderService;
use App\Services\Drop\Suppliers\CJDropshippingConnector;
use App\Services\Drop\Suppliers\AliExpressConnector;
use App\Services\Drop\Suppliers\Contracts\SupplierConnectorInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\Drop\Shopify\ShopifyFulfillmentService;
use Illuminate\Support\Facades\Log;

/**
 * Drop Internacional — Fase 2
 * Job de polling de rastreio para ordens de fornecedores.
 *
 * Agendado a cada 6h em routes/console.php.
 * Consulta o fornecedor para cada DropSupplierOrder com status ordered/shipped
 * e registra novos eventos de rastreio. Marca como entregue ao detectar delivery.
 */
class TrackingPollingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Numero maximo de tentativas antes de abandonar o job */
    public int $tries = 3;

    /** Timeout do job em segundos */
    public int $timeout = 300;

    public function handle(DropOrderService $orderService): void
    {
        Log::info('TrackingPollingJob: iniciando polling de rastreio');

        $orders = DropSupplierOrder::whereIn('status', ['ordered', 'shipped'])
            ->whereNotNull('external_order_id')
            ->get();

        if ($orders->isEmpty()) {
            Log::info('TrackingPollingJob: nenhuma ordem pendente de rastreio');
            return;
        }

        Log::info('TrackingPollingJob: processando ordens', ['count' => $orders->count()]);

        $updated   = 0;
        $delivered = 0;
        $errors    = 0;

        foreach ($orders as $supplierOrder) {
            try {
                $connector = $this->resolveConnector($supplierOrder->supplier_slug);

                $tracking = $connector->getTracking($supplierOrder->external_order_id);

                if (empty($tracking)) {
                    continue;
                }

                $trackingNumber = $tracking['trackingNumber'] ?? null;
                $carrier        = $tracking['carrier'] ?? null;
                $status         = $tracking['status'] ?? null;
                $events         = $tracking['events'] ?? [];

                // Atualizar tracking_code e carrier se ainda nao preenchidos
                $isNewTracking = $trackingNumber && ! $supplierOrder->tracking_code;
                if ($isNewTracking) {
                    $supplierOrder->tracking_code    = $trackingNumber;
                    $supplierOrder->tracking_carrier = $carrier;
                    $supplierOrder->save();

                    // Criar fulfillment no Shopify para enviar numero de rastreio ao cliente
                    try {
                        $dropOrderForFulfillment = $supplierOrder->dropOrder;
                        if ($dropOrderForFulfillment) {
                            app(ShopifyFulfillmentService::class)->createFulfillment(
                                $dropOrderForFulfillment,
                                $supplierOrder
                            );
                        }
                    } catch (\Throwable $fulfillmentEx) {
                        Log::warning('TrackingPollingJob: falha ao criar fulfillment Shopify', [
                            'drop_supplier_order_id' => $supplierOrder->id,
                            'error'                  => $fulfillmentEx->getMessage(),
                        ]);
                    }
                }

                // Registrar novos eventos (evitar duplicatas)
                foreach ($events as $event) {
                    $eventDesc = is_array($event)
                        ? ($event['description'] ?? $event['trackingDetail'] ?? json_encode($event))
                        : (string) $event;

                    $eventAt = is_array($event)
                        ? ($event['time'] ?? $event['trackingTime'] ?? now())
                        : now();

                    $exists = DropTrackingUpdate::where('drop_supplier_order_id', $supplierOrder->id)
                        ->where('event_description', $eventDesc)
                        ->exists();

                    if (! $exists) {
                        DropTrackingUpdate::create([
                            'drop_supplier_order_id' => $supplierOrder->id,
                            'drop_order_id'          => $supplierOrder->drop_order_id,
                            'tracking_code'          => $trackingNumber ?? $supplierOrder->tracking_code,
                            'carrier'                => $carrier ?? $supplierOrder->tracking_carrier,
                            'event_description'      => $eventDesc,
                            'event_at'               => $eventAt,
                        ]);

                        $updated++;
                    }
                }

                // Detectar entrega confirmada
                if ($this->isDeliveredStatus($status, $events)) {
                    $supplierOrder->status = 'delivered';
                    $supplierOrder->save();

                    $dropOrder = $supplierOrder->dropOrder;
                    if ($dropOrder && ! in_array($dropOrder->status, ['delivered', 'completed', 'cancelled'])) {
                        $orderService->markAsDelivered($dropOrder);
                        $delivered++;

                        Log::info('TrackingPollingJob: pedido marcado como entregue', [
                            'drop_order_id'          => $dropOrder->id,
                            'drop_supplier_order_id' => $supplierOrder->id,
                            'external_order_id'      => $supplierOrder->external_order_id,
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                $errors++;
                Log::error('TrackingPollingJob: erro ao processar ordem', [
                    'drop_supplier_order_id' => $supplierOrder->id,
                    'external_order_id'      => $supplierOrder->external_order_id ?? null,
                    'error'                  => $e->getMessage(),
                ]);
            }
        }

        Log::info('TrackingPollingJob: polling concluido', [
            'total_orders' => $orders->count(),
            'new_events'   => $updated,
            'delivered'    => $delivered,
            'errors'       => $errors,
        ]);
    }

    /**
     * Verificar se o status ou eventos indicam entrega confirmada.
     */
    private function isDeliveredStatus(?string $status, array $events): bool
    {
        $keywords = ['delivered', 'entregue', 'delivery successful', 'signed'];

        if ($status && in_array(strtolower(trim($status)), $keywords)) {
            return true;
        }

        if (! empty($events)) {
            $last     = end($events);
            $lastDesc = '';

            if (is_array($last)) {
                $lastDesc = strtolower($last['description'] ?? $last['trackingDetail'] ?? '');
            } elseif (is_string($last)) {
                $lastDesc = strtolower($last);
            }

            foreach ($keywords as $kw) {
                if (str_contains($lastDesc, $kw)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Resolver conector pelo slug do fornecedor.
     * Extensivel para novos fornecedores.
     */
    private function resolveConnector(string $supplierSlug): SupplierConnectorInterface
    {
        return match ($supplierSlug) {
            'cj'         => app(CJDropshippingConnector::class),
            'aliexpress' => app(AliExpressConnector::class),
            default      => app(CJDropshippingConnector::class),
        };
    }
}
