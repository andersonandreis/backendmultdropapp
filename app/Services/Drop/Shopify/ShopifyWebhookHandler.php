<?php

namespace App\Services\Drop\Shopify;

use App\Models\Drop\DropOrder;
use App\Models\Drop\DropStore;
use App\Models\Drop\DropSupplierOrder;
use Illuminate\Support\Facades\Log;

/**
 * Handler de webhooks do Shopify — valida HMAC e processa eventos.
 */
class ShopifyWebhookHandler
{
    /**
     * Valida o HMAC-SHA256 do payload recebido do Shopify.
     * Lanca RuntimeException se invalido.
     *
     * @throws \RuntimeException
     */
    public function validateHmac(string $payload, string $hmacHeader, string $secret): bool
    {
        $computed = base64_encode(hash_hmac('sha256', $payload, $secret, true));

        if (!hash_equals($computed, $hmacHeader)) {
            throw new \RuntimeException('Shopify webhook HMAC invalido');
        }

        return true;
    }

    /**
     * Cria um DropOrder a partir de um evento orders/create do Shopify.
     * Idempotente: ignora se shopify_order_id ja existe.
     */
    public function handleOrderCreated(array $payload, int $clientId): DropOrder
    {
        $shopifyOrderId = (string) ($payload['id'] ?? '');

        // Idempotencia: retorna se ja existe
        $existing = DropOrder::where('shopify_order_id', $shopifyOrderId)
            ->where('client_id', $clientId)
            ->first();

        if ($existing) {
            Log::info('[ShopifyWebhookHandler] handleOrderCreated: pedido ja existe, ignorando', [
                'shopify_order_id' => $shopifyOrderId,
                'drop_order_id'    => $existing->id,
            ]);
            return $existing;
        }

        $customer      = $payload['customer'] ?? [];
        $shippingAddr  = $payload['shipping_address'] ?? null;
        $customerName  = trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));
        $customerEmail = $customer['email'] ?? null;
        $customerPhone = $customer['phone'] ?? null;

        $financialStatus = $payload['financial_status'] ?? 'pending';
        $status = ($financialStatus === 'paid') ? 'payment_received' : 'pending_payment';

        $utmData = $this->extractTrafficSource($payload);

        $order = DropOrder::create(array_merge([
            'client_id'           => $clientId,
            'shopify_order_id'    => $shopifyOrderId,
            'shopify_order_number' => (string) ($payload['order_number'] ?? ''),
            'customer_name'       => $customerName ?: null,
            'customer_email'      => $customerEmail ? encrypt($customerEmail) : null,
            'customer_phone'      => $customerPhone ? encrypt($customerPhone) : null,
            'shipping_address'    => $shippingAddr ? encrypt(json_encode($shippingAddr)) : null,
            'total_amount'        => $payload['total_price'] ?? '0.00',
            'currency'            => $payload['currency'] ?? 'BRL',
            'status'              => $status,
        ], $utmData));

        Log::info('[ShopifyWebhookHandler] Pedido Shopify criado', [
            'drop_order_id'    => $order->id,
            'shopify_order_id' => $shopifyOrderId,
            'status'           => $status,
            'client_id'        => $clientId,
        ]);

        // Disparar evento Purchase server-side (CAPI)
        try {
            app(\App\Services\Drop\DropTrackingEventService::class)->firePurchaseEvent($order);
        } catch (\Throwable $e) {
            Log::warning('[ShopifyWebhookHandler] firePurchaseEvent falhou: ' . $e->getMessage());
        }

        // Disparar criacao automatica no fornecedor se pedido veio pago
        if ($financialStatus === 'paid') {
            try {
                \App\Jobs\Drop\CreateSupplierOrderJob::dispatch($order->id)->onQueue('drop');
                Log::info('[ShopifyWebhookHandler] CreateSupplierOrderJob despachado', [
                    'drop_order_id'    => $order->id,
                    'shopify_order_id' => $shopifyOrderId,
                ]);
            } catch (\Throwable $e) {
                Log::warning('[ShopifyWebhookHandler] Falha ao despachar CreateSupplierOrderJob: ' . $e->getMessage());
            }
        }

        return $order;
    }

    /**
     * Atualiza status de um DropOrder existente quando financial_status muda.
     */
    public function handleOrderUpdated(array $payload, int $clientId): void
    {
        $shopifyOrderId = (string) ($payload['id'] ?? '');

        $order = DropOrder::where('shopify_order_id', $shopifyOrderId)
            ->where('client_id', $clientId)
            ->first();

        if (!$order) {
            Log::warning('[ShopifyWebhookHandler] handleOrderUpdated: pedido nao encontrado', [
                'shopify_order_id' => $shopifyOrderId,
                'client_id'        => $clientId,
            ]);
            return;
        }

        $financialStatus = $payload['financial_status'] ?? null;

        if ($financialStatus === 'paid' && $order->status === 'pending_payment') {
            $order->update(['status' => 'payment_received']);

            Log::info('[ShopifyWebhookHandler] Status atualizado para payment_received', [
                'drop_order_id'    => $order->id,
                'shopify_order_id' => $shopifyOrderId,
            ]);

            // Disparar criacao automatica no fornecedor apos pagamento tardio
            try {
                \App\Jobs\Drop\CreateSupplierOrderJob::dispatch($order->id)->onQueue('drop');
                Log::info('[ShopifyWebhookHandler] CreateSupplierOrderJob despachado (order updated)', [
                    'drop_order_id' => $order->id,
                ]);
            } catch (\Throwable $e) {
                Log::warning('[ShopifyWebhookHandler] Falha CreateSupplierOrderJob (update): ' . $e->getMessage());
            }
        }
    }

    /**
     * Registra evento de fulfillment criado (log por ora).
     */
    public function handleFulfillmentCreated(array $payload, int $clientId): void
    {
        Log::info('[ShopifyWebhookHandler] Fulfillment criado', [
            'fulfillment_id'   => $payload['id'] ?? null,
            'order_id'         => $payload['order_id'] ?? null,
            'tracking_number'  => $payload['tracking_number'] ?? null,
            'tracking_company' => $payload['tracking_company'] ?? null,
            'client_id'        => $clientId,
        ]);
    }

    /**
     * Marca a DropStore como desinstalada quando o app e removido da loja.
     */
    public function handleAppUninstalled(array $payload, int $clientId): void
    {
        $shopDomain = $payload['domain'] ?? $payload['myshopify_domain'] ?? null;

        if (!$shopDomain) {
            Log::warning('[ShopifyWebhookHandler] handleAppUninstalled: shop_domain ausente no payload', [
                'client_id' => $clientId,
            ]);
            return;
        }

        $store = DropStore::where('shop_domain', $shopDomain)
            ->where('client_id', $clientId)
            ->first();

        if (!$store) {
            Log::warning('[ShopifyWebhookHandler] handleAppUninstalled: DropStore nao encontrada', [
                'shop_domain' => $shopDomain,
                'client_id'   => $clientId,
            ]);
            return;
        }

        $store->update([
            'status'                => 'uninstalled',
            'webhook_registered_at' => null,
        ]);

        Log::info('[ShopifyWebhookHandler] App desinstalado - loja marcada como uninstalled', [
            'store_id'    => $store->id,
            'shop_domain' => $shopDomain,
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers privados
    // -------------------------------------------------------------------------

    /**
     * Extrai dados de rastreamento de origem (UTM, fbp, gclid) do note_attributes.
     * Retorna array associativo com as chaves encontradas.
     */
    private function extractTrafficSource(array $payload): array
    {
        $noteAttributes = $payload['note_attributes'] ?? [];
        $trackedKeys    = ['utm_source', 'utm_campaign', 'utm_medium', 'utm_term', 'utm_content', 'fbp', 'gclid'];
        $result         = [];

        foreach ($noteAttributes as $attr) {
            $key   = $attr['name'] ?? '';
            $value = $attr['value'] ?? '';
            if (in_array($key, $trackedKeys, true) && $value !== '') {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
