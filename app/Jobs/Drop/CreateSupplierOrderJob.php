<?php

namespace App\Jobs\Drop;

use App\Models\Drop\DropOrder;
use App\Models\Drop\DropSupplierOrder;
use App\Models\Drop\ImportedProduct;
use App\Services\Drop\Suppliers\AliExpressConnector;
use App\Services\Drop\Suppliers\CJDropshippingConnector;
use App\Services\Drop\Suppliers\Contracts\SupplierConnectorInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Drop Internacional - Fase 2
 * Cria automaticamente o pedido no fornecedor (AliExpress/CJ) apos pagamento confirmado.
 *
 * Disparado por:
 *  - ShopifyWebhookHandler::handleOrderCreated (quando financial_status = paid)
 *  - Pagar.me webhook callback (apos confirmacao de pagamento)
 *
 * Para cada item do DropOrder.items_json, localiza o ImportedProduct correspondente
 * e cria um DropSupplierOrder + chama o conector do fornecedor.
 */
class CreateSupplierOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    /** Backoff exponencial: 1min, 5min, 15min */
    public array $backoff = [60, 300, 900];

    public function __construct(
        private readonly int $dropOrderId
    ) {}

    public function handle(): void
    {
        $order = DropOrder::with(['dropStore'])->find($this->dropOrderId);

        if (!$order) {
            Log::error('CreateSupplierOrderJob: DropOrder nao encontrado', ['drop_order_id' => $this->dropOrderId]);
            return;
        }

        // So processar se o pedido estiver pago
        if (!in_array($order->status, ['payment_received', 'awaiting_supplier'], true)) {
            Log::info('CreateSupplierOrderJob: pedido nao esta pago, ignorando', [
                'drop_order_id' => $order->id,
                'status'        => $order->status,
            ]);
            return;
        }

        // Idempotencia: se ja tem SupplierOrders ativos, nao duplicar
        if ($order->supplierOrders()->where('status', '!=', 'failed')->exists()) {
            Log::info('CreateSupplierOrderJob: supplier orders ja existem, ignorando', [
                'drop_order_id' => $order->id,
            ]);
            return;
        }

        $items = $order->items_json ?? [];

        if (empty($items)) {
            // Fallback: tenta via imported_product_id direto no pedido
            if ($order->imported_product_id) {
                $items = [['imported_product_id' => $order->imported_product_id, 'quantity' => 1]];
            } else {
                Log::warning('CreateSupplierOrderJob: pedido sem items_json nem imported_product_id', [
                    'drop_order_id' => $order->id,
                ]);
                return;
            }
        }

        $shippingAddress = $order->shipping_address ?? [];

        foreach ($items as $item) {
            $this->processItem($order, $item, $shippingAddress);
        }

        // Atualizar status do pedido para awaiting_supplier
        if (!in_array($order->status, ['awaiting_supplier', 'processing'], true)) {
            $order->update(['status' => 'awaiting_supplier']);
        }

        Log::info('CreateSupplierOrderJob: concluido', [
            'drop_order_id' => $order->id,
            'items_count'   => count($items),
        ]);
    }

    private function processItem(DropOrder $order, array $item, array $shippingAddress): void
    {
        $importedProductId = $item['imported_product_id'] ?? $item['product_id'] ?? null;

        $importedProduct = null;
        if ($importedProductId) {
            $importedProduct = ImportedProduct::find($importedProductId);
        }

        // Fallback: buscar pelo shopify_product_id que vem no items_json do webhook
        if (!$importedProduct && !empty($item['shopify_product_id'])) {
            $importedProduct = ImportedProduct::where('shopify_product_id', $item['shopify_product_id'])
                ->where('client_id', $order->client_id)
                ->first();
        }

        if (!$importedProduct) {
            Log::warning('CreateSupplierOrderJob: ImportedProduct nao encontrado para item', [
                'drop_order_id' => $order->id,
                'item'          => $item,
            ]);
            return;
        }

        $supplierSlug = $importedProduct->supplier_slug ?? 'aliexpress';
        $connector    = $this->resolveConnector($supplierSlug);

        $orderData = $this->buildSupplierOrderData($order, $item, $importedProduct, $shippingAddress);

        // Criar DropSupplierOrder pendente antes de chamar a API (rastreabilidade)
        $supplierOrder = DropSupplierOrder::create([
            'drop_order_id'  => $order->id,
            'supplier_slug'  => $supplierSlug,
            'product_url'    => $importedProduct->external_supplier_id
                ? $this->buildProductUrl($supplierSlug, $importedProduct->external_supplier_id)
                : null,
            'variant_title'  => $item['variant_title'] ?? $item['name'] ?? $importedProduct->title,
            'cost_paid_usd'  => (float) ($importedProduct->cost_usd ?? 0),
            'status'         => 'pending',
        ]);

        try {
            $result = $connector->createOrder($orderData);

            if (!empty($result['success']) && !empty($result['external_order_id'])) {
                $supplierOrder->update([
                    'external_order_id' => $result['external_order_id'],
                    'cost_paid_usd'     => $result['estimated_cost'] ?? $supplierOrder->cost_paid_usd,
                    'status'            => 'ordered',
                    'ordered_at'        => now(),
                    'notes'             => null,
                ]);

                Log::info('CreateSupplierOrderJob: pedido criado no fornecedor', [
                    'drop_order_id'          => $order->id,
                    'drop_supplier_order_id' => $supplierOrder->id,
                    'supplier_slug'          => $supplierSlug,
                    'external_order_id'      => $result['external_order_id'],
                ]);
            } else {
                $error = $result['error'] ?? 'Erro desconhecido';
                $supplierOrder->update([
                    'status' => 'failed',
                    'notes'  => 'Falha na criacao: ' . $error,
                ]);

                Log::error('CreateSupplierOrderJob: falha ao criar pedido no fornecedor', [
                    'drop_order_id'          => $order->id,
                    'drop_supplier_order_id' => $supplierOrder->id,
                    'supplier_slug'          => $supplierSlug,
                    'error'                  => $error,
                ]);
            }
        } catch (\Throwable $e) {
            $supplierOrder->update([
                'status' => 'failed',
                'notes'  => 'Excecao: ' . $e->getMessage(),
            ]);

            Log::error('CreateSupplierOrderJob: excecao ao chamar API do fornecedor', [
                'drop_order_id'          => $order->id,
                'drop_supplier_order_id' => $supplierOrder->id,
                'supplier_slug'          => $supplierSlug,
                'error'                  => $e->getMessage(),
            ]);

            throw $e; // Relancar para retry do queue
        }
    }

    private function buildSupplierOrderData(
        DropOrder $order,
        array $item,
        ImportedProduct $product,
        array $shippingAddress
    ): array {
        return [
            'product_id'        => $product->external_supplier_id,
            'quantity'          => (int) ($item['quantity'] ?? 1),
            'sku_attr'          => $item['sku_attr'] ?? $item['variant_sku'] ?? '',
            'logistics'         => 'CAINIAO_STANDARD',
            'internal_order_id' => $order->id,
            'contact_person'    => $order->customer_name ?? ($shippingAddress['name'] ?? ''),
            'address'           => $shippingAddress['address1'] ?? $shippingAddress['address'] ?? '',
            'address2'          => $shippingAddress['address2'] ?? '',
            'city'              => $shippingAddress['city'] ?? $order->shipping_city ?? '',
            'province'          => $shippingAddress['province'] ?? $order->shipping_state ?? '',
            'zip'               => $shippingAddress['zip'] ?? $order->shipping_zip ?? '',
            'phone'             => $order->customer_phone ?? ($shippingAddress['phone'] ?? ''),
            'cpf'               => $order->customer_cpf ?? '',
        ];
    }

    private function buildProductUrl(string $supplierSlug, string $externalId): string
    {
        return match ($supplierSlug) {
            'aliexpress' => 'https://www.aliexpress.com/item/' . $externalId . '.html',
            'cj'         => 'https://app.cjdropshipping.com/product-detail.html?id=' . $externalId,
            default      => '',
        };
    }

    private function resolveConnector(string $supplierSlug): SupplierConnectorInterface
    {
        return match ($supplierSlug) {
            'aliexpress' => app(AliExpressConnector::class),
            'cj'         => app(CJDropshippingConnector::class),
            default      => app(AliExpressConnector::class),
        };
    }
}
