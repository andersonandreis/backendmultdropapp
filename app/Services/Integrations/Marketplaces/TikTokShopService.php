<?php

namespace App\Services\Integrations\Marketplaces;

use App\Models\MarketplaceAccount;
use App\Models\Product;
use App\Models\Order;
use App\Services\Integrations\Contracts\MarketplaceInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TikTokShopService implements MarketplaceInterface
{
    private string $baseUrl = 'https://open-api.tiktokglobalshop.com';
    private string $appKey;
    private string $appSecret;
    private string $redirectUri;

    public function __construct()
    {
        $this->appKey = config('services.tiktok_shop.app_key', env('TIKTOK_SHOP_APP_KEY', ''));
        $this->appSecret = config('services.tiktok_shop.app_secret', env('TIKTOK_SHOP_APP_SECRET', ''));
        $this->redirectUri = config('services.tiktok_shop.redirect_uri', env('TIKTOK_SHOP_REDIRECT_URI', ''));
    }

    // ──────────────────────────── Signature ────────────────────────────

    /**
     * Build HMAC-SHA256 signature for TikTok Shop API requests.
     * Spec: sign = HMAC-SHA256(app_secret, path + sorted_params_string + timestamp)
     */
    protected function buildSignature(string $path, array $params, int $timestamp): string
    {
        // Sort params alphabetically by key
        ksort($params);

        $paramString = '';
        foreach ($params as $key => $value) {
            if ($key === 'sign' || $key === 'access_token') {
                continue;
            }
            $paramString .= $key . (is_array($value) ? json_encode($value) : $value);
        }

        $baseString = $this->appSecret . $path . $paramString . $this->appSecret;

        return hash_hmac('sha256', $baseString, $this->appSecret);
    }

    /**
     * Build full query params with app_key, timestamp, sign, and access_token.
     */
    protected function buildQueryParams(string $path, array $extraParams = [], ?string $accessToken = null): array
    {
        $timestamp = time();

        $params = array_merge([
            'app_key' => $this->appKey,
            'timestamp' => $timestamp,
        ], $extraParams);

        $sign = $this->buildSignature($path, $params, $timestamp);

        $params['sign'] = $sign;

        if ($accessToken) {
            $params['access_token'] = $accessToken;
        }

        return $params;
    }

    // ──────────────────────────── Auth ────────────────────────────

    public function authenticate(MarketplaceAccount $account): string|array
    {
        $state = $account->id;
        $authUrl = "https://auth.tiktok-shops.com/oauth/authorize"
            . "?app_key={$this->appKey}"
            . "&state={$state}"
            . "&redirect_uri=" . urlencode($this->redirectUri);

        return [
            'status' => 'redirect',
            'url' => $authUrl,
        ];
    }

    public function refreshToken(MarketplaceAccount $account): ?string
    {
        $refreshToken = $account->refresh_token;

        if (!$refreshToken) {
            Log::warning('[TikTokShopService] refreshToken: refresh_token ausente', ['account_id' => $account->id]);
            return null;
        }

        $path = '/api/token/refresh';
        $params = [
            'app_key' => $this->appKey,
            'app_secret' => $this->appSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ];

        try {
            $response = Http::timeout(30)->get("{$this->baseUrl}{$path}", $params);

            if ($response->failed()) {
                Log::error('[TikTokShopService] refreshToken: HTTP error', [
                    'account_id' => $account->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json('data');

            if (empty($data['access_token'])) {
                Log::error('[TikTokShopService] refreshToken: resposta sem access_token', [
                    'account_id' => $account->id,
                    'response' => $response->json(),
                ]);
                return null;
            }

            $account->update([
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'],
                'token_expires_at' => now()->addSeconds($data['access_token_expire_in'] ?? 3600),
                'refresh_token_expires_at' => now()->addSeconds($data['refresh_token_expire_in'] ?? 86400 * 365),
                'last_token_refresh_at' => now(),
            ]);

            Log::info('[TikTokShopService] Token renovado', ['account_id' => $account->id]);

            return $data['access_token'];
        } catch (\Exception $e) {
            Log::error('[TikTokShopService] refreshToken: excecao', [
                'account_id' => $account->id,
                'message' => $e->getMessage(),
            ]);
            return null;
        }
    }

    protected function getValidAccessToken(MarketplaceAccount $account): ?string
    {
        $token = $account->access_token;

        if (!$token) {
            return null;
        }

        if ($account->token_expires_at && now()->gte($account->token_expires_at)) {
            return $this->refreshToken($account);
        }

        return $token;
    }

    // ──────────────────────────── API Caller ────────────────────────────

    protected function callApi(string $path, array $body = [], string $method = 'POST', ?string $accessToken = null, array $extraQuery = []): array
    {
        $queryParams = $this->buildQueryParams($path, $extraQuery, $accessToken);
        $url = $this->baseUrl . $path . '?' . http_build_query($queryParams);

        try {
            $http = Http::timeout(30)->withHeaders([
                'Content-Type' => 'application/json',
                'x-tts-access-token' => $accessToken ?? '',
            ]);

            if (strtoupper($method) === 'GET') {
                $response = $http->get($url, $body);
            } elseif (strtoupper($method) === 'PUT') {
                $response = $http->put($url, $body);
            } else {
                $response = $http->post($url, $body);
            }

            if ($response->failed()) {
                Log::error('[TikTokShopService] callApi: HTTP error', [
                    'path' => $path,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return ['error' => 'http_error', 'message' => $response->body()];
            }

            return $response->json() ?? [];
        } catch (\Exception $e) {
            Log::error('[TikTokShopService] callApi: excecao', [
                'path' => $path,
                'message' => $e->getMessage(),
            ]);
            return ['error' => 'exception', 'message' => $e->getMessage()];
        }
    }

    // ──────────────────────────── Products ────────────────────────────

    public function syncProduct(MarketplaceAccount $account, Product $product): bool|array
    {
        $accessToken = $this->getValidAccessToken($account);

        if (!$accessToken) {
            Log::warning('[TikTokShopService] syncProduct: access_token ausente', ['account_id' => $account->id]);
            return false;
        }

        // --- ESCUDO ANTI-BAN ---
        $forbiddenWords = \App\Models\ForbiddenWord::where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('context')->orWhere('context', 'tiktok');
            })->pluck('word')->toArray();

        $textToAnalyze = strtolower($product->name . ' ' . ($product->description ?? ''));
        foreach ($forbiddenWords as $word) {
            if (mb_stripos($textToAnalyze, strtolower($word)) !== false) {
                \App\Models\SyncLog::create([
                    'syncable_type' => Product::class,
                    'syncable_id' => $product->id,
                    'platform' => 'tiktok',
                    'action' => 'Sync Product',
                    'direction' => 'outbound',
                    'status' => 'failed',
                    'error_message' => "BLOQUEIO PREVENTIVO HUBAI: O produto contem a palavra proibida '{$word}'. Remova este termo para evitar banimento da sua loja no TikTok Shop.",
                    'request_payload' => json_encode(['title' => $product->name, 'description' => $product->description]),
                ]);
                return false;
            }
        }
        // --- FIM: ESCUDO ANTI-BAN ---

        $shopId = $account->shop_id;
        $path = '/product/202309/products';

        $payload = [
            'title' => $product->name,
            'description' => $product->description ?? '',
            'category_id' => $product->tiktok_category_id ?? '',
            'brand_id' => $product->tiktok_brand_id ?? null,
            'skus' => [
                [
                    'original_price' => (string) ($product->price ?? 0),
                    'seller_sku' => $product->sku ?? '',
                    'stock_infos' => [
                        [
                            'available_stock' => $product->stock ?? 0,
                            'warehouse_id' => $account->settings['tiktok_warehouse_id'] ?? '',
                        ],
                    ],
                ],
            ],
            'package_weight' => [
                'value' => (string) ($product->weight ?? 500),
                'unit' => 'GRAM',
            ],
        ];

        // If product already has a TikTok product ID, update; otherwise create
        $tiktokProductId = $product->marketplace_ids['tiktok'] ?? null;

        if ($tiktokProductId) {
            $response = $this->callApi(
                "/product/202309/products/{$tiktokProductId}",
                $payload,
                'PUT',
                $accessToken,
                ['shop_id' => $shopId]
            );
        } else {
            $response = $this->callApi(
                $path,
                $payload,
                'POST',
                $accessToken,
                ['shop_id' => $shopId]
            );
        }

        if (!empty($response['error']) || (!empty($response['code']) && $response['code'] !== 0)) {
            Log::error('[TikTokShopService] syncProduct: falha', [
                'product_id' => $product->id,
                'error' => $response['message'] ?? $response['error'] ?? 'unknown',
            ]);
            return false;
        }

        Log::info('[TikTokShopService] syncProduct: sucesso', [
            'product_id' => $product->id,
            'tiktok_product_id' => $response['data']['product_id'] ?? $tiktokProductId,
        ]);

        return true;
    }

    // ──────────────────────────── Orders ────────────────────────────

    public function fetchOrders(MarketplaceAccount $account, string $sinceDate = null): array
    {
        $accessToken = $this->getValidAccessToken($account);
        $shopId = $account->shop_id;

        if (!$accessToken || !$shopId) {
            Log::warning('[TikTokShopService] fetchOrders: access_token ou shop_id ausente', ['account_id' => $account->id]);
            return [];
        }

        $createTimeFrom = $sinceDate ? strtotime($sinceDate) : strtotime('-7 days');
        $createTimeTo = time();

        $payload = [
            'create_time_ge' => $createTimeFrom,
            'create_time_lt' => $createTimeTo,
            'page_size' => 50,
        ];

        $path = '/order/202309/orders/search';

        $allOrders = [];
        $nextPageToken = null;

        do {
            if ($nextPageToken) {
                $payload['page_token'] = $nextPageToken;
            }

            $response = $this->callApi($path, $payload, 'POST', $accessToken, ['shop_id' => $shopId]);

            if (!empty($response['error']) || (!empty($response['code']) && $response['code'] !== 0)) {
                Log::error('[TikTokShopService] fetchOrders: falha', [
                    'account_id' => $account->id,
                    'error' => $response['message'] ?? 'unknown',
                ]);
                break;
            }

            $orders = $response['data']['orders'] ?? [];
            $allOrders = array_merge($allOrders, $orders);

            $nextPageToken = $response['data']['next_page_token'] ?? null;
        } while ($nextPageToken);

        Log::info('[TikTokShopService] fetchOrders: pedidos obtidos', [
            'account_id' => $account->id,
            'count' => count($allOrders),
        ]);

        return $allOrders;
    }

    // ──────────────────────────── Inventory & Price ────────────────────────────

    public function syncInventoryAndPrice(MarketplaceAccount $account, string $sku, int $quantity, float $price): bool
    {
        $accessToken = $this->getValidAccessToken($account);
        $shopId = $account->shop_id;

        if (!$accessToken || !$shopId) {
            Log::warning('[TikTokShopService] syncInventoryAndPrice: access_token ou shop_id ausente', ['account_id' => $account->id]);
            return false;
        }

        $product = Product::where('sku', $sku)->first();
        $tiktokProductId = $product?->marketplace_ids['tiktok'] ?? null;

        if (!$tiktokProductId) {
            Log::warning('[TikTokShopService] syncInventoryAndPrice: produto sem tiktok_product_id', ['sku' => $sku]);
            return false;
        }

        // Update inventory
        $inventoryPayload = [
            'skus' => [
                [
                    'id' => $product->marketplace_ids['tiktok_sku_id'] ?? '',
                    'stock_infos' => [
                        [
                            'available_stock' => $quantity,
                            'warehouse_id' => $account->settings['tiktok_warehouse_id'] ?? '',
                        ],
                    ],
                ],
            ],
        ];

        $inventoryResponse = $this->callApi(
            "/product/202309/products/{$tiktokProductId}/inventory/update",
            $inventoryPayload,
            'POST',
            $accessToken,
            ['shop_id' => $shopId]
        );

        if (!empty($inventoryResponse['error']) || (!empty($inventoryResponse['code']) && $inventoryResponse['code'] !== 0)) {
            Log::error('[TikTokShopService] syncInventoryAndPrice: falha ao atualizar estoque', [
                'sku' => $sku,
                'error' => $inventoryResponse['message'] ?? 'unknown',
            ]);
            return false;
        }

        // Update price via product partial update
        $pricePayload = [
            'skus' => [
                [
                    'id' => $product->marketplace_ids['tiktok_sku_id'] ?? '',
                    'original_price' => (string) $price,
                ],
            ],
        ];

        $priceResponse = $this->callApi(
            "/product/202309/products/{$tiktokProductId}",
            $pricePayload,
            'PUT',
            $accessToken,
            ['shop_id' => $shopId]
        );

        if (!empty($priceResponse['error']) || (!empty($priceResponse['code']) && $priceResponse['code'] !== 0)) {
            Log::error('[TikTokShopService] syncInventoryAndPrice: falha ao atualizar preco', [
                'sku' => $sku,
                'error' => $priceResponse['message'] ?? 'unknown',
            ]);
            return false;
        }

        Log::info('[TikTokShopService] syncInventoryAndPrice: estoque e preco atualizados', [
            'sku' => $sku,
            'quantity' => $quantity,
            'price' => $price,
        ]);

        return true;
    }

    // ──────────────────────────── Shipping ────────────────────────────

    public function getShippingLabel(MarketplaceAccount $account, Order $order): ?string
    {
        $accessToken = $this->getValidAccessToken($account);
        $shopId = $account->shop_id;

        if (!$accessToken || !$shopId) {
            Log::warning('[TikTokShopService] getShippingLabel: access_token ou shop_id ausente', ['order_id' => $order->id]);
            return null;
        }

        $orderId = $order->marketplace_order_id ?? $order->order_number;

        try {
            // Step 1: Get available shipping services
            $servicesResponse = $this->callApi(
                "/fulfillment/202309/orders/{$orderId}/shipping_services",
                [],
                'GET',
                $accessToken,
                ['shop_id' => $shopId]
            );

            if (!empty($servicesResponse['error']) || (!empty($servicesResponse['code']) && $servicesResponse['code'] !== 0)) {
                Log::error('[TikTokShopService] getShippingLabel step 1: falha ao obter servicos', [
                    'order_id' => $orderId,
                    'error' => $servicesResponse['message'] ?? 'unknown',
                ]);
                return null;
            }

            $shippingServices = $servicesResponse['data']['shipping_services'] ?? [];
            $shippingServiceId = $shippingServices[0]['id'] ?? null;

            if (!$shippingServiceId) {
                Log::warning('[TikTokShopService] getShippingLabel: nenhum servico de envio disponivel', ['order_id' => $orderId]);
                return null;
            }

            // Step 2: Ship the package
            $shipPayload = [
                'order_id' => $orderId,
                'shipping_service_id' => $shippingServiceId,
            ];

            $shipResponse = $this->callApi(
                '/fulfillment/202309/packages/ship',
                $shipPayload,
                'POST',
                $accessToken,
                ['shop_id' => $shopId]
            );

            if (!empty($shipResponse['error']) || (!empty($shipResponse['code']) && $shipResponse['code'] !== 0)) {
                Log::error('[TikTokShopService] getShippingLabel step 2: falha ao enviar pacote', [
                    'order_id' => $orderId,
                    'error' => $shipResponse['message'] ?? 'unknown',
                ]);
                return null;
            }

            $packageId = $shipResponse['data']['package_id'] ?? null;

            if (!$packageId) {
                Log::warning('[TikTokShopService] getShippingLabel: package_id nao retornado', ['order_id' => $orderId]);
                return null;
            }

            // Step 3: Get shipping label (poll until available, max 10 attempts)
            for ($attempt = 0; $attempt < 10; $attempt++) {
                $labelResponse = $this->callApi(
                    "/fulfillment/202309/packages/{$packageId}/shipping_documents",
                    ['document_type' => 'SHIPPING_LABEL'],
                    'GET',
                    $accessToken,
                    ['shop_id' => $shopId]
                );

                $docUrl = $labelResponse['data']['doc_url'] ?? null;

                if ($docUrl) {
                    $contents = Http::get($docUrl)->body();
                    $path = "shipping-labels/tiktok/{$orderId}.pdf";
                    \Storage::disk('local')->put($path, $contents);

                    Log::info('[TikTokShopService] getShippingLabel: etiqueta salva', [
                        'order_id' => $orderId,
                        'path' => $path,
                    ]);

                    return $path;
                }

                sleep(2);
            }

            Log::warning('[TikTokShopService] getShippingLabel: timeout aguardando etiqueta', ['order_id' => $orderId]);
            return null;
        } catch (\Exception $e) {
            Log::error('[TikTokShopService] getShippingLabel: excecao', [
                'order_id' => $orderId,
                'message' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function getShippingLabelBatch(MarketplaceAccount $account, Collection $orders): Collection
    {
        return $orders->mapWithKeys(function (Order $order) use ($account) {
            return [$order->id => $this->getShippingLabel($account, $order)];
        });
    }
}
