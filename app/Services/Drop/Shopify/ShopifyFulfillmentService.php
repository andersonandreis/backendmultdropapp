<?php

namespace App\Services\Drop\Shopify;

use App\Models\Drop\DropOrder;
use App\Models\Drop\DropSupplierOrder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Drop Internacional - Fase 2
 * Servico para criar fulfillments no Shopify quando rastreio e recebido.
 *
 * Chamado por:
 *  - TrackingPollingJob (polling a cada 6h)
 *  - DropOrderService::registerTracking
 */
class ShopifyFulfillmentService
{
    /**
     * Criar fulfillment no Shopify para um DropOrder com rastreio disponivel.
     *
     * @param  DropOrder         $dropOrder      Pedido que recebeu rastreio
     * @param  DropSupplierOrder $supplierOrder  Ordem do fornecedor com tracking_code
     * @return bool  true se o fulfillment foi criado com sucesso
     */
    public function createFulfillment(DropOrder $dropOrder, DropSupplierOrder $supplierOrder): bool
    {
        $trackingCode = $supplierOrder->tracking_code;
        $carrier      = $supplierOrder->tracking_carrier ?? '';

        if (!$trackingCode) {
            Log::warning('ShopifyFulfillmentService: tracking_code ausente', [
                'drop_order_id'          => $dropOrder->id,
                'drop_supplier_order_id' => $supplierOrder->id,
            ]);
            return false;
        }

        // So aplicavel a pedidos oriundos do Shopify
        if (empty($dropOrder->shopify_order_id)) {
            Log::info('ShopifyFulfillmentService: pedido nao e de origem Shopify, ignorando', [
                'drop_order_id' => $dropOrder->id,
            ]);
            return false;
        }

        $store = $dropOrder->dropStore;

        if (!$store || !$store->isShopify()) {
            Log::warning('ShopifyFulfillmentService: DropStore nao e Shopify ou nao encontrada', [
                'drop_order_id' => $dropOrder->id,
            ]);
            return false;
        }

        $accessToken = $store->access_token;
        $shopDomain  = $store->shop_domain;

        if (!$accessToken || !$shopDomain) {
            Log::warning('ShopifyFulfillmentService: access_token ou shop_domain ausente', [
                'drop_order_id' => $dropOrder->id,
                'shop_domain'   => $shopDomain,
            ]);
            return false;
        }

        try {
            // Passo 1: Obter location_id da loja (necessario para criar fulfillment)
            $locationId = $this->getPrimaryLocationId($shopDomain, $accessToken);

            if (!$locationId) {
                Log::error('ShopifyFulfillmentService: nao foi possivel obter location_id', [
                    'drop_order_id' => $dropOrder->id,
                    'shop_domain'   => $shopDomain,
                ]);
                return false;
            }

            // Passo 2: Criar o fulfillment via API Shopify
            $payload = [
                'fulfillment' => [
                    'location_id'  => $locationId,
                    'tracking_info' => [
                        'number'  => $trackingCode,
                        'company' => $this->normalizeCarrierName($carrier),
                        'url'     => $this->buildTrackingUrl($trackingCode, $carrier),
                    ],
                    'notify_customer' => true,
                    'line_items_by_fulfillment_order' => [],
                ],
            ];

            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
                'Content-Type'           => 'application/json',
            ])->timeout(20)->post(
                "https://{$shopDomain}/admin/api/2024-01/orders/{$dropOrder->shopify_order_id}/fulfillments.json",
                $payload
            );

            if ($response->successful()) {
                Log::info('ShopifyFulfillmentService: fulfillment criado com sucesso', [
                    'drop_order_id'    => $dropOrder->id,
                    'shopify_order_id' => $dropOrder->shopify_order_id,
                    'tracking_code'    => $trackingCode,
                    'fulfillment_id'   => $response->json('fulfillment.id'),
                ]);
                return true;
            }

            // Ignorar erro 422 se fulfillment ja existe (idempotencia)
            if ($response->status() === 422) {
                $errors = $response->json('errors', []);
                $errStr = is_array($errors) ? json_encode($errors) : (string) $errors;

                if (str_contains(strtolower($errStr), 'already') || str_contains(strtolower($errStr), 'fulfilled')) {
                    Log::info('ShopifyFulfillmentService: pedido ja estava fulfillido, ignorando', [
                        'drop_order_id'    => $dropOrder->id,
                        'shopify_order_id' => $dropOrder->shopify_order_id,
                    ]);
                    return true;
                }
            }

            Log::error('ShopifyFulfillmentService: falha ao criar fulfillment', [
                'drop_order_id'    => $dropOrder->id,
                'shopify_order_id' => $dropOrder->shopify_order_id,
                'status'           => $response->status(),
                'body'             => $response->body(),
            ]);

            return false;

        } catch (\Throwable $e) {
            Log::error('ShopifyFulfillmentService: excecao ao criar fulfillment', [
                'drop_order_id' => $dropOrder->id,
                'error'         => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Buscar o ID do local principal da loja Shopify.
     * Necessario para a API de fulfillments.
     */
    private function getPrimaryLocationId(string $shopDomain, string $accessToken): ?int
    {
        try {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
            ])->timeout(10)->get(
                "https://{$shopDomain}/admin/api/2024-01/locations.json?limit=1"
            );

            if ($response->successful()) {
                $locations = $response->json('locations', []);
                return !empty($locations) ? (int) $locations[0]['id'] : null;
            }
        } catch (\Throwable $e) {
            Log::warning('ShopifyFulfillmentService: erro ao buscar location_id', [
                'shop_domain' => $shopDomain,
                'error'       => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Normalizar nome da transportadora para formato reconhecido pelo Shopify.
     */
    private function normalizeCarrierName(string $carrier): string
    {
        $map = [
            'cainiao'   => 'Cainiao',
            'correios'  => 'Correios',
            'latam'     => 'LATAM Cargo',
            'fedex'     => 'FedEx',
            'dhl'       => 'DHL Express',
            'ups'       => 'UPS',
            'usps'      => 'USPS',
        ];

        $lower = strtolower($carrier);
        foreach ($map as $key => $name) {
            if (str_contains($lower, $key)) {
                return $name;
            }
        }

        return $carrier ?: 'Other';
    }

    /**
     * Construir URL de rastreio com base na transportadora.
     */
    private function buildTrackingUrl(string $trackingCode, string $carrier): string
    {
        $lower = strtolower($carrier);

        if (str_contains($lower, 'correios') || str_contains($lower, 'cainiao')) {
            return 'https://rastreamento.correios.com.br/app/index.php?objeto=' . $trackingCode;
        }

        if (str_contains($lower, 'dhl')) {
            return 'https://www.dhl.com/global-en/home/tracking.html?tracking-id=' . $trackingCode;
        }

        return 'https://track.aliexpress.com/logisticsdetail.htm?tradeId=' . $trackingCode;
    }
}
