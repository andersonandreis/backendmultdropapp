<?php

namespace App\Services\Drop\Suppliers;

use App\Services\Drop\Suppliers\Contracts\SupplierConnectorInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Drop Internacional — Conector CJ Dropshipping.
 *
 * Integra com a API publica CJ Dropshipping v2.0:
 * https://developers.cjdropshipping.com/api2.0/v1/
 *
 * Credenciais via .env: CJ_API_KEY
 * Mapeadas em config/services.php como services.cj.api_key
 */
class CJDropshippingConnector implements SupplierConnectorInterface
{
    private const BASE_URL    = 'https://developers.cjdropshipping.com/api2.0/v1';
    private const TOKEN_TTL   = 23 * 60 * 60; // 23 horas (token CJ expira em 24h)
    private const TOKEN_CACHE = 'cj_access_token';

    public function getSlug(): string
    {
        return 'cj';
    }

    /**
     * Autenticar com a API CJ e retornar o accessToken.
     * O token e cacheado por 23h para evitar rate-limit no endpoint de auth.
     *
     * @throws \RuntimeException se as credenciais forem invalidas
     */
    public function authenticate(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE);
        if ($cached) {
            return $cached;
        }

        $response = Http::timeout(15)->post(self::BASE_URL . '/authentication/getAccessToken', [
            'apiKey' => config('services.cj.api_key'),
        ]);

        $data = $response->json();

        if (! $response->successful() || ($data['code'] ?? null) !== 200) {
            Log::error('CJDropshippingConnector: falha na autenticacao', [
                'status' => $response->status(),
                'body'   => $data,
            ]);
            throw new \RuntimeException(
                'CJ Dropshipping: falha na autenticacao — verifique CJ_API_KEY no .env'
            );
        }

        $token = $data['data']['accessToken'] ?? null;
        if (! $token) {
            throw new \RuntimeException('CJ Dropshipping: accessToken ausente na resposta de auth');
        }

        Cache::put(self::TOKEN_CACHE, $token, self::TOKEN_TTL);

        return $token;
    }

    /**
     * Pesquisar produtos na API CJ.
     *
     * @param  array  $filters  Aceita: query, category, page, per_page
     */
    public function searchProducts(array $filters): array
    {
        try {
            $token = $this->authenticate();

            $params = [
                'pageNum'  => $filters['page'] ?? 1,
                'pageSize' => $filters['per_page'] ?? 20,
            ];

            if (! empty($filters['query'])) {
                $params['productName'] = $filters['query'];
            }
            if (! empty($filters['category'])) {
                $params['categoryId'] = $filters['category'];
            }

            $response = Http::timeout(20)
                ->withHeaders(['CJ-Access-Token' => $token])
                ->get(self::BASE_URL . '/product/list', $params);

            $data = $response->json();

            if (! $response->successful() || ($data['code'] ?? null) !== 200) {
                Log::warning('CJDropshippingConnector: searchProducts falhou', [
                    'params' => $params,
                    'code'   => $data['code'] ?? $response->status(),
                    'msg'    => $data['message'] ?? '',
                ]);
                return [];
            }

            $products = $data['data']['list'] ?? [];

            return array_map(fn ($p) => $this->normalizeListItem($p), $products);
        } catch (\Throwable $e) {
            Log::error('CJDropshippingConnector: erro em searchProducts', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Buscar produto completo pelo ID externo (PID do CJ).
     */
    public function getProduct(string $externalId): array
    {
        try {
            $token = $this->authenticate();

            $response = Http::timeout(20)
                ->withHeaders(['CJ-Access-Token' => $token])
                ->get(self::BASE_URL . '/product/query', ['pid' => $externalId]);

            $data = $response->json();

            if (! $response->successful() || ($data['code'] ?? null) !== 200) {
                Log::warning('CJDropshippingConnector: getProduct falhou', [
                    'pid'  => $externalId,
                    'code' => $data['code'] ?? $response->status(),
                    'msg'  => $data['message'] ?? '',
                ]);
                return [];
            }

            return $this->normalizeFullProduct($data['data'] ?? []);
        } catch (\Throwable $e) {
            Log::error('CJDropshippingConnector: erro em getProduct', [
                'pid'   => $externalId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Criar pedido de compra no CJ Dropshipping.
     *
     * @param  array  $orderData  {orderNumber, products[{vid,quantity}], address{...}}
     */
    public function createOrder(array $orderData): array
    {
        try {
            $token = $this->authenticate();

            $response = Http::timeout(30)
                ->withHeaders(['CJ-Access-Token' => $token])
                ->post(self::BASE_URL . '/shopping/order/createOrder', $orderData);

            $data = $response->json();

            if (! $response->successful() || ($data['code'] ?? null) !== 200) {
                Log::error('CJDropshippingConnector: createOrder falhou', [
                    'orderNumber' => $orderData['orderNumber'] ?? null,
                    'code'        => $data['code'] ?? $response->status(),
                    'msg'         => $data['message'] ?? '',
                ]);
                return [];
            }

            return $data['data'] ?? [];
        } catch (\Throwable $e) {
            Log::error('CJDropshippingConnector: erro em createOrder', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Consultar status de um pedido no CJ.
     */
    public function getOrderStatus(string $externalOrderId): array
    {
        try {
            $token = $this->authenticate();

            $response = Http::timeout(15)
                ->withHeaders(['CJ-Access-Token' => $token])
                ->get(self::BASE_URL . '/shopping/order/getOrderDetail', [
                    'orderId' => $externalOrderId,
                ]);

            $data = $response->json();

            if (! $response->successful() || ($data['code'] ?? null) !== 200) {
                Log::warning('CJDropshippingConnector: getOrderStatus falhou', [
                    'orderId' => $externalOrderId,
                    'code'    => $data['code'] ?? $response->status(),
                    'msg'     => $data['message'] ?? '',
                ]);
                return [];
            }

            return $data['data'] ?? [];
        } catch (\Throwable $e) {
            Log::error('CJDropshippingConnector: erro em getOrderStatus', [
                'orderId' => $externalOrderId,
                'error'   => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Consultar informacoes de rastreio de um pedido.
     *
     * @return array  {trackingNumber, carrier, status, events[]}
     */
    public function getTracking(string $externalOrderId): array
    {
        try {
            $token = $this->authenticate();

            $response = Http::timeout(15)
                ->withHeaders(['CJ-Access-Token' => $token])
                ->get(self::BASE_URL . '/logistic/trackingInfo', [
                    'orderId' => $externalOrderId,
                ]);

            $data = $response->json();

            if (! $response->successful() || ($data['code'] ?? null) !== 200) {
                Log::warning('CJDropshippingConnector: getTracking falhou', [
                    'orderId' => $externalOrderId,
                    'code'    => $data['code'] ?? $response->status(),
                    'msg'     => $data['message'] ?? '',
                ]);
                return [];
            }

            $raw = $data['data'] ?? [];

            return [
                'trackingNumber' => $raw['trackingNumber'] ?? $raw['tracking_number'] ?? null,
                'carrier'        => $raw['logisticName'] ?? $raw['carrier'] ?? null,
                'status'         => $raw['trackStatus'] ?? $raw['status'] ?? null,
                'events'         => $raw['trackingList'] ?? $raw['events'] ?? [],
            ];
        } catch (\Throwable $e) {
            Log::error('CJDropshippingConnector: erro em getTracking', [
                'orderId' => $externalOrderId,
                'error'   => $e->getMessage(),
            ]);
            return [];
        }
    }

    // -------------------------------------------------------------------------
    // Helpers de normalizacao
    // -------------------------------------------------------------------------

    private function normalizeListItem(array $p): array
    {
        $costUsd = (float) ($p['sellPrice'] ?? $p['productPrice'] ?? 0);

        return [
            'external_id'  => (string) ($p['pid'] ?? ''),
            'title'        => $p['productName'] ?? $p['productNameEn'] ?? '',
            'cost_usd'     => $costUsd,
            'shipping_usd' => (float) ($p['shippingPrice'] ?? 0),
            'rating'       => isset($p['productEvaluation']) ? (float) $p['productEvaluation'] : null,
            'sales_count'  => isset($p['productSales']) ? (int) $p['productSales'] : null,
            'images'       => array_values(array_filter([$p['productImage'] ?? $p['thumbnail'] ?? null])),
            'supplier_url' => isset($p['pid'])
                ? 'https://app.cjdropshipping.com/product-detail.html?id=' . $p['pid']
                : null,
            'category'   => $p['categoryName'] ?? $p['category'] ?? null,
            'risk_score' => 0,
        ];
    }

    private function normalizeFullProduct(array $p): array
    {
        if (empty($p)) {
            return [];
        }

        $costUsd = (float) ($p['sellPrice'] ?? $p['productPrice'] ?? 0);

        $images = [];
        if (! empty($p['productImage'])) {
            $images[] = $p['productImage'];
        }
        if (! empty($p['productImageSet'])) {
            foreach ((array) $p['productImageSet'] as $img) {
                $url = is_array($img) ? ($img['imageUrl'] ?? null) : $img;
                if ($url) {
                    $images[] = $url;
                }
            }
        }
        $images = array_values(array_unique(array_filter($images)));

        $variants = [];
        if (! empty($p['variants'])) {
            foreach ((array) $p['variants'] as $v) {
                $variants[] = [
                    'vid'      => $v['vid'] ?? null,
                    'sku'      => $v['variantSku'] ?? null,
                    'title'    => $v['variantNameEn'] ?? $v['variantName'] ?? null,
                    'cost_usd' => (float) ($v['variantSellPrice'] ?? $costUsd),
                    'stock'    => (int) ($v['variantStock'] ?? 0),
                    'image'    => $v['variantImage'] ?? null,
                ];
            }
        }

        return [
            'external_id'    => (string) ($p['pid'] ?? ''),
            'title'          => $p['productNameEn'] ?? $p['productName'] ?? '',
            'description'    => $p['productDescEn'] ?? $p['productDesc'] ?? null,
            'cost_usd'       => $costUsd,
            'shipping_usd'   => (float) ($p['shippingPrice'] ?? 0),
            'estimated_days' => isset($p['deliveryTime']) ? (int) $p['deliveryTime'] : null,
            'rating'         => isset($p['productEvaluation']) ? (float) $p['productEvaluation'] : null,
            'sales_count'    => isset($p['productSales']) ? (int) $p['productSales'] : null,
            'images'         => $images,
            'variants'       => $variants,
            'supplier_url'   => 'https://app.cjdropshipping.com/product-detail.html?id=' . ($p['pid'] ?? ''),
            'category'       => $p['categoryName'] ?? $p['category'] ?? null,
            'risk_score'     => 0,
        ];
    }
}
