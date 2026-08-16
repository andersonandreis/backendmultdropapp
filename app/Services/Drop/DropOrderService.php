<?php

namespace App\Services\Drop;

use App\Models\Drop\DropOrder;
use App\Models\Drop\DropSupplierOrder;
use App\Models\Drop\DropTrackingUpdate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Drop Internacional - Servico de orquestracao de pedidos.
 *
 * Maquina de estados do DropOrder.status:
 *   new -> payment_received -> awaiting_supplier -> processing
 *       -> shipped -> delivered -> completed | cancelled
 */
class DropOrderService
{
    public function __construct(
        private DropPricingService $pricing
    ) {}

    /**
     * Processar pedido recebido via webhook Shopify (ja persistido como DropOrder).
     *
     * Calcula profit_estimate, avanca status para payment_received se pago.
     */
    public function processIncomingOrder(DropOrder $order): DropOrder
    {
        try {
            $profit = $this->pricing->calculateOrderProfit($order);
            $order->profit_estimate_usd = $profit['net_profit'];
        } catch (\Throwable $e) {
            Log::warning('DropOrderService: falha ao calcular profit', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
        }

        if ($order->financial_status === 'paid' && $order->status === 'new') {
            $order->status = 'payment_received';
        }

        $order->save();

        Log::info('DropOrderService: pedido processado', [
            'order_id'         => $order->id,
            'shopify_order_id' => $order->shopify_order_id ?? null,
            'status'           => $order->status,
            'profit_estimate'  => $order->profit_estimate_usd,
        ]);

        return $order;
    }

    /**
     * Criar ordem de compra no fornecedor (fluxo manual no MVP).
     *
     * Cria DropSupplierOrder e avanca DropOrder.status para awaiting_supplier.
     */
    public function createSupplierOrder(
        DropOrder $order,
        string $supplierSlug,
        string $productUrl,
        string $variantTitle,
        float $costPaidUsd
    ): DropSupplierOrder {
        $supplierOrder = DropSupplierOrder::create([
            'drop_order_id'   => $order->id,
            'client_id'       => $order->client_id,
            'supplier_slug'   => $supplierSlug,
            'product_url'     => $productUrl,
            'variant_title'   => $variantTitle,
            'cost_paid_usd'   => $costPaidUsd,
            'status'          => 'pending',
        ]);

        $order->status = 'awaiting_supplier';
        $order->save();

        Log::info('DropOrderService: ordem de fornecedor criada', [
            'drop_order_id'          => $order->id,
            'drop_supplier_order_id' => $supplierOrder->id,
            'supplier_slug'          => $supplierSlug,
        ]);

        return $supplierOrder;
    }

    /**
     * Registrar rastreio recebido para uma ordem de fornecedor.
     *
     * Atualiza DropSupplierOrder, cria DropTrackingUpdate e avanca DropOrder para shipped.
     */
    public function registerTracking(
        DropSupplierOrder $supplierOrder,
        string $trackingCode,
        string $carrier
    ): DropTrackingUpdate {
        $supplierOrder->tracking_code    = $trackingCode;
        $supplierOrder->tracking_carrier = $carrier;
        $supplierOrder->status           = 'shipped';
        $supplierOrder->save();

        $trackingUpdate = DropTrackingUpdate::create([
            'drop_supplier_order_id' => $supplierOrder->id,
            'drop_order_id'          => $supplierOrder->drop_order_id,
            'tracking_code'          => $trackingCode,
            'carrier'                => $carrier,
            'event_description'      => 'Pedido postado pelo fornecedor',
            'event_at'               => now(),
        ]);

        $dropOrder = $supplierOrder->dropOrder;
        $terminalStatuses = ['delivered', 'completed', 'cancelled'];
        if ($dropOrder && !in_array($dropOrder->status, $terminalStatuses)) {
            $dropOrder->status = 'shipped';
            $dropOrder->save();
        }

        Log::info('DropOrderService: rastreio registrado', [
            'drop_order_id'          => $supplierOrder->drop_order_id,
            'drop_supplier_order_id' => $supplierOrder->id,
            'tracking_code'          => $trackingCode,
            'carrier'                => $carrier,
        ]);

        return $trackingUpdate;
    }

    /**
     * Marcar pedido como entregue ao cliente final.
     */
    public function markAsDelivered(DropOrder $order): DropOrder
    {
        $order->status       = 'delivered';
        $order->delivered_at = now();
        $order->save();

        Log::info('DropOrderService: pedido marcado como entregue', ['drop_order_id' => $order->id]);

        return $order;
    }

    /**
     * Cancelar pedido Drop. Nao cancela no fornecedor automaticamente no MVP.
     */
    public function cancelOrder(DropOrder $order, string $reason = ''): DropOrder
    {
        $order->status        = 'cancelled';
        $order->cancel_reason = $reason;
        $order->cancelled_at  = now();
        $order->save();

        Log::info('DropOrderService: pedido cancelado', [
            'drop_order_id' => $order->id,
            'reason'        => $reason,
        ]);

        return $order;
    }

    /**
     * Listar pedidos que ainda nao tiveram ordem criada no fornecedor.
     * Status elegiveis: payment_received sem supplierOrders vinculados.
     */
    public function getPendingSupplierOrders(int $clientId): Collection
    {
        return DropOrder::where('client_id', $clientId)
            ->where('status', 'payment_received')
            ->whereDoesntHave('supplierOrders')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Listar pedidos com rastreio pendente (ordens no fornecedor sem tracking_code).
     */
    public function getPendingTracking(int $clientId): Collection
    {
        return DropSupplierOrder::where('client_id', $clientId)
            ->whereIn('status', ['pending', 'processing'])
            ->whereNull('tracking_code')
            ->with('dropOrder')
            ->orderBy('created_at')
            ->get();
    }
}
