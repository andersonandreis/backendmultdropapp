<?php

namespace App\Services\Drop\Suppliers;

use App\Services\Drop\Suppliers\Contracts\SupplierConnectorInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Drop Internacional — Conector AliExpress Open Platform.
 *
 * API oficial AliExpress DS (Dropshipping) v2.0:
 * https://developers.aliexpress.com/en/doc.htm
 *
 * Credenciais via .env:
 *   ALIEXPRESS_APP_KEY       → App Key do portal developers.aliexpress.com
 *   ALIEXPRESS_APP_SECRET    → App Secret
 *   ALIEXPRESS_ACCESS_TOKEN  → OAuth token da conta AliExpress do operador
 *   ALIEXPRESS_REDIRECT_URI  → Callback OAuth
 */
class AliExpressConnector implements SupplierConnectorInterface
{
    private const BASE_URL    = 'https://api-sg.aliexpress.com/sync';
    private const SIGN_METHOD = 'md5';
    private const API_VERSION = '2.0';

    private string $appKey;
    private string $appSecret;
    private ?string $accessToken;

    public function __construct()
    {
        $this->appKey      = config('services.aliexpress.app_key', '');
        $this->appSecret   = config('services.aliexpress.app_secret', '');
        $this->accessToken = config('services.aliexpress.access_token');
    }

    public function getSlug(): string
    {
        return 'aliexpress';
    }

    // -------------------------------------------------------------------------
    // Busca de produtos
    // -------------------------------------------------------------------------

    public function searchProducts(array $filters): array
    {
        try {
            $params = [
                'method'          => 'aliexpress.ds.recommend.feed.get',
                'keywords'        => $filters['query'] ?? '',
                'page_no'         => $filters['page'] ?? 1,
                'page_size'       => min($filters['per_page'] ?? 20, 50),
                'sort'            => $filters['sort'] ?? 'SALE_PRICE_ASC',
                'target_currency' => 'BRL',
                'target_language' => 'PT',
                'country'         => 'BR',
                'delivery_days'   => $filters['delivery_days'] ?? null,
            ];

            if (!empty($filters['category_id'])) {
                $params['category_id'] = $filters['category_id'];
            }
            if (!empty($filters['min_price'])) {
                $params['min_sale_price'] = (int)($filters['min_price'] * 100);
            }
            if (!empty($filters['max_price'])) {
                $params['max_sale_price'] = (int)($filters['max_price'] * 100);
            }

            $params = array_filter($params, fn($v) => $v !== null && $v !== '');

            $response = $this->call($params);
            $result   = $response['aliexpress_ds_recommend_feed_get_response'] ?? [];
            $products = $result['result']['products']['traffic_product_d_t_o'] ?? [];

            return array_map(fn($p) => $this->normalizeListItem($p), $products);

        } catch (\Throwable $e) {
            Log::error('AliExpressConnector: erro em searchProducts', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function getProduct(string $externalId): array
    {
        try {
            $response = $this->call([
                'method'          => 'aliexpress.ds.product.get',
                'product_id'      => $externalId,
                'target_currency' => 'BRL',
                'target_language' => 'PT',
                'country'         => 'BR',
                'ship_to_country' => 'BR',
            ]);

            $data = $response['aliexpress_ds_product_get_response']['result'] ?? [];
            return empty($data) ? [] : $this->normalizeFullProduct($data);

        } catch (\Throwable $e) {
            Log::error('AliExpressConnector: erro em getProduct', [
                'product_id' => $externalId,
                'error'      => $e->getMessage(),
            ]);
            return [];
        }
    }

    // -------------------------------------------------------------------------
    // Pedidos
    // -------------------------------------------------------------------------

    public function createOrder(array $orderData): array
    {
        try {
            if (empty($this->accessToken)) {
                throw new \RuntimeException(
                    'AliExpress: ALIEXPRESS_ACCESS_TOKEN nao configurado. Autorize o app primeiro em /oauth/aliexpress.'
                );
            }

            $orderRequest = [
                'product_items' => [[
                    'product_id'             => $orderData['product_id'],
                    'product_count'          => $orderData['quantity'] ?? 1,
                    'sku_attr'               => $orderData['sku_attr'] ?? '',
                    'logistics_service_name' => $orderData['logistics'] ?? 'CAINIAO_STANDARD',
                    'order_memo'             => 'HubAI Drop #' . ($orderData['internal_order_id'] ?? ''),
                ]],
                'logistics_address' => [
                    'country'        => 'BR',
                    'province'       => $orderData['province'] ?? '',
                    'city'           => $orderData['city'] ?? '',
                    'address'        => $orderData['address'] ?? '',
                    'address2'       => $orderData['address2'] ?? '',
                    'zip'            => preg_replace('/\D/', '', $orderData['zip'] ?? ''),
                    'full_name'      => $orderData['contact_person'] ?? '',
                    'phone_country'  => '55',
                    'mobile_no'      => preg_replace('/\D/', '', $orderData['phone'] ?? ''),
                    'cpf'            => preg_replace('/\D/', '', $orderData['cpf'] ?? ''),
                ],
            ];

            $response = $this->call([
                'method' => 'aliexpress.ds.order.create',
                'param_place_order_request4_open_api_d_t_o' => json_encode($orderRequest),
            ], withSession: true);

            $result = $response['aliexpress_ds_order_create_response']['result'] ?? [];

            if (!empty($result['error_msg'])) {
                Log::warning('AliExpressConnector: createOrder erro', ['result' => $result]);
                return ['success' => false, 'error' => $result['error_msg']];
            }

            $orderItem = $result['order_list']['order_list_item'][0] ?? [];

            return [
                'success'           => true,
                'external_order_id' => (string) ($orderItem['order_id'] ?? ''),
                'status'            => 'pending',
                'estimated_cost'    => (float) ($result['total_amount'] ?? 0),
                'raw'               => $result,
            ];

        } catch (\Throwable $e) {
            Log::error('AliExpressConnector: erro em createOrder', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function getOrderStatus(string $externalOrderId): array
    {
        try {
            $response = $this->call([
                'method'   => 'aliexpress.ds.order.get',
                'order_id' => $externalOrderId,
            ], withSession: true);

            $order = $response['aliexpress_ds_order_get_response']['result'] ?? [];

            return [
                'external_order_id' => $externalOrderId,
                'status'            => strtolower($order['order_status'] ?? 'unknown'),
                'paid_at'           => $order['pay_time'] ?? null,
                'raw'               => $order,
            ];

        } catch (\Throwable $e) {
            Log::error('AliExpressConnector: erro em getOrderStatus', ['error' => $e->getMessage()]);
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    public function getTracking(string $externalOrderId): array
    {
        try {
            $response = $this->call([
                'method'       => 'aliexpress.ds.tracking.info.query',
                'order_id'     => $externalOrderId,
                'carrier_code' => '',
            ], withSession: true);

            $data = $response['aliexpress_ds_tracking_info_query_response']['result'] ?? [];

            return [
                'tracking_code'      => $data['tracking_number'] ?? null,
                'carrier'            => $data['carrier_code'] ?? null,
                'status'             => $data['shipping_status'] ?? null,
                'events'             => $data['events']['tracking_event_d_t_o'] ?? [],
                'estimated_delivery' => null,
                'raw'                => $data,
            ];

        } catch (\Throwable $e) {
            Log::error('AliExpressConnector: erro em getTracking', ['error' => $e->getMessage()]);
            return ['tracking_code' => null, 'error' => $e->getMessage()];
        }
    }

    // -------------------------------------------------------------------------
    // Categorias
    // -------------------------------------------------------------------------

    public function getCategories(): array
    {
        return Cache::remember('aliexpress_categories', 86400, function () {
            try {
                $response = $this->call(['method' => 'aliexpress.ds.category.get']);
                $cats = $response['aliexpress_ds_category_get_response']['result']
                    ['child_category_list']['aliexpress_category_info_d_t_o'] ?? [];

                return array_map(fn($c) => [
                    'id'   => $c['category_id'],
                    'name' => $c['multi_language_names']['multi_language_name'][0]['name']
                              ?? (string) $c['category_id'],
                ], $cats);
            } catch (\Throwable $e) {
                return [];
            }
        });
    }

    // -------------------------------------------------------------------------
    // OAuth
    // -------------------------------------------------------------------------

    public function getOAuthUrl(string $state = ''): string
    {
        return 'https://api-sg.aliexpress.com/oauth/authorize?' . http_build_query([
            'client_id'    => $this->appKey,
            'redirect_uri' => config('services.aliexpress.redirect_uri'),
            'sp'           => 'ae',
            'state'        => $state,
            'view'         => 'web',
        ]);
    }

    public function exchangeOAuthCode(string $code): array
    {
        $response = $this->call([
            'method'      => 'aliexpress.system.oauth.token',
            'code'        => $code,
            'grant_type'  => 'authorization_code',
        ]);

        if (isset($response['error_response'])) {
            throw new \RuntimeException(
                'AliExpress OAuth: ' . ($response['error_response']['msg'] ?? 'erro desconhecido')
            );
        }

        return $response;
    }

    public function refreshOAuthToken(string $refreshToken): array
    {
        $response = $this->call([
            'method'        => 'aliexpress.system.oauth.token',
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
        ]);

        if (isset($response['error_response'])) {
            throw new \RuntimeException(
                'AliExpress OAuth refresh: ' . ($response['error_response']['msg'] ?? 'erro')
            );
        }

        return $response;
    }

    // -------------------------------------------------------------------------
    // Assinatura HMAC-MD5 (padrão AliExpress Open Platform)
    // -------------------------------------------------------------------------

    private function call(array $params, bool $withSession = false): array
    {
        $baseParams = [
            'app_key'     => $this->appKey,
            'sign_method' => self::SIGN_METHOD,
            'timestamp'   => date('Y-m-d H:i:s'),
            'v'           => self::API_VERSION,
        ];

        if ($withSession && $this->accessToken) {
            $baseParams['session'] = $this->accessToken;
        }

        $allParams         = array_merge($baseParams, $params);
        $allParams['sign'] = $this->sign($allParams);

        $response = Http::timeout(20)
            ->asForm()
            ->post(self::BASE_URL, $allParams);

        if (!$response->successful()) {
            throw new \RuntimeException(
                "AliExpress HTTP {$response->status()}: " . $response->body()
            );
        }

        return $response->json() ?? [];
    }

    private function sign(array $params): string
    {
        unset($params['sign']);
        ksort($params);

        $str = $this->appSecret;
        foreach ($params as $key => $value) {
            if ($value !== '' && $value !== null) {
                $str .= $key . $value;
            }
        }
        $str .= $this->appSecret;

        return strtoupper(md5($str));
    }

    // -------------------------------------------------------------------------
    // Normalização
    // -------------------------------------------------------------------------

    private function normalizeListItem(array $p): array
    {
        $price         = (float) ($p['target_sale_price'] ?? $p['sale_price'] ?? 0);
        $originalPrice = (float) ($p['target_original_price'] ?? $p['original_price'] ?? $price);

        return [
            'external_id'    => (string) ($p['product_id'] ?? ''),
            'title'          => $p['product_title'] ?? '',
            'cost_usd'       => $price,
            'original_price' => $originalPrice,
            'discount_pct'   => $originalPrice > 0 ? round((1 - $price / $originalPrice) * 100) : 0,
            'shipping_usd'   => 0,
            'rating'         => isset($p['evaluate_rate'])
                ? round((float) rtrim($p['evaluate_rate'], '%') / 20, 1)
                : null,
            'sales_count'    => isset($p['lastest_volume']) ? (int) $p['lastest_volume'] : null,
            'images'         => array_values(array_filter([
                $p['product_main_image_url']
                ?? $p['product_small_image_urls']['string'][0]
                ?? null,
            ])),
            'supplier_url'   => $p['product_detail_url'] ?? null,
            'category'       => $p['second_level_category_name'] ?? $p['first_level_category_name'] ?? null,
            'source'         => 'aliexpress',
        ];
    }

    private function normalizeFullProduct(array $p): array
    {
        if (empty($p)) {
            return [];
        }

        $info  = $p['product_info_dto'] ?? $p;
        $price = (float) ($info['target_sale_price'] ?? $info['sale_price'] ?? 0);

        $images = [];
        foreach ($p['image_info_dto']['image_urls']['image_url'] ?? [] as $img) {
            $images[] = ($img['domain'] ?? '') . ($img['thumbnail'] ?? '');
        }
        if (empty($images) && !empty($info['product_main_image_url'])) {
            $images[] = $info['product_main_image_url'];
        }

        $variants = [];
        foreach ($p['sku_info_dto']['sku_list']['sku_d_t_o'] ?? [] as $sku) {
            $variants[] = [
                'sku_id'     => (string) ($sku['sku_id'] ?? ''),
                'sku_attr'   => $sku['sku_attr'] ?? '',
                'price_usd'  => (float) ($sku['sku_price'] ?? $price),
                'stock'      => (int) ($sku['ipm_sku_stock'] ?? 0),
                'images'     => array_values(array_filter([$sku['sku_image_url'] ?? null])),
                'attributes' => $this->parseSkuAttr($sku['sku_attr'] ?? ''),
            ];
        }

        return [
            'external_id'  => (string) ($info['product_id'] ?? $p['product_id'] ?? ''),
            'title'        => $info['subject'] ?? $info['product_title'] ?? '',
            'description'  => $p['description'] ?? '',
            'cost_usd'     => $price,
            'shipping_usd' => 0,
            'images'       => $images,
            'variants'     => $variants,
            'rating'       => isset($info['evaluate_rate'])
                ? round((float) rtrim($info['evaluate_rate'], '%') / 20, 1)
                : null,
            'sales_count'  => isset($info['lastest_volume']) ? (int) $info['lastest_volume'] : null,
            'supplier_url' => $info['product_detail_url'] ?? null,
            'category'     => $p['category_info_dto']['leaf_category_name'] ?? null,
            'weight_kg'    => isset($p['weight']) ? (float) $p['weight'] : null,
            'source'       => 'aliexpress',
        ];
    }

    private function parseSkuAttr(string $skuAttr): array
    {
        $result = [];
        foreach (explode(';', $skuAttr) as $part) {
            if (str_contains($part, '#')) {
                [$idPart, $value] = explode('#', $part, 2);
                $result[] = ['raw' => $idPart, 'value' => trim($value)];
            }
        }
        return $result;
    }
}
