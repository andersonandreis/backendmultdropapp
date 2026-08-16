<?php

namespace App\Services\Integrations\Marketplaces;

use App\Models\MarketplaceAccount;
use App\Models\Product;
use App\Models\Order;
use App\Services\Integrations\Contracts\MarketplaceInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ShopeeService implements MarketplaceInterface
{
    /** Host sem path — pra endpoints que ja trazem o /api/v2 completo. */
    private const HOST = 'https://partner.shopeemobile.com';

    private string $baseUrl = 'https://partner.shopeemobile.com/api/v2';
    private string $partnerId;
    private string $partnerKey;
    private string $redirectUri;
    private bool $useBridge;
    private string $bridgeUrl;
    private string $bridgeSecret;

    public function __construct()
    {
        $this->partnerId = config('services.shopee.partner_id', env('SHOPEE_PARTNER_ID', ''));
        $this->partnerKey = config('services.shopee.partner_key', env('SHOPEE_PARTNER_KEY', ''));
        $this->redirectUri = config('services.shopee.redirect_uri', env('SHOPEE_REDIRECT_URI', ''));
        $this->useBridge = (bool) config('services.shopee.use_bridge', false);
        $this->bridgeUrl = config('services.shopee.bridge_url', '');
        $this->bridgeSecret = config('services.shopee.bridge_secret', '');
    }

    protected function buildAuthSignature(string $path, int $timestamp): string
    {
        $baseString = $this->partnerId . $path . $timestamp;
        return hash_hmac('sha256', $baseString, $this->partnerKey);
    }

    /**
     * FOR-065: Verifica se uma categoria Shopee e folha (nao tem filhos).
     * Usa a API get_category_tree com cache Redis por 24h.
     * Categorias sem ID configurado (NULL→100001) sao sempre PAI — retorna false.
     *
     * @param int $categoryId
     * @return bool true = folha, false = pai (ou nao foi possivel verificar)
     */
    protected function isShopeeCategoryLeaf(int $categoryId): bool
    {
        // 100001 e o fallback padrao quando nenhuma categoria foi configurada —
        // e categoria raiz (pai de tudo), bloqueamos na origem.
        if ($categoryId === 100001 || $categoryId <= 0) {
            return false;
        }

        $cacheKey = "shopee_category_is_leaf:{$categoryId}";

        return Cache::remember($cacheKey, 86400, function () use ($categoryId) {
            // Chama get_category_tree (endpoint publico — sem shop_id/access_token)
            $path      = '/api/v2/product/get_category';
            $timestamp = time();
            $sign      = $this->buildAuthSignature($path, $timestamp);

            $url = $this->baseUrl . '/product/get_category?' . http_build_query([
                'partner_id'  => $this->partnerId,
                'timestamp'   => $timestamp,
                'sign'        => $sign,
                'language'    => 'pt',
            ]);

            try {
                $response = Http::timeout(10)->get($url);
                if ($response->failed()) {
                    // Se nao conseguiu verificar, deixa passar (fail-open para nao
                    // bloquear produtos validos por indisponibilidade de API)
                    Log::channel('marketplace')->warning('[ShopeeService] isShopeeCategoryLeaf: API indisponivel', [
                        'category_id' => $categoryId,
                        'status'      => $response->status(),
                    ]);
                    return true;
                }

                $data = $response->json();
                $categories = $data['response']['category_list'] ?? [];

                if (empty($categories)) {
                    // Sem dados: fail-open
                    return true;
                }

                // Monta set de category_ids que sao PAI de alguma outra categoria
                $parentIds = [];
                foreach ($categories as $cat) {
                    $pid = $cat['parent_category_id'] ?? 0;
                    if ($pid > 0) {
                        $parentIds[$pid] = true;
                    }
                }

                // Se categoryId aparece como pai de outro -> NAO e folha
                $isLeaf = !isset($parentIds[$categoryId]);

                // Bônus FOR-065: se PAI com exatamente 1 filho direto, loga sugestao
                if (!$isLeaf) {
                    $children = array_filter($categories, fn($c) => ($c['parent_category_id'] ?? 0) === $categoryId);
                    if (count($children) === 1) {
                        $child = reset($children);
                        Log::channel('marketplace')->info('[ShopeeService] guard_category_sugestao', [
                            'category_id_pai'  => $categoryId,
                            'sugestao_leaf_id' => $child['category_id'] ?? null,
                            'sugestao_nome'    => $child['display_category_name'] ?? null,
                        ]);
                    }
                }

                return $isLeaf;
            } catch (\Exception $e) {
                Log::channel('marketplace')->warning('[ShopeeService] isShopeeCategoryLeaf: excecao', [
                    'category_id' => $categoryId,
                    'message'     => $e->getMessage(),
                ]);
                return true; // fail-open
            }
        });
    }

    public function authenticate(MarketplaceAccount $account): string|array
    {
        // Shopee OAuth url gen
        $timestamp = time();
        $path = '/api/v2/shop/auth_partner';
        $sign = $this->buildAuthSignature($path, $timestamp);

        // URL Authorization via bridge (api.hubai.io e a redirect URI registrada no painel Shopee)
        // State base64 JSON com client_id + origin_callback para o relay do Fornecefy
        $returnUrl = config('app.frontend_url', 'https://hubai.io') . '/integracoes';
        $relayUrl  = url('/api/oauth/shopee/hubai-relay');   // endpoint no proprio Fornecefy
        $statePayload = base64_encode(json_encode([
            'client_id'       => $account->client_id,
            'supplier_id'     => $account->supplier_id ?? 1,
            'account_name'    => $account->account_name ?? 'Loja Shopee',
            'platform'        => 'shopee',
            'code_verifier'   => null,
            'return_url'      => $returnUrl,
            'shop_domain'     => '',
            'origin_callback' => $relayUrl,
        ]));

        // redirect DEVE ser a URL registrada no painel Shopee Open Platform (api.hubai.io)
        $callbackUrl = $this->redirectUri ?: 'https://api.hubai.io/shopee/oauth-callback';
        $url = "https://partner.shopeemobile.com/api/v2/shop/auth_partner?partner_id={$this->partnerId}&timestamp={$timestamp}&sign={$sign}&redirect={$callbackUrl}&state={$statePayload}";

        return [
            'status' => 'redirect',
            'url' => $url
        ];
    }

    public function syncProduct(MarketplaceAccount $account, Product $product): bool|array
    {
        // FOR-066: guard inicial — conta suspensa ou kyc_pending nao faz HTTP call
        // SEL-422: o guard so conhecia 'kyc_pending' (ingles) com in_array strict,
        // mas o SEL-397 grava 'kyc_pendente' (portugues) — que e o que esta no
        // banco. Resultado: a conta marcada com pendencia passava direto e
        // continuava batendo no Shopee em chamada que ia falhar. Agora as duas
        // grafias sao reconhecidas, sempre, pra nao deixar registro antigo invisivel.
        if (in_array($account->status, array_merge(['suspended'], \App\Services\Integrations\PendenciaContaService::KYC), true)) {
            $reason = \App\Services\Integrations\PendenciaContaService::ehKyc($account->status)
                ? 'Conta Shopee com KYC pendente (registro de vendedor incompleto no Seller Center)'
                : 'Conta Shopee suspensa';
            Log::warning('[ShopeeService] syncProduct: guard_account_blocked', [
                'account_id' => $account->id,
                'shop_id'    => $account->shop_id,
                'status'     => $account->status,
                'sku'        => $product->sku,
            ]);
            \App\Models\SyncLog::create([
                'syncable_type'   => Product::class,
                'syncable_id'     => $product->id,
                'platform'        => 'shopee',
                'action'          => 'guard_account_blocked',
                'direction'       => 'outbound',
                'status'          => 'skipped',
                'error_message'   => $reason,
                'request_payload' => json_encode(['sku' => $product->sku, 'account_status' => $account->status]),
            ]);
            return ['error' => 'guard_account_blocked', 'message' => $reason];
        }

        $shopId = $this->getShopId($account);
        $accessToken = $this->getValidAccessToken($account);

        if (!$shopId) {
            return ['error' => 'config_error', 'message' => 'Shop ID nao configurado nesta conta Shopee. Reconecte a conta.'];
        }
        if (!$accessToken) {
            return ['error' => 'token_error', 'message' => 'Token Shopee ausente ou expirado. Reconecte a conta.'];
        }

        // --- INICIO: ESCUDO ANTI-BAN (HIPER-AUTOMACAO) ---
        $forbiddenWords = \App\Models\ForbiddenWord::where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('context')->orWhere('context', 'shopee');
            })->pluck('word')->toArray();

        $textToAnalyze = strtolower($product->name . ' ' . ($product->description ?? ''));
        foreach ($forbiddenWords as $word) {
            if (mb_stripos($textToAnalyze, strtolower($word)) !== false) {
                // Bloqueio preventivo ativado!
                \App\Models\SyncLog::create([
                    'syncable_type' => Product::class,
                    'syncable_id' => $product->id,
                    'platform' => 'shopee',
                    'action' => 'Sync Product',
                    'direction' => 'outbound',
                    'status' => 'failed',
                    'error_message' => "BLOQUEIO PREVENTIVO HUBAI: O produto contem a palavra proibida '{$word}'. Remova este termo para evitar banimento da sua loja na Shopee.",
                    'request_payload' => json_encode(['title' => $product->name, 'description' => $product->description]),
                ]);
                return false;
            }
        }
        // --- FIM: ESCUDO ANTI-BAN ---

        // --- INICIO: GUARD CATEGORIA NAO-FOLHA (FOR-065) ---
        $resolvedCategoryId = (int) ($product->shopee_category_id ?? $product->category_id ?? 100001);

        // SEL-311: catalogo nao preenche shopee_category_id -> fallback 100001 (raiz)
        // fazia o guard bloquear 100% dos publishes Shopee em silencio. Antes de
        // bloquear, resolve automatico via category_recommend pelo nome do produto.
        if (!$this->isShopeeCategoryLeaf($resolvedCategoryId)) {
            $recommended = $this->recommendCategoryByName((string) $product->name, $accessToken, $shopId);
            if ($recommended && $this->isShopeeCategoryLeaf($recommended)) {
                $resolvedCategoryId = $recommended;
                $product->shopee_category_id = $recommended;
                $product->saveQuietly();
                Log::channel('marketplace')->info('[ShopeeService] SEL-311 categoria auto-resolvida via category_recommend', [
                    'sku'         => $product->sku,
                    'category_id' => $recommended,
                ]);
            }
        }

        if (!$this->isShopeeCategoryLeaf($resolvedCategoryId)) {
            $logMsg = "FOR-065: categoria Shopee {$resolvedCategoryId} nao e folha (tem subcategorias). Atribua categoria terminal ao produto.";

            \App\Models\SyncLog::create([
                'syncable_type'   => \App\Models\Product::class,
                'syncable_id'     => $product->id,
                'platform'        => 'shopee',
                'action'          => 'guard_category_not_leaf',
                'direction'       => 'outbound',
                'status'          => 'skipped',
                'error_message'   => $logMsg,
                'request_payload' => json_encode(['sku' => $product->sku, 'category_id' => $resolvedCategoryId]),
            ]);

            Log::channel('marketplace')->warning('[ShopeeService] guard_category_not_leaf', [
                'sku'         => $product->sku,
                'category_id' => $resolvedCategoryId,
            ]);

            return ['error' => 'guard_category_not_leaf', 'message' => $logMsg];
        }
        // --- FIM: GUARD CATEGORIA NAO-FOLHA (FOR-065) ---

        // Monta payload Shopee v2
        $weight = $product->weight_kg ?? $product->weight ?? 0.5;
        $stock  = max(1, (int) ($product->virtual_stock_qty ?? $product->stock_qty ?? 1));

        $payload = [
            'original_price' => (float) $product->price,
            'description'    => $product->description ?? $product->name,
            'item_name'      => $product->name,
            'item_sku'       => $product->sku,
            'weight'         => (float) $weight,
            // seller_stock e o formato exigido pela API Shopee atual (normal_stock e legado)
            'seller_stock'   => [['stock' => $stock]],
            // SEL-366: canal fixo 90003 (Correios) bloqueava lojas sem Correios habilitado
            // (Shopee devolve error_invalid_logistic_info e o guard FOR-068 marcava
            // no_shipping_channel indevido). Resolve o canal real via get_channel_list.
            'logistic_info'  => [
                $product->shopee_logistic_id
                    ? ['logistic_id' => (int) $product->shopee_logistic_id, 'enabled' => true]
                    : $this->getFirstEnabledLogistic((string) $accessToken, (int) $shopId),
            ],
            // Dimensoes obrigatorias para canais com fee_type=SIZE_INPUT (ex: Correios)
            // Formato correto: objeto 'dimension' com campos internos (nao campos separados no root)
            'dimension' => [
                // Shopee exige minimo 1cm; dados em fracao (ex: 0.25cm) convertem para 0 sem max()
                'package_length' => max(1, (int) round($product->length_cm ?? 15)),
                'package_width'  => max(1, (int) round($product->width_cm  ?? 10)),
                'package_height' => max(1, (int) round($product->height_cm ?? 5)),
            ],
            'category_id'    => $resolvedCategoryId,
            'condition'      => 'NEW',
            'brand'          => ['brand_id' => (int) ($product->shopee_brand_id ?? 0), 'original_brand_name' => 'NoBrand'],
        ];

        // FOR-069: inclui gtin/ean no payload se disponivel (Shopee passou a exigir em algumas categorias)
        $gtinValue = $product->gtin ?? $product->ean ?? null;
        if (!empty($gtinValue)) {
            $payload['tax_info'] = ['gtin' => (string) $gtinValue];
        }

        // Adiciona imagens: primeiro tenta shopee_image_id (ja enviadas), depois faz upload
        if (method_exists($product, 'media') && $product->media()->count()) {
            $imageIds = $product->media()->pluck('shopee_image_id')->filter()->values()->toArray();
            if (empty($imageIds)) {
                // Tenta fazer upload das imagens via URL
                $imageIds = $this->uploadProductImages($account, $product, $accessToken, $shopId);
            }
            if (!empty($imageIds)) {
                $payload['image'] = ['image_id_list' => $imageIds];
            }
        }

        // Se ja tem shopee_item_id -> update_item, senao -> add_item
        if (!empty($product->shopee_item_id)) {
            $payload['item_id'] = (int) $product->shopee_item_id;
            $result = $this->callApi('/api/v2/product/update_item', [
                'shop_id'      => $shopId,
                'access_token' => $accessToken,
            ] + $payload);
        } else {
            $result = $this->callApi('/api/v2/product/add_item', [
                'shop_id'      => $shopId,
                'access_token' => $accessToken,
            ] + $payload);

            if (isset($result['response']['item_id'])) {
                $product->update(['shopee_item_id' => $result['response']['item_id']]);
            }
        }

        $errorCode = $result['error'] ?? '';
        $success   = ($errorCode === '' || $errorCode === null);

        Log::channel('marketplace')->info('[ShopeeService] syncProduct', [
            'shop_id'  => $shopId,
            'sku'      => $product->sku,
            'item_id'  => $product->shopee_item_id,
            'success'  => $success,
            'error'    => $errorCode ?: null,
            'message'  => $result['message'] ?? null,
        ]);

        if ($success) {
            return $result;
        }
        // FOR-066: error_kyc_auth -> marca conta kyc_pending + bloqueia jobs futuros
        if ($errorCode === 'error_kyc_auth') {
            $kycMsg = 'kyc_pending: seller registration incomplete (Shopee Seller Center)';
            $account->update([
                // SEL-422: grafia canonica unica (a que ja esta no banco).
                'status'             => \App\Services\Integrations\PendenciaContaService::KYC_CANONICO,
                'sync_blocked_at'    => now(),
                'last_error_message' => $kycMsg,
            ]);
            \App\Models\SyncLog::create([
                'syncable_type'   => Product::class,
                'syncable_id'     => $product->id,
                'platform'        => 'shopee',
                'action'          => 'guard_kyc_pending',
                'direction'       => 'outbound',
                'status'          => 'error',
                'error_message'   => $kycMsg,
                'request_payload' => json_encode(['shop_id' => $shopId, 'sku' => $product->sku]),
            ]);
            Log::error('[ShopeeService] syncProduct: error_kyc_auth — conta marcada kyc_pending', [
                'account_id' => $account->id,
                'shop_id'    => $shopId,
                'sku'        => $product->sku,
            ]);
            return ['error' => 'error_kyc_auth', 'message' => 'KYC Shopee pendente — o vendedor precisa concluir o registro no Seller Center.'];
        }


        // FOR-068: error_invalid_logistic_info -> nenhum canal logistico habilitado na shop
        if ($errorCode === 'product.error_invalid_logistic_info') {
            $logisticMsg = 'no_shipping_channel: nenhum canal logistico habilitado. Ative ao menos 1 no Seller Center.';
            Log::error('[ShopeeService] syncProduct: error_invalid_logistic_info', [
                'account_id' => $account->id,
                'shop_id'    => $shopId,
                'sku'        => $product->sku,
            ]);
            return ['error' => 'error_invalid_logistic_info', 'message' => $logisticMsg];
        }

        // FOR-069: error_busi com mensagem de GTIN -> categoriza separado do abnormal generico
        if ($errorCode === 'product.error_busi') {
            $gtinDetail = $result['message'] ?? '';
            if (mb_stripos($gtinDetail, 'GTIN') !== false) {
                $gtinMsg = 'missing_gtin: GTIN obrigatorio para esta categoria Shopee. Cadastre o barcode EAN/UPC no produto.';
                Log::warning('[ShopeeService] syncProduct: error_busi_missing_gtin', [
                    'account_id' => $account->id,
                    'shop_id'    => $shopId,
                    'sku'        => $product->sku,
                    'item_id'    => $product->shopee_item_id,
                    'detail'     => $gtinDetail,
                ]);
                \App\Models\SyncLog::create([
                    'syncable_type'   => Product::class,
                    'syncable_id'     => $product->id,
                    'platform'        => 'shopee',
                    'action'          => 'guard_missing_gtin',
                    'direction'       => 'outbound',
                    'status'          => 'error',
                    'error_message'   => $gtinMsg,
                    'request_payload' => json_encode(['sku' => $product->sku, 'item_id' => $product->shopee_item_id, 'shopee_msg' => $gtinDetail]),
                ]);
                return ['error' => 'error_busi_missing_gtin', 'message' => $gtinMsg];
            }
        }

                        $shopeeErrors = [

            'product.error_busi'  => 'Produto com status anormal na Shopee (item_abnormal). Acesse o Seller Center e regularize o anuncio.',
            'product.error_param' => 'Parametro invalido na publicacao Shopee.',
            'error_auth'          => 'Token Shopee expirado ou invalido. Reconecte a conta Shopee.',
            'error_not_found'     => 'Produto nao encontrado na Shopee.',
        ];
        $detail   = $result['message'] ?? '';
        $humanMsg = $shopeeErrors[$errorCode] ?? "Erro Shopee [{$errorCode}]: {$detail}";
        if ($errorCode === 'product.error_param' && $detail) {
            $humanMsg .= ' Detalhe: ' . $detail;
        }
        return ['error' => $errorCode, 'message' => $humanMsg];
    }

    public function fetchOrders(MarketplaceAccount $account, string $sinceDate = null): array
    {
        $shopId = $this->getShopId($account);
        $accessToken = $this->getValidAccessToken($account);

        if (!$shopId || !$accessToken) {
            Log::warning('[ShopeeService] fetchOrders: shop_id ou access_token ausente', ['account_id' => $account->id]);
            return [];
        }

        $timeFrom = $sinceDate ? strtotime($sinceDate) : strtotime('-7 days');
        $timeTo = time();

        // Step 1: Get order list com janelas de 15 dias (limite Shopee API = max 15 dias por chamada)
        // Para periodos maiores, divide em multiplas janelas de 15 dias
        $orderSnList      = [];
        $maxWindowSeconds = 15 * 24 * 3600; // 15 dias em segundos
        $pageSize         = 50;
        $maxPages         = 200; // protecao geral contra loop infinito
        $totalPageCount   = 0;

        // Divide o periodo total em janelas de 15 dias
        $windowStart = $timeFrom;
        while ($windowStart < $timeTo && $totalPageCount < $maxPages) {
            $windowEnd = min($windowStart + $maxWindowSeconds, $timeTo);

            $cursor    = '';
            $pageCount = 0;

            do {
                $listParams = [
                    'time_range_field' => 'create_time',
                    'time_from'        => $windowStart,
                    'time_to'          => $windowEnd,
                    'page_size'        => $pageSize,
                    'cursor'           => $cursor,
                    'shop_id'          => $shopId,
                    'access_token'     => $accessToken,
                ];

                $listResponse = $this->callApi('/api/v2/order/get_order_list', $listParams, 'GET');

                // Ignorar janelas com erro (ex: order_list_invalid_time — nao deve ocorrer com 15d)
                if (!empty($listResponse['error']) && $listResponse['error'] !== '') {
                    Log::warning('[ShopeeService] fetchOrders: erro na janela', [
                        'account_id'   => $account->id,
                        'window_start' => $windowStart,
                        'window_end'   => $windowEnd,
                        'error'        => $listResponse['error'],
                    ]);
                    break;
                }

                $pageOrders = $listResponse['response']['order_list'] ?? [];
                $hasMore    = (bool) ($listResponse['response']['more'] ?? false);
                $cursor     = $listResponse['response']['next_cursor'] ?? '';

                foreach ($pageOrders as $o) {
                    if (!empty($o['order_sn'])) {
                        $orderSnList[] = $o['order_sn'];
                    }
                }

                $pageCount++;
                $totalPageCount++;
            } while ($hasMore && $cursor && $totalPageCount < $maxPages);

            $windowStart = $windowEnd;
        }

        // Deduplicar order_sns (pode haver sobreposicao nas bordas das janelas)
        $orderSnList = array_unique($orderSnList);

        if (empty($orderSnList)) {
            Log::info('[ShopeeService] fetchOrders: nenhum pedido encontrado', [
                'account_id' => $account->id,
                'pages'      => $totalPageCount,
            ]);
            return [];
        }

        // Step 2: Get order details (batches of 50)
        $allOrders = [];
        foreach (array_chunk($orderSnList, 50) as $chunk) {
            $detailParams = [
                'order_sn_list' => implode(',', $chunk),
                // MUL-197: total_amount/pay_time/create_time/order_status/tracking_no eram
                // omitidos — raiz das cascas (total=0, paid_at NULL) criadas pelo sync.
                'response_optional_fields' => 'buyer_user_id,buyer_username,item_list,recipient_address,shipping_carrier,package_list,order_sn,total_amount,pay_time,create_time,order_status,tracking_no',
                'shop_id' => $shopId,
                'access_token' => $accessToken,
            ];

            $detailResponse = $this->callApi('/api/v2/order/get_order_detail', $detailParams, 'GET');

            if (!empty($detailResponse['response']['order_list'])) {
                $allOrders = array_merge($allOrders, $detailResponse['response']['order_list']);
            }
        }

        Log::info('[ShopeeService] fetchOrders: pedidos obtidos', [
            'account_id' => $account->id,
            'count' => count($allOrders),
        ]);

        return $allOrders;
    }

    public function syncInventoryAndPrice(MarketplaceAccount $account, string $sku, int $quantity, float $price): bool
    {
        $shopId = $this->getShopId($account);
        $accessToken = $this->getValidAccessToken($account);

        if (!$shopId || !$accessToken) {
            Log::warning('[ShopeeService] syncInventoryAndPrice: shop_id ou access_token ausente', ['account_id' => $account->id]);
            return false;
        }

        // NOV-079: busca pelo external_listing_id ou custom_sku em client_products
        // Shopee item_id fica em shopee_external_item_id ou external_listing_id (ex: 123456789)
        $clientProduct = \App\Models\ClientProduct::where('marketplace_account_id', $account->id)
            ->where(function ($q) use ($sku) {
                $q->where('external_listing_id', $sku)
                  ->orWhere('shopee_external_item_id', $sku)
                  ->orWhere('custom_sku', $sku)
                  ->orWhere('supplier_product_sku', $sku);
            })
            ->first();

        if (!$clientProduct || !$clientProduct->external_listing_id) {
            Log::warning('[ShopeeService] syncInventoryAndPrice: listing nao encontrada', ['account_id' => $account->id, 'sku' => $sku]);
            return false;
        }

        // Shopee item_id: preferir shopee_external_item_id, fallback external_listing_id
        $itemId = (int) ($clientProduct->shopee_external_item_id ?: $clientProduct->external_listing_id);
        $modelId = 0; // sem variacao por padrao

        // Update stock
        $stockParams = [
            'item_id' => $itemId,
            'stock_list' => [
                [
                    'model_id' => $modelId,
                    'normal_stock' => $quantity,
                ],
            ],
            'shop_id' => $shopId,
            'access_token' => $accessToken,
        ];

        $stockResponse = $this->callApi('/api/v2/product/update_stock', $stockParams);

        if (!empty($stockResponse['error']) && $stockResponse['error'] !== '') {
            $stockError = $stockResponse['error'] ?? $stockResponse['message'] ?? 'unknown';

            // NOV-151: product.error_param = produto removido/banido* of the Shopee (erro permanente).
            // Marcar ClientProduct com sync_status=sync_failed_permanent para parar retries.
            if ($stockError === 'product.error_param' && $clientProduct) {
                $clientProduct->update([
                    'sync_status'      => 'sync_failed_permanent',
                    'last_sync_error' => 'Shopee: product.error_param - produto removido ou banido da plataforma',
                ]);
                Log::warning('[ShopeeService] syncInventoryAndPrice: product.error_param - marcado permanent-failed, retries parados', [
                    'sku'               => $sku,
                    'client_product_id' => $clientProduct->id ?? null,
                ]);
            } else {
                Log::error('[ShopeeService] syncInventoryAndPrice: falha ao atualizar estoque', [
                    'sku'    => $sku,
                    'error' => $stockError,
                ]);
            }
            return false;
        }

        // Update price
        $priceParams = [
            'item_id' => $itemId,
            'price_list' => [
                [
                    'model_id' => $modelId,
                    'original_price' => $price,
                ],
            ],
            'shop_id' => $shopId,
            'access_token' => $accessToken,
        ];

        $priceResponse = $this->callApi('/api/v2/product/update_price', $priceParams);

        if (!empty($priceResponse['error']) && $priceResponse['error'] !== '') {
            Log::error('[ShopeeService] syncInventoryAndPrice: falha ao atualizar preco', [
                'sku' => $sku,
                'error' => $priceResponse['error'] ?? $priceResponse['message'] ?? 'unknown',
            ]);
            return false;
        }

        Log::info('[ShopeeService] syncInventoryAndPrice: estoque e preco atualizados', [
            'sku' => $sku,
            'quantity' => $quantity,
            'price' => $price,
        ]);

        return true;
    }


    /**
     * Faz upload de imagens do produto para a Shopee via URL (upload_image by url).
     * Retorna array de image_id da Shopee para usar no add_item/update_item.
     * Salva shopee_image_id em product_media para evitar re-upload.
     */
    protected function uploadProductImages(MarketplaceAccount $account, Product $product, string $accessToken, int $shopId): array
    {
        $imageIds = [];
        $medias   = $product->media()->whereNotNull('url')->take(9)->get(); // Shopee suporta max 9 imagens

        foreach ($medias as $media) {
            if (!empty($media->shopee_image_id)) {
                $imageIds[] = $media->shopee_image_id;
                continue;
            }

            $imageUrl = $media->url ?? $media->original_url;
            if (!$imageUrl) {
                continue;
            }

            // Upload via cURL multipart (Shopee exige binary multipart, nao JSON)
            $imageContent = @file_get_contents($imageUrl);
            if (!$imageContent) {
                Log::warning('[ShopeeService] uploadProductImages: falha ao baixar imagem', ['url' => $imageUrl]);
                continue;
            }
            // Detectar e converter WebP para JPEG (Shopee nao aceita WebP)
            $tmpFile = tempnam(sys_get_temp_dir(), 'shopee_img_') . '.jpg';
            $isWebp = (
                str_ends_with(strtolower($imageUrl), '.webp') ||
                (strlen($imageContent) > 4 && substr($imageContent, 0, 4) === 'RIFF')
            );
            if ($isWebp && function_exists('imagecreatefromwebp')) {
                $tmpWebp = tempnam(sys_get_temp_dir(), 'shopee_webp_') . '.webp';
                file_put_contents($tmpWebp, $imageContent);
                $gdImg = @imagecreatefromwebp($tmpWebp);
                @unlink($tmpWebp);
                if ($gdImg) {
                    imagejpeg($gdImg, $tmpFile, 85);
                    imagedestroy($gdImg);
                    Log::channel('marketplace')->info('[ShopeeService] uploadProductImages: WebP convertido para JPEG', ['url' => $imageUrl]);
                } else {
                    file_put_contents($tmpFile, $imageContent);
                }
            } else {
                file_put_contents($tmpFile, $imageContent);
            }

            $path      = '/api/v2/media_space/upload_image';
            $timestamp = time();
            $baseString = $this->partnerId . $path . $timestamp . $accessToken . $shopId;
            $sign      = hash_hmac('sha256', $baseString, $this->partnerKey);
            $queryString = http_build_query([
                'partner_id'   => $this->partnerId,
                'timestamp'    => $timestamp,
                'access_token' => $accessToken,
                'shop_id'      => $shopId,
                'sign'         => $sign,
            ]);
            $url = "{$this->baseUrl}/media_space/upload_image?{$queryString}";

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_POSTFIELDS, [
                'image' => new \CURLFile($tmpFile, 'image/jpeg', 'product.jpg'),
            ]);
            $responseBody = curl_exec($ch);
            curl_close($ch);
            @unlink($tmpFile);

            $result = json_decode($responseBody, true) ?? [];
            $shopeeImageId = $result['response']['image_info']['image_id'] ?? null;

            if ($shopeeImageId) {
                $media->update(['shopee_image_id' => $shopeeImageId]);
                $imageIds[] = $shopeeImageId;
                Log::channel('marketplace')->info('[ShopeeService] uploadProductImages: imagem enviada', [
                    'product_id' => $product->id,
                    'media_id'   => $media->id,
                    'shopee_image_id' => $shopeeImageId,
                ]);
            } else {
                Log::warning('[ShopeeService] uploadProductImages: falha ao enviar imagem', [
                    'product_id' => $product->id,
                    'media_id'   => $media->id,
                    'url'        => $imageUrl,
                    'error'      => $result['error'] ?? 'unknown',
                    'message'    => $result['message'] ?? '',
                ]);
            }
        }

        return $imageIds;
    }

    /**
     * Descriptografa token salvo pelo OAuthController (que usa encrypt()).
     * Retorna o token em texto claro ou o proprio valor se nao estiver encriptado.
     */
    protected function decryptToken(?string $encryptedToken): ?string
    {
        if (!$encryptedToken) {
            return null;
        }
        try {
            return decrypt($encryptedToken);
        } catch (\Throwable $e) {
            // Token ja em texto claro (salvo pelo refreshToken sem encrypt) - retornar direto
            return $encryptedToken;
        }
    }

    /**
     * NOV-061: wrapper publico (alinhado com MercadoLivreService::getAccessToken).
     * Permite que o InternalTokenController chame o mesmo metodo em qualquer service.
     */
    public function getAccessToken(MarketplaceAccount $account): ?string
    {
        return $this->getValidAccessToken($account);
    }
    public function getValidAccessToken(MarketplaceAccount $account): ?string
    {
        $token = $account->access_token;

        if (!$token) {
            return null;
        }

        // NOV-061: token ainda valido com margem de 5min -> retornar direto sem lock
        if ($account->token_expires_at && now()->lt($account->token_expires_at->subMinutes(5))) {
            return $this->decryptToken($token);
        }

        // NOV-061 (Bug 1): lock distribuido evita thundering herd com N workers
        // simultaneos refrescando o mesmo token (gera erro 1010 na Shopee e gasta refresh_token).
        $lock = \Illuminate\Support\Facades\Cache::store('redis')->lock("shopee_token_refresh:{$account->id}", 30);
        try {
            $lock->block(10);
            $account->refresh(); // re-ler do banco - outro worker pode ter renovado enquanto esperavamos
            if ($account->token_expires_at && now()->lt($account->token_expires_at->subMinutes(5))) {
                return $this->decryptToken($account->access_token);
            }
            // PR5: token marcado como quebrado permanentemente -- parar retry ate reconexao OAuth
            if ($account->is_token_broken) {
                \Illuminate\Support\Facades\Log::warning('[ShopeeService] Conta com token quebrado (is_token_broken=1) -- skip refresh', [
                    'account_id' => $account->id,
                    'reason'     => $account->token_broken_reason,
                ]);
                return null;
            }
            return $this->refreshToken($account);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            \Illuminate\Support\Facades\Log::warning('[ShopeeService] Lock timeout no refresh', ['account_id' => $account->id]);
            return null;
        } finally {
            optional($lock)->release();
        }
    }

    public function refreshToken(MarketplaceAccount $account): ?string
    {
        // NOV-181: WL em modo bridge com conta gerenciada centralmente NUNCA renova
        // na Shopee (refresh_token e single-use — renovar aqui mataria a cadeia do hub).
        // Em vez disso, pede o token renovado ao hub central.
        $installation = app(\App\Services\InstallationConfig::class);
        if ($installation->usesBridge('shopee') && $account->centrally_managed) {
            return $this->requestRefreshFromHub($account);
        }

        $refreshToken = $this->decryptToken($account->refresh_token);
        $shopId = $this->getShopId($account);

        if (!$refreshToken || !$shopId) {
            Log::warning('[ShopeeService] refreshToken: refresh_token ou shop_id ausente', ['account_id' => $account->id]);
            return null;
        }

        $path = '/api/v2/auth/access_token/get';
        $timestamp = time();
        $baseString = $this->partnerId . $path . $timestamp;
        $sign = hash_hmac('sha256', $baseString, $this->partnerKey);

        // Shopee v2: partner_id, timestamp, sign devem estar na QUERY STRING (obrigatorio)
        // A Shopee tambem exige partner_id no body POST (doc v2.0, endpoint access_token/get)
        $queryString = http_build_query([
            'partner_id' => (int) $this->partnerId,
            'timestamp'  => $timestamp,
            'sign'       => $sign,
        ]);

        $response = Http::post("{$this->baseUrl}/auth/access_token/get?{$queryString}", [
            'partner_id'    => (int) $this->partnerId,
            'shop_id'       => $shopId,
            'refresh_token' => $refreshToken,
        ]);

        $responseData = $response->json() ?? [];
        // Shopee retorna error='' (string vazia) em caso de sucesso — usar !empty() nao isset()
        $hasError = $response->failed() || (!empty($responseData['error']) && $responseData['error'] !== '');

        if ($hasError) {
            $httpStatus = $response->status();
            Log::error('[ShopeeService] refreshToken falhou', [
                'account_id' => $account->id,
                'status'     => $httpStatus,
                'body'       => $responseData,
            ]);
            // Erros temporarios (429/502/503/504): lancar exception para circuit breaker, nao marcar is_token_broken
            $temporaryErrors = [429, 502, 503, 504];
            if (in_array($httpStatus, $temporaryErrors)) {
                throw new \RuntimeException('[ShopeeService] Erro temporario HTTP ' . $httpStatus . ' - retry depois');
            }
            // PR5: erros permanentes de token -- marcar is_token_broken=1 para parar retries
            $permanentTokenErrors = ['refresh_token_expired', 'error_param', 'error_auth', 'error_not_found', 'invalid_access_token'];
            $shopeeError = $responseData['error'] ?? '';
            if (in_array($shopeeError, $permanentTokenErrors) || $httpStatus === 403) {
                $account->update([
                    'is_token_broken'     => 1,
                    'token_broken_reason' => $shopeeError ?: 'http_' . $httpStatus,
                    'token_broken_at'     => now(),
                    'status'              => 'needs_reauth',
                    'sync_blocked_at'     => now(),
                    'last_error_message'  => '[PR5] Token Shopee permanentemente expirado: ' . ($shopeeError ?: 'HTTP ' . $httpStatus) . '. Requer reconexao OAuth.',
                ]);
                \Illuminate\Support\Facades\Log::warning('[ShopeeService] Token permanentemente quebrado -- is_token_broken=1 aplicado', [
                    'account_id'   => $account->id,
                    'shopee_error' => $shopeeError,
                    'http_status'  => $httpStatus,
                ]);
                try {
                    \App\Jobs\NotifyTokenBrokenJob::dispatch($account->id, 'shopee');
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('[ShopeeService] Falha ao despachar NotifyTokenBrokenJob', ['error' => $e->getMessage()]);
                }
            }
            return null;
        }

        $data = $responseData;

        // Shopee retorna refresh_token_expire_in (segundos) no response do access_token/get.
        // Se nao vier, usar 30 dias como fallback (duracao padrao do refresh_token Shopee).
        $refreshExpireIn = isset($data['refresh_token_expire_in']) && $data['refresh_token_expire_in'] > 0
            ? (int) $data['refresh_token_expire_in']
            : 86400 * 30;

        $account->update([
            'access_token'             => encrypt($data['access_token']),
            'refresh_token'            => encrypt($data['refresh_token']),
            'token_expires_at'         => now()->addSeconds($data['expire_in']),
            'refresh_token_expires_at' => now()->addSeconds($refreshExpireIn),
            'last_token_refresh_at'    => now(),
        ]);

        Log::info('[ShopeeService] Token renovado', ['account_id' => $account->id]);

        // NOV-181: hub central propaga a cadeia nova pras WLs espelho
        if ($installation->isHub()) {
            try {
                \App\Jobs\PropagateShopeeTokenJob::dispatch($account->id);
            } catch (\Throwable $e) {
                Log::error('[ShopeeService] Falha ao despachar propagacao de token', [
                    'account_id' => $account->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return $data['access_token'];
    }

    /**
     * NOV-181: WL bridge pede ao hub central o token renovado (o hub e o unico
     * dono da cadeia refresh_token). Degrada graciosamente: hub fora do ar ou
     * erro => null, SEM marcar needs_reauth e SEM tocar na Shopee.
     */
    protected function requestRefreshFromHub(MarketplaceAccount $account): ?string
    {
        $secret = (string) config('services.shopee.bridge_secret', '');
        $shopId = $this->getShopId($account);

        if ($secret === '' || ! $shopId) {
            Log::error('[ShopeeService] requestRefreshFromHub: bridge_secret ou shop_id ausente', [
                'account_id' => $account->id,
            ]);
            return null;
        }

        $hubUrl = app(\App\Services\InstallationConfig::class)->hubUrl();
        $body   = json_encode([
            'shop_id'      => (string) $shopId,
            'requested_by' => (string) config('app.url'),
        ]);
        $sig = hash_hmac('sha256', $body, $secret);

        try {
            $response = Http::timeout(20)
                ->withHeaders([
                    'X-HubAI-Bridge-Sig' => $sig,
                    'Content-Type'       => 'application/json',
                ])
                ->withBody($body, 'application/json')
                ->post("{$hubUrl}/api/oauth/shopee/bridge-refresh");
        } catch (\Throwable $e) {
            Log::warning('[ShopeeService] Hub inacessivel no refresh bridge — degradando', [
                'account_id' => $account->id,
                'hub'        => $hubUrl,
                'error'      => $e->getMessage(),
            ]);
            return null;
        }

        $data = $response->json() ?? [];

        if ($response->failed() || empty($data['success']) || empty($data['access_token'])) {
            Log::warning('[ShopeeService] Hub recusou refresh bridge', [
                'account_id' => $account->id,
                'status'     => $response->status(),
                'body'       => substr($response->body(), 0, 300),
            ]);
            return null;
        }

        $account->update([
            'access_token'             => encrypt($data['access_token']),
            'refresh_token'            => encrypt((string) ($data['refresh_token'] ?? '')),
            'token_expires_at'         => $data['token_expires_at'] ?? now()->addHours(4),
            'refresh_token_expires_at' => $data['refresh_token_expires_at'] ?? now()->addDays(30),
            'last_token_refresh_at'    => now(),
            'status'                   => 'active',
        ]);

        Log::info('[ShopeeService] Token espelhado renovado via hub central', [
            'account_id' => $account->id,
            'shop_id'    => $shopId,
        ]);

        return $data['access_token'];
    }

    public function getShopId(MarketplaceAccount $account): ?int
    {
        return $account->shop_id;
    }

    /**
     * Gera assinatura para requests autenticados da Shopee v2.
     */
    protected function buildRequestSignature(string $path, int $timestamp, string $accessToken, int $shopId): string
    {
        $baseString = $this->partnerId . $path . $timestamp . $accessToken . $shopId;
        return hash_hmac('sha256', $baseString, $this->partnerKey);
    }

    /**
     * {@inheritdoc}
     *
     * Full Shopee shipping label flow:
     * 1. Get shipping parameters
     * 2. Ship order
     * 3. Create shipping document
     * 4. Poll until document is READY
     * 5. Download shipping document
     */
    public function getShippingLabel(MarketplaceAccount $account, Order $order): ?string
    {
        $shopId = $this->getShopId($account);
        $accessToken = $this->getValidAccessToken($account);

        if (!$shopId || !$accessToken) {
            Log::warning('[ShopeeService] getShippingLabel: shop_id ou access_token ausente', ['order_id' => $order->id]);
            return null;
        }

        $orderSn = $order->marketplace_order_id ?? $order->order_number;
        $baseParams = ['shop_id' => $shopId, 'access_token' => $accessToken];

        try {
            // Step 1: Get shipping parameter
            $shippingParams = $this->callApi('/api/v2/logistics/get_shipping_parameter', array_merge($baseParams, [
                'order_sn' => $orderSn,
            ]), 'GET');

            if (!empty($shippingParams['error']) && $shippingParams['error'] !== '') {
                Log::error('[ShopeeService] getShippingLabel step 1 falhou', ['order_sn' => $orderSn, 'error' => $shippingParams]);
                return null;
            }

            // Extract pickup info from response
            $infoList = $shippingParams['response']['info_needed'] ?? [];
            $pickup = $infoList['pickup'] ?? null;
            $dropoff = $infoList['dropoff'] ?? null;

            // Step 2: Ship order
            $shipPayload = array_merge($baseParams, ['order_sn' => $orderSn]);

            if ($pickup) {
                $shipPayload['pickup'] = [
                    'address_id' => $pickup['address_list'][0]['address_id'] ?? 0,
                    'pickup_time_id' => $pickup['time_slot_list'][0]['pickup_time_id'] ?? '',
                ];
            } elseif ($dropoff) {
                $shipPayload['dropoff'] = [
                    'branch_id' => $dropoff['branch_list'][0]['branch_id'] ?? 0,
                    'sender_real_name' => $dropoff['sender_real_name'] ?? '',
                ];
            } else {
                // Non-integrated logistics: just ship without extra params
                $shipPayload['non_integrated'] = [];
            }

            $shipResponse = $this->callApi('/api/v2/logistics/ship_order', $shipPayload);

            if (!empty($shipResponse['error']) && $shipResponse['error'] !== '') {
                Log::error('[ShopeeService] getShippingLabel step 2 falhou', ['order_sn' => $orderSn, 'error' => $shipResponse]);
                return null;
            }

            // Step 3: Create shipping document
            $createDocResponse = $this->callApi('/api/v2/logistics/create_shipping_document', array_merge($baseParams, [
                'order_list' => [
                    ['order_sn' => $orderSn],
                ],
            ]));

            if (!empty($createDocResponse['error']) && $createDocResponse['error'] !== '') {
                Log::error('[ShopeeService] getShippingLabel step 3 falhou', ['order_sn' => $orderSn, 'error' => $createDocResponse]);
                return null;
            }

            // Step 4: Poll until READY (max 10 attempts, 2s interval)
            $ready = false;
            for ($attempt = 0; $attempt < 10; $attempt++) {
                $statusResponse = $this->callApi('/api/v2/logistics/get_shipping_document_result', array_merge($baseParams, [
                    'order_list' => [
                        ['order_sn' => $orderSn],
                    ],
                ]));

                $resultList = $statusResponse['response']['result_list'] ?? [];
                $docStatus = $resultList[0]['status'] ?? '';

                if ($docStatus === 'READY') {
                    $ready = true;
                    break;
                }

                if ($docStatus === 'FAILED') {
                    Log::error('[ShopeeService] getShippingLabel step 4: documento FAILED', ['order_sn' => $orderSn, 'result' => $resultList]);
                    return null;
                }

                sleep(2);
            }

            if (!$ready) {
                Log::warning('[ShopeeService] getShippingLabel step 4: timeout aguardando READY', ['order_sn' => $orderSn]);
                return null;
            }

            // Step 5: Download shipping document
            $downloadResponse = $this->callApi('/api/v2/logistics/download_shipping_document', array_merge($baseParams, [
                'order_list' => [
                    ['order_sn' => $orderSn],
                ],
            ]));

            if (!empty($downloadResponse['error']) && $downloadResponse['error'] !== '') {
                Log::error('[ShopeeService] getShippingLabel step 5 falhou', ['order_sn' => $orderSn, 'error' => $downloadResponse]);
                return null;
            }

            // The response contains the shipping document URL or base64 data
            $fileUrl = $downloadResponse['response']['file']['url'] ?? null;
            $fileData = $downloadResponse['response']['file']['document'] ?? null;

            if ($fileUrl) {
                // Save the label to storage
                $contents = Http::get($fileUrl)->body();
                $path = "shipping-labels/shopee/{$orderSn}.pdf";
                \Storage::disk('local')->put($path, $contents);
                Log::info('[ShopeeService] getShippingLabel: etiqueta salva', ['order_sn' => $orderSn, 'path' => $path]);
                return $path;
            }

            if ($fileData) {
                $path = "shipping-labels/shopee/{$orderSn}.pdf";
                \Storage::disk('local')->put($path, base64_decode($fileData));
                Log::info('[ShopeeService] getShippingLabel: etiqueta salva (base64)', ['order_sn' => $orderSn, 'path' => $path]);
                return $path;
            }

            Log::warning('[ShopeeService] getShippingLabel: resposta sem arquivo', ['order_sn' => $orderSn, 'response' => $downloadResponse]);
            return null;

        } catch (\Exception $e) {
            Log::error('[ShopeeService] getShippingLabel: excecao', ['order_sn' => $orderSn, 'message' => $e->getMessage()]);
            return null;
        }
    }


    /**
     * Baixa a etiqueta de envio para um pedido (step 5 do fluxo, sem polling).
     * Chamado pelo ShopeeWebhookController quando evento code=11 SHIPPING_DOCUMENT_STATUS READY.
     *
     * @param MarketplaceAccount $account
     * @param string $orderSn
     * @return string|null  path local no Storage ou null em caso de falha
     */
    public function downloadShippingLabel(MarketplaceAccount $account, string $orderSn): ?string
    {
        $shopId      = $this->getShopId($account);
        $accessToken = $this->getValidAccessToken($account);

        if (! $shopId || ! $accessToken) {
            Log::warning('[ShopeeService] downloadShippingLabel: shop_id ou access_token ausente', ['order_sn' => $orderSn]);
            return null;
        }

        $partnerId  = (int) config('services.shopee.partner_id');
        $partnerKey = config('services.shopee.partner_key');
        $timestamp  = time();
        $path       = '/api/v2/logistics/download_shipping_document';
        $sign       = hash_hmac('sha256', $partnerId . $path . $timestamp . $accessToken . $shopId, $partnerKey);

        $baseParams = [
            'partner_id'   => $partnerId,
            'shop_id'      => $shopId,
            'access_token' => $accessToken,
            'timestamp'    => $timestamp,
            'sign'         => $sign,
        ];

        try {
            $response = $this->callDirect($path, array_merge($baseParams, [
                'order_list' => [['order_sn' => $orderSn]],
            ]));

            if (! empty($response['error']) && $response['error'] !== '') {
                Log::error('[ShopeeService] downloadShippingLabel: API erro', ['order_sn' => $orderSn, 'error' => $response]);
                return null;
            }

            $fileUrl  = $response['response']['file']['url']      ?? null;
            $fileData = $response['response']['file']['document'] ?? null;

            if ($fileUrl) {
                $contents = \Illuminate\Support\Facades\Http::get($fileUrl)->body();
                $labelPath = "shipping-labels/shopee/{$orderSn}.pdf";
                \Storage::disk('local')->put($labelPath, $contents);
                Log::info('[ShopeeService] downloadShippingLabel: salva via URL', ['order_sn' => $orderSn, 'path' => $labelPath]);
                return $labelPath;
            }

            if ($fileData) {
                $labelPath = "shipping-labels/shopee/{$orderSn}.pdf";
                \Storage::disk('local')->put($labelPath, base64_decode($fileData));
                Log::info('[ShopeeService] downloadShippingLabel: salva via base64', ['order_sn' => $orderSn, 'path' => $labelPath]);
                return $labelPath;
            }

            Log::warning('[ShopeeService] downloadShippingLabel: resposta sem arquivo', ['order_sn' => $orderSn]);
            return null;

        } catch (\Throwable $e) {
            Log::error('[ShopeeService] downloadShippingLabel: excecao', ['order_sn' => $orderSn, 'message' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getShippingLabelBatch(MarketplaceAccount $account, Collection $orders): Collection
    {
        return $orders->mapWithKeys(function (Order $order) use ($account) {
            return [$order->id => $this->getShippingLabel($account, $order)];
        });
    }

    /**
     * Unified API caller: routes through bridge (goolhub.io legacy) or direct Shopee API.
     */
    /**
     * INF-023 S-P0-3: metodo era chamado em publishProduct() mas nao existia.
     * Busca o primeiro canal de logistica habilitado da loja; fallback 90003 (Correios BR).
     */
    protected function getFirstEnabledLogistic(string $accessToken, int $shopId): array
    {
        try {
            $resp = $this->callApi('/api/v2/logistics/get_channel_list', [
                'shop_id'      => $shopId,
                'access_token' => $accessToken,
            ], 'GET');

            foreach ($resp['response']['logistics_channel_list'] ?? [] as $ch) {
                if (!empty($ch['enabled'])) {
                    return ['logistic_id' => (int) $ch['logistics_channel_id'], 'enabled' => true];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[ShopeeService] get_channel_list falhou — usando canal default 90003', [
                'shop_id' => $shopId,
                'error'   => $e->getMessage(),
            ]);
        }

        return ['logistic_id' => 90003, 'enabled' => true];
    }

    /**
     * SEL-311: recomenda categoria folha via /api/v2/product/category_recommend
     * (cache 24h por nome normalizado). Retorna null quando a API nao ajuda —
     * nesse caso o guard FOR-065 segue bloqueando com mensagem clara.
     */
    protected function recommendCategoryByName(string $itemName, ?string $accessToken, int|string|null $shopId): ?int
    {
        $itemName = trim(mb_substr($itemName, 0, 120));
        if ($itemName === '' || !$accessToken || !$shopId) {
            return null;
        }

        $cacheKey = 'shopee_cat_recommend:' . md5(mb_strtolower($itemName));
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return ((int) $cached) ?: null;
        }

        $result = $this->callApi('/api/v2/product/category_recommend', [
            'shop_id'      => $shopId,
            'access_token' => $accessToken,
            'item_name'    => $itemName,
        ], 'GET');

        $ids = $result['response']['category_id'] ?? [];
        $id  = is_array($ids) ? (int) ($ids[0] ?? 0) : (int) $ids;

        if ($id > 0) {
            Cache::put($cacheKey, $id, 86400);
            return $id;
        }

        Log::channel('marketplace')->warning('[ShopeeService] SEL-311 category_recommend sem resultado', [
            'item_name' => $itemName,
            'error'     => $result['error'] ?? null,
            'message'   => $result['message'] ?? ($result['msg'] ?? null),
        ]);
        return null;
    }

    protected function callApi(string $endpoint, array $params, string $method = 'POST'): array
    {
        if ($this->useBridge) {
            return $this->callViaBridge($endpoint, $params);
        }
        return $this->callDirect($endpoint, $params, $method);
    }

    /**
     * Call Shopee API via the goolhub.io bridge (legacy K3s cluster).
     * The bridge handles auth signatures using its own approved Shopee app credentials.
     */
    protected function callViaBridge(string $endpoint, array $params): array
    {
        try {
            $response = Http::timeout(30)
                ->withHeaders(['X-Bridge-Secret' => $this->bridgeSecret])
                ->post($this->bridgeUrl, [
                    'endpoint' => $endpoint,
                    'params' => json_encode($params),
                    'shop_id' => $params['shop_id'] ?? null,
                ]);

            if ($response->failed()) {
                Log::error('[ShopeeService] callViaBridge: HTTP error', [
                    'endpoint' => $endpoint,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return ['error' => 'bridge_http_error', 'message' => $response->body()];
            }

            return $response->json() ?? [];
        } catch (\Exception $e) {
            Log::error('[ShopeeService] callViaBridge: excecao', [
                'endpoint' => $endpoint,
                'message' => $e->getMessage(),
            ]);
            return ['error' => 'bridge_exception', 'message' => $e->getMessage()];
        }
    }

    /**
     * Call Shopee API directly (when HubAI has its own approved app).
     *
     * NOV-061 (Bug 2): se a Shopee retornar invalid_acceess_token (typo deles),
     * tentamos UM retry forcando refresh do access_token. Evita falhas transitorias
     * onde o token "valido" no DB foi invalidado server-side pela Shopee.
     */
    public function callDirect(string $endpoint, array $params, string $method = 'POST', bool $retried = false): array
    {
        $shopId = $params['shop_id'] ?? null;
        $accessToken = $params['access_token'] ?? null;

        // Remove auth params from payload (they go in query string)
        $payload = collect($params)->except(['shop_id', 'access_token'])->toArray();

        $timestamp = time();
        $sign = $this->buildRequestSignature($endpoint, $timestamp, $accessToken ?? '', $shopId ?? 0);

        $queryParams = http_build_query([
            'partner_id' => $this->partnerId,
            'timestamp' => $timestamp,
            'access_token' => $accessToken,
            'shop_id' => $shopId,
            'sign' => $sign,
        ]);

        $url = "{$this->baseUrl}" . str_replace('/api/v2', '', $endpoint) . "?{$queryParams}";

        try {
            $http = Http::timeout(30);

            if (strtoupper($method) === 'GET') {
                if (!empty($payload)) {
                    $url .= '&' . http_build_query($payload);
                }
                $response = $http->get($url);
            } else {
                $response = $http->post($url, $payload);
            }

            if ($response->failed()) {
                // FOR-066: checar error_kyc_auth antes de retornar direct_http_error generico
                $bodyRaw    = $response->body();
                $bodyDecoded = json_decode($bodyRaw, true);
                $httpErr    = $bodyDecoded['error'] ?? '';
                if ($httpErr === 'error_kyc_auth') {
                    Log::error('[ShopeeService] callDirect: error_kyc_auth detectado', [
                        'endpoint' => $endpoint,
                        'shop_id'  => $shopId,
                        'body'     => $bodyRaw,
                    ]);

                    // SEL-397: a conta ficava "active" no painel enquanto a Shopee recusava
                    // TODOS os pedidos por KYC pendente. O cliente achava que estava integrado
                    // e nao entrava nada — caso alexdona7@gmail.com, 7 dias e 0 pedidos.
                    try {
                        $conta = \App\Models\MarketplaceAccount::where('shop_id', $shopId)
                            ->where('platform', 'shopee')
                            ->first();
                        if ($conta && ! \App\Services\Integrations\PendenciaContaService::ehKyc($conta->status)) {
                            $conta->update(['status' => \App\Services\Integrations\PendenciaContaService::KYC_CANONICO]);
                            Log::warning('[SEL-397] conta marcada como kyc_pendente', [
                                'account_id' => $conta->id,
                                'client_id'  => $conta->client_id,
                                'shop_id'    => $shopId,
                            ]);
                        }
                    } catch (\Throwable $e) {
                        Log::warning('[SEL-397] nao consegui marcar a conta', ['erro' => $e->getMessage()]);
                    }

                    return ['error' => 'error_kyc_auth', 'message' => $bodyDecoded['message'] ?? $bodyRaw];
                }
                Log::error('[ShopeeService] callDirect: HTTP error', [
                    'endpoint' => $endpoint,
                    'status' => $response->status(),
                    'body' => $bodyRaw,
                ]);
                return ['error' => 'direct_http_error', 'message' => $bodyRaw];
            }

            $body = $response->json() ?? [];

            // NOV-061 Bug 2: retry uma vez se invalid_acceess_token (typo da Shopee, vale tambem invalid_access_token)
            $shopeeErr = $body['error'] ?? '';
            if (!$retried && $shopId && in_array($shopeeErr, ['invalid_acceess_token', 'invalid_access_token'], true)) {
                Log::warning('[ShopeeService] callDirect: token invalido, tentando refresh+retry', [
                    'endpoint' => $endpoint,
                    'shop_id' => $shopId,
                    'shopee_error' => $shopeeErr,
                ]);
                $account = \App\Models\MarketplaceAccount::where('platform', 'shopee')
                    ->where('shop_id', $shopId)
                    ->first();
                if ($account) {
                    $newToken = $this->refreshToken($account);
                    if ($newToken) {
                        return $this->callDirect(
                            $endpoint,
                            array_merge($params, ['access_token' => $newToken]),
                            $method,
                            true
                        );
                    }
                }
            }

            return $body;
        } catch (\Exception $e) {
            Log::error('[ShopeeService] callDirect: excecao', [
                'endpoint' => $endpoint,
                'message' => $e->getMessage(),
            ]);
            return ['error' => 'direct_exception', 'message' => $e->getMessage()];
        }
    }

    /**
     * GET /api/v2/payment/get_escrow_detail
     * Retorna os detalhes do repasse Shopee (escrow release) para um pedido.
     * Disponivel apos o pedido ficar COMPLETED no Shopee.
     */
    public function getEscrowDetail(MarketplaceAccount $account, string $orderSn): array
    {
        $shopId      = $this->getShopId($account);
        $accessToken = $this->getValidAccessToken($account);

        if (!$shopId || !$accessToken) {
            return ['error' => 'missing_credentials'];
        }

        $response = $this->callApi('/api/v2/payment/get_escrow_detail', [
            'order_sn'     => $orderSn,
            'shop_id'      => $shopId,
            'access_token' => $accessToken,
        ], 'GET');

        if (!empty($response['error'])) {
            \Log::warning('[ShopeeService] getEscrowDetail falhou', [
                'order_sn'   => $orderSn,
                'account_id' => $account->id,
                'response'   => $response,
            ]);
        }

        return $response;
    }


    /**
     * Busca lista de pedidos Shopee por intervalo de tempo.
     * Wrapper publico sobre callApi para uso em SyncShopeeOrdersJob e outros consumers.
     *
     * @param int    $shopId
     * @param string $accessToken
     * @param int    $timeFrom   unix timestamp
     * @param int    $timeTo     unix timestamp
     * @param int    $pageSize   max 50
     * @return array  resposta raw da API Shopee
     */
    /**
     * Busca detalhes de ate 50 pedidos Shopee em lote.
     *
     * @param int    $shopId
     * @param string $accessToken
     * @param array  $orderSns  lista de order_sn
     * @return array resposta raw da API Shopee
     */
    public function getOrderDetail(int $shopId, string $accessToken, array $orderSns): array
    {
        return $this->callApi('/api/v2/order/get_order_detail', [
            'order_sn_list'            => implode(',', $orderSns),
            // MUL-197: + pay_time/create_time/buyer_user_id (enriquecimento de rascunho precisa de paid_at)
            'response_optional_fields' => 'buyer_username,buyer_user_id,item_list,total_amount,order_status,tracking_no,shipping_carrier,package_list,recipient_address,pay_time,create_time',
            'shop_id'                  => $shopId,
            'access_token'             => $accessToken,
        ], 'GET');
    }

        public function getOrderList(int $shopId, string $accessToken, int $timeFrom, int $timeTo, int $pageSize = 50): array
    {
        return $this->callApi('/api/v2/order/get_order_list', [
            'time_range_field'        => 'create_time',
            'time_from'               => $timeFrom,
            'time_to'                 => $timeTo,
            'page_size'               => $pageSize,
            'cursor'                  => '',
            'response_optional_fields' => 'order_status,buyer_username,total_amount',
            'shop_id'                 => $shopId,
            'access_token'            => $accessToken,
        ], 'GET');
    }


    /**
     * Busca detalhes de um item/anuncio na Shopee e normaliza para o contrato unificado.
     * Endpoint: GET /api/v2/product/get_item_base_info
     *
     * Contrato unificado retornado:
     *   title       <- item_name
     *   description <- description
     *   images[]    <- images.image_url_list[]
     *   video_url   <- video_info.video_url (null se indisponivel)
     *   price       <- price_info[0].current_price
     *   stock       <- stock_info_v2.summary_info.total_available_stock
     *   attributes  <- attribute_list (mapa name->value)
     *   score       <- null (Shopee BR nao expoe quality score via API Partner v2)
     *   _raw        <- dados brutos Shopee para uso interno do controller
     */
    public function fetchItemDetail(MarketplaceAccount $account, int $itemId): array
    {
        $shopId      = $this->getShopId($account);
        $accessToken = $this->getValidAccessToken($account);

        if (!$shopId || !$accessToken) {
            return ['error' => 'auth_failed', 'message' => 'Token Shopee invalido ou expirado. Reconecte a conta.'];
        }

        // Shopee v2 usa get_item_base_info com item_id_list (nao get_item_detail)
        $result = $this->callApi('/api/v2/product/get_item_base_info', [
            'item_id_list' => $itemId,
            'shop_id'      => $shopId,
            'access_token' => $accessToken,
        ], 'GET');

        if (!empty($result['error']) && $result['error'] !== '') {
            return ['error' => $result['error'], 'message' => $result['message'] ?? 'Erro ao buscar anuncio na Shopee.'];
        }

        $itemList = $result['response']['item_list'] ?? [];
        if (empty($itemList)) {
            return ['error' => 'not_found', 'message' => 'Anuncio nao encontrado na Shopee.'];
        }

        $raw = $itemList[0];

        // Imagens: Shopee retorna images.image_url_list[]
        $images = $raw['images']['image_url_list'] ?? [];

        // Preco: Shopee retorna price_info[] (primeiro elemento = produto simples)
        $price = 0.0;
        $priceInfo = $raw['price_info'] ?? [];
        if (!empty($priceInfo)) {
            // current_price = preco com desconto; original_price = preco cheio
            $price = (float) ($priceInfo[0]['current_price'] ?? $priceInfo[0]['original_price'] ?? 0.0);
        }

        // Estoque: Shopee v2 usa stock_info_v2.summary_info.total_available_stock
        $stock = 0;
        $stockSummary = $raw['stock_info_v2']['summary_info'] ?? [];
        if (!empty($stockSummary)) {
            $stock = (int) ($stockSummary['total_available_stock'] ?? 0);
        } else {
            // Fallback: stock_info legado
            $stockInfo = $raw['stock_info'] ?? [];
            if (!empty($stockInfo)) {
                $stock = (int) ($stockInfo[0]['normal_stock'] ?? 0);
            }
        }

        // Video: disponivel em alguns mercados como video_info.video_url
        $videoUrl = $raw['video_info']['video_url'] ?? null;

        // Atributos: Shopee retorna attribute_list[] com {attribute_id, attribute_name, attribute_value_list[]}
        $attributes = [];
        foreach ($raw['attribute_list'] ?? [] as $attr) {
            $attrKey   = $attr['attribute_name'] ?? (string) ($attr['attribute_id'] ?? 'unknown');
            $attrValue = $attr['attribute_value_list'][0]['value'] ?? null;
            if ($attrKey && $attrValue !== null) {
                $attributes[$attrKey] = $attrValue;
            }
        }

        // Score: Shopee BR nao expoe quality score via API Partner v2.
        // O PublicApiController calcula score internamente (completude do anuncio).
        $score = null;

        return [
            'title'       => $raw['item_name'] ?? '',
            'description' => $raw['description'] ?? '',
            'images'      => $images,
            'video_url'   => $videoUrl,
            'price'       => $price,
            'stock'       => $stock,
            'attributes'  => $attributes,
            'score'       => $score,
            '_raw'        => $raw,
        ];
    }

    /**
     * Atualiza um item na Shopee a partir do contrato unificado do PublicApiController.
     *
     * A Shopee exige 3 chamadas separadas (ao contrario do ML que usa 1 PUT):
     *   1. /product/update_item  - titulo, descricao, imagens, atributos
     *   2. /product/update_price - preco (endpoint dedicado obrigatorio)
     *   3. /product/update_stock - estoque (endpoint dedicado obrigatorio)
     *
     * @param array  $images     image_id Shopee pre-carregados via media_space/upload_image
     * @param int|null $stock    null = nao atualiza estoque
     * @param array  $attributes mapa {attribute_id_numerico => valor}
     * @return array {update_item, update_price, update_stock, errors[]}
     */
    public function updateItemDetail(
        MarketplaceAccount $account,
        int $itemId,
        string $title,
        string $description,
        float $price,
        ?string $status = null,
        array $images = [],
        ?int $stock = null,
        array $attributes = []
    ): array {
        $shopId      = $this->getShopId($account);
        $accessToken = $this->getValidAccessToken($account);

        if (!$shopId || !$accessToken) {
            return ['error' => 'auth_failed', 'message' => 'Token Shopee invalido ou expirado. Reconecte a conta.'];
        }

        $errors  = [];
        $results = [];

        // ---------------------------------------------------------------
        // 0. Status (unlist/relist) - executar ANTES do update_item
        //    para evitar erro de item inativo ao atualizar campos
        // ---------------------------------------------------------------
        if ($status === 'paused') {
            $this->callApi('/api/v2/product/unlist_item', [
                'item_list'    => [['item_id' => $itemId, 'unlist' => true]],
                'shop_id'      => $shopId,
                'access_token' => $accessToken,
            ]);
        } elseif ($status === 'active') {
            $this->callApi('/api/v2/product/unlist_item', [
                'item_list'    => [['item_id' => $itemId, 'unlist' => false]],
                'shop_id'      => $shopId,
                'access_token' => $accessToken,
            ]);
        }

        // ---------------------------------------------------------------
        // 1. update_item - titulo, descricao, imagens, atributos
        // ---------------------------------------------------------------
        $updatePayload = [
            'item_id'      => $itemId,
            'shop_id'      => $shopId,
            'access_token' => $accessToken,
        ];

        if (!empty($title)) {
            $updatePayload['item_name'] = mb_substr($title, 0, 120);
        }
        if (!empty($description)) {
            $updatePayload['description'] = $description;
        }
        if (!empty($images)) {
            // $images deve conter image_id strings pre-carregados via media_space/upload_image
            $updatePayload['image'] = ['image_id_list' => array_values($images)];
        }
        if (!empty($attributes)) {
            $attrList = [];
            foreach ($attributes as $attrId => $attrValue) {
                $attrList[] = [
                    'attribute_id'         => (int) $attrId,
                    'attribute_value_list' => [['value' => (string) $attrValue]],
                ];
            }
            if (!empty($attrList)) {
                $updatePayload['attribute_list'] = $attrList;
            }
        }

        $updateResult = $this->callApi('/api/v2/product/update_item', $updatePayload);
        $results['update_item'] = $updateResult['response'] ?? $updateResult;

        if (!empty($updateResult['error']) && $updateResult['error'] !== '') {
            $errors[] = 'update_item: ' . ($updateResult['message'] ?? $updateResult['error']);
            Log::warning('[ShopeeService] updateItemDetail: update_item falhou', [
                'item_id' => $itemId,
                'error'   => $updateResult['error'],
                'message' => $updateResult['message'] ?? '',
            ]);
        }

        // ---------------------------------------------------------------
        // 2. update_price - Shopee exige endpoint dedicado para preco
        //    model_id=0 = produto simples (sem variantes)
        // ---------------------------------------------------------------
        if ($price > 0) {
            $priceResult = $this->callApi('/api/v2/product/update_price', [
                'item_id'      => $itemId,
                'price_list'   => [['model_id' => 0, 'original_price' => $price]],
                'shop_id'      => $shopId,
                'access_token' => $accessToken,
            ]);
            $results['update_price'] = $priceResult['response'] ?? $priceResult;

            if (!empty($priceResult['error']) && $priceResult['error'] !== '') {
                $errors[] = 'update_price: ' . ($priceResult['message'] ?? $priceResult['error']);
                Log::warning('[ShopeeService] updateItemDetail: update_price falhou', [
                    'item_id' => $itemId,
                    'price'   => $price,
                    'error'   => $priceResult['error'],
                ]);
            }
        }

        // ---------------------------------------------------------------
        // 3. update_stock - Shopee exige endpoint dedicado para estoque
        //    model_id=0 = produto simples (sem variantes)
        // ---------------------------------------------------------------
        if ($stock !== null) {
            $stockResult = $this->callApi('/api/v2/product/update_stock', [
                'item_id'    => $itemId,
                'stock_list' => [['model_id' => 0, 'normal_stock' => max(0, $stock)]],
                'shop_id'    => $shopId,
                'access_token' => $accessToken,
            ]);
            $results['update_stock'] = $stockResult['response'] ?? $stockResult;

            if (!empty($stockResult['error']) && $stockResult['error'] !== '') {
                $errors[] = 'update_stock: ' . ($stockResult['message'] ?? $stockResult['error']);
                Log::warning('[ShopeeService] updateItemDetail: update_stock falhou', [
                    'item_id' => $itemId,
                    'stock'   => $stock,
                    'error'   => $stockResult['error'],
                ]);
            }
        }

        Log::info('[ShopeeService] updateItemDetail: concluido', [
            'item_id' => $itemId,
            'errors'  => $errors ?: null,
            'steps'   => array_keys($results),
        ]);

        if (!empty($errors) && !empty($updateResult['error']) && $updateResult['error'] !== '') {
            return ['error' => implode('; ', $errors), 'message' => 'Atualizacao na Shopee falhou.', 'results' => $results];
        }

        return array_merge($results, ['errors' => $errors]);
    }


    /**
     * Faz upload de imagens por URL para a Shopee (sem depender do model Product).
     * Retorna array de image_id strings. Limite: 9 imagens (maximo Shopee).
     */
    public function uploadImageFromUrls(string $accessToken, int $shopId, array $urls): array
    {
        $imageIds = [];
        $urls     = array_filter($urls);
        $limit    = 9; // Shopee suporta max 9 imagens por item

        foreach (array_slice($urls, 0, $limit) as $imageUrl) {
            $imageContent = @file_get_contents($imageUrl);
            if (!$imageContent) {
                Log::warning('[ShopeeService] uploadImageFromUrls: falha ao baixar imagem', ['url' => $imageUrl]);
                continue;
            }

            // Detectar e converter WebP para JPEG (Shopee nao aceita WebP)
            $tmpFile = tempnam(sys_get_temp_dir(), 'shopee_img_') . '.jpg';
            $isWebp = (
                str_ends_with(strtolower($imageUrl), '.webp') ||
                (strlen($imageContent) > 4 && substr($imageContent, 0, 4) === 'RIFF')
            );
            if ($isWebp && function_exists('imagecreatefromwebp')) {
                $tmpWebp = tempnam(sys_get_temp_dir(), 'shopee_webp_') . '.webp';
                file_put_contents($tmpWebp, $imageContent);
                $gdImg = @imagecreatefromwebp($tmpWebp);
                @unlink($tmpWebp);
                if ($gdImg) {
                    imagejpeg($gdImg, $tmpFile, 85);
                    imagedestroy($gdImg);
                } else {
                    file_put_contents($tmpFile, $imageContent);
                }
            } else {
                file_put_contents($tmpFile, $imageContent);
            }

            $path      = '/api/v2/media_space/upload_image';
            $timestamp = time();
            $baseString = $this->partnerId . $path . $timestamp . $accessToken . $shopId;
            $sign      = hash_hmac('sha256', $baseString, $this->partnerKey);
            $queryString = http_build_query([
                'partner_id'   => $this->partnerId,
                'timestamp'    => $timestamp,
                'access_token' => $accessToken,
                'shop_id'      => $shopId,
                'sign'         => $sign,
            ]);
            $url = "{$this->baseUrl}/media_space/upload_image?{$queryString}";

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_POSTFIELDS, [
                'image' => new \CURLFile($tmpFile, 'image/jpeg', 'product.jpg'),
            ]);
            $responseBody = curl_exec($ch);
            curl_close($ch);
            @unlink($tmpFile);

            $result        = json_decode($responseBody, true) ?? [];
            $shopeeImageId = $result['response']['image_info']['image_id'] ?? null;

            if ($shopeeImageId) {
                $imageIds[] = $shopeeImageId;
                Log::info('[ShopeeService] uploadImageFromUrls: imagem enviada', [
                    'url'             => $imageUrl,
                    'shopee_image_id' => $shopeeImageId,
                ]);
            } else {
                Log::warning('[ShopeeService] uploadImageFromUrls: falha ao enviar imagem', [
                    'url'     => $imageUrl,
                    'error'   => $result['error'] ?? 'unknown',
                    'message' => $result['message'] ?? '',
                ]);
            }
        }

        return $imageIds;
    }

    /**
     * Publica um novo produto na Shopee via /api/v2/product/add_item.
     * Nao depende do model Product — recebe array com campos normalizados.
     *
     * @param array $product {
     *   title, description, price, category_id, pictures, sku_codigo,
     *   available_quantity, condition
     * }
     */
    public function publishProduct(MarketplaceAccount $account, array $product): array
    {
        // Guard NOV-156: nao publicar em conta pending/fantasma (OAuth abandonado).
        if ($account->status === 'pending' || empty($account->seller_id)) {
            Log::warning('[ShopeeService] publishProduct: conta pending/fantasma ignorada', [
                'account_id' => $account->id,
                'client_id'  => $account->client_id,
                'status'     => $account->status,
                'seller_id'  => $account->seller_id,
            ]);
            return ['error' => 'account_pending_reauth', 'message' => 'Conexao Shopee incompleta. Reconecte a conta para publicar.'];
        }

        $shopId      = $this->getShopId($account);
        $accessToken = $this->getValidAccessToken($account);

        if (!$shopId || !$accessToken) {
            return ['error' => 'auth_failed', 'message' => 'Token Shopee invalido ou expirado. Reconecte a conta.'];
        }

        // Upload das imagens por URL
        $imageIds = [];
        if (!empty($product['pictures'])) {
            $imageIds = $this->uploadImageFromUrls($accessToken, $shopId, $product['pictures']);
        }

        $payload = [
            'shop_id'        => $shopId,
            'access_token'   => $accessToken,
            'original_price' => round((float) $product['price'], 2),
            'description'    => mb_substr($product['description'] ?? $product['title'], 0, 3000),
            'item_name'      => mb_substr($product['title'], 0, 120),
            'item_sku'       => $product['sku_codigo'] ?? '',
            'weight'         => 0.5,
            'seller_stock'   => [['stock' => max(1, (int) ($product['available_quantity'] ?? 1))]],
            'logistic_info'  => [$this->getFirstEnabledLogistic($accessToken, $shopId)],
            'dimension'      => ['package_length' => 15, 'package_width' => 10, 'package_height' => 5],
            'category_id'    => (int) ($product['category_id'] ?? 100001),
            'condition'      => 'NEW',
            'brand'          => ['brand_id' => 0, 'original_brand_name' => 'NoBrand'],
        ];

        if (!empty($imageIds)) {
            $payload['image'] = ['image_id_list' => $imageIds];
        }

        Log::info('[ShopeeService] publishProduct: publicando produto', [
            'shop_id'  => $shopId,
            'title'    => $payload['item_name'],
            'category' => $payload['category_id'],
            'images'   => count($imageIds),
        ]);

        return $this->callDirect('/api/v2/product/add_item', $payload, 'POST');
    }

    /**
     * Baixa o documento de envio THERMAL_AIR_WAYBILL como ZIP binario bruto.
     * Usado pelo ShippingLabelService para extrair ZPL e converter em PNG.
     *
     * Diferente de downloadShippingLabel(), este metodo retorna o corpo binario
     * do ZIP sem tentar parsear JSON -- necessario pois a Shopee retorna ZIP
     * quando shipping_document_type=THERMAL_AIR_WAYBILL.
     *
     * @param  MarketplaceAccount $account
     * @param  string             $orderSn
     * @return string|null  Conteudo binario do ZIP ou null em falha
     */
    public function downloadShippingDocumentRaw(MarketplaceAccount $account, string $orderSn, ?string $packageNumber = null): ?string
    {
        $shopId      = $this->getShopId($account);
        $accessToken = $this->getValidAccessToken($account);

        if (! $shopId || ! $accessToken) {
            Log::warning('[ShopeeService] downloadShippingDocumentRaw: shop_id ou access_token ausente', [
                'order_sn' => $orderSn,
            ]);
            return null;
        }

        $partnerId  = (int) $this->partnerId;
        $partnerKey = $this->partnerKey;
        $timestamp  = time();
        $path       = '/api/v2/logistics/download_shipping_document';
        $sign       = hash_hmac('sha256', $partnerId . $path . $timestamp . $accessToken . $shopId, $partnerKey);

        $queryString = http_build_query([
            'partner_id'   => $partnerId,
            'timestamp'    => $timestamp,
            'access_token' => $accessToken,
            'shop_id'      => $shopId,
            'sign'         => $sign,
        ]);

        $url = "{$this->baseUrl}/logistics/download_shipping_document?{$queryString}";

        $body = [
            'order_list'             => [['order_sn' => $orderSn, 'package_number' => $packageNumber]],
            'shipping_document_type' => 'THERMAL_AIR_WAYBILL',
        ];

        try {
            $response = Http::timeout(60)
                ->withHeaders(['Accept' => 'application/octet-stream, application/zip, */*'])
                ->post($url, $body);

            if ($response->failed()) {
                Log::error('[ShopeeService] downloadShippingDocumentRaw: HTTP erro', [
                    'order_sn' => $orderSn,
                    'status'   => $response->status(),
                    'body'     => mb_substr($response->body(), 0, 500),
                ]);
                return null;
            }

            $rawBody = $response->body();

            // Detecta se a Shopee retornou JSON de erro em vez do ZIP
            if (strlen($rawBody) < 500 || str_starts_with(ltrim($rawBody), '{')) {
                $decoded = json_decode($rawBody, true);
                if (is_array($decoded) && ! empty($decoded['error']) && $decoded['error'] !== '') {
                    Log::error('[ShopeeService] downloadShippingDocumentRaw: API erro JSON', [
                        'order_sn' => $orderSn,
                        'error'    => $decoded,
                    ]);
                    return null;
                }
            }

            Log::info('[ShopeeService] downloadShippingDocumentRaw: ZIP recebido', [
                'order_sn' => $orderSn,
                'bytes'    => strlen($rawBody),
            ]);

            return $rawBody;

        } catch (\Throwable $e) {
            Log::error('[ShopeeService] downloadShippingDocumentRaw: excecao', [
                'order_sn' => $orderSn,
                'error'    => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Cria o documento de envio THERMAL_AIR_WAYBILL para um pedido.
     * Retorna true se criado com sucesso (o status READY sera notificado via webhook code=15
     * ou verificado via getShippingDocumentStatus).
     *
     * @param  MarketplaceAccount $account
     * @param  string             $orderSn
     * @return bool
     */
    /**
     * SEL-413: passou a devolver array em vez de bool.
     *
     * A Shopee responde a falha em DOIS lugares: 'error' no topo (ex.:
     * common.batch_api_all_failed) e o motivo real dentro de
     * response.result_list[0].fail_error. Como o retorno era bool, esse motivo real
     * morria no log e o seller via sempre "marketplace processando" — inclusive para
     * encomenda ja despachada, que nunca vai liberar etiqueta.
     *
     * @return array{ok: bool, error: ?string, message: ?string}
     */
    public function createShippingDocument(MarketplaceAccount $account, string $orderSn, ?string $packageNumber = null): array
    {
        $shopId      = $this->getShopId($account);
        $accessToken = $this->getValidAccessToken($account);

        if (! $shopId || ! $accessToken) {
            Log::warning('[ShopeeService] createShippingDocument: shop_id ou access_token ausente', [
                'order_sn' => $orderSn,
            ]);
            return ['ok' => false, 'error' => 'missing_credentials', 'message' => null];
        }

        $response = $this->callDirect('/api/v2/logistics/create_shipping_document', [
            'shop_id'                => $shopId,
            'access_token'           => $accessToken,
            'order_list'             => [['order_sn' => $orderSn, 'package_number' => $packageNumber]],
            'shipping_document_type' => 'THERMAL_AIR_WAYBILL',
        ]);

        // SEL-413: o motivo util vem no result_list, nao no 'error' do topo.
        $item        = $response['response']['result_list'][0] ?? [];
        $failError   = $item['fail_error']   ?? null;
        $failMessage = $item['fail_message'] ?? null;
        $topError    = (! empty($response['error']) && $response['error'] !== '') ? $response['error'] : null;

        if ($failError || $topError) {
            Log::error('[ShopeeService] createShippingDocument: API erro', [
                'order_sn'     => $orderSn,
                'fail_error'   => $failError,
                'fail_message' => $failMessage,
                'top_error'    => $topError,
            ]);
            return ['ok' => false, 'error' => $failError ?: $topError, 'message' => $failMessage];
        }

        Log::info('[ShopeeService] createShippingDocument: solicitado', [
            'order_sn' => $orderSn,
            'response' => $response['response'] ?? [],
        ]);

        return ['ok' => true, 'error' => null, 'message' => null];
    }

    /**
     * Verifica status do documento de envio via get_shipping_document_result.
     * Retorna 'READY', 'FAILED', 'PROCESSING' ou null em erro de API.
     *
     * @param  MarketplaceAccount $account
     * @param  string             $orderSn
     * @return string|null
     */
    public function getShippingDocumentStatus(MarketplaceAccount $account, string $orderSn, ?string $packageNumber = null): ?string
    {
        $shopId      = $this->getShopId($account);
        $accessToken = $this->getValidAccessToken($account);

        if (! $shopId || ! $accessToken) {
            return null;
        }

        $response = $this->callDirect('/api/v2/logistics/get_shipping_document_result', [
            'shop_id'      => $shopId,
            'access_token' => $accessToken,
            // MUL-354: com package_number null a Shopee nao localiza a remessa e
            // responde logistics.shipping_document_should_print_first.
            'order_list'   => [['order_sn' => $orderSn, 'package_number' => $packageNumber]],
        ]);

        if (! empty($response['error']) && $response['error'] !== '') {
            Log::warning('[ShopeeService] getShippingDocumentStatus: API erro', [
                'order_sn' => $orderSn,
                'error'    => $response,
            ]);
            return null;
        }

        $resultList = $response['response']['result_list'] ?? [];
        return $resultList[0]['status'] ?? null;
    }



    /**
     * NOV-081: Pausa (deslista) um produto no Shopee por estoque zero.
     * API Shopee: POST /api/v2/product/unlist_item com unlist=true
     * Reutiliza a logica ja existente em updateItemDetail (linha 1176).
     */
    public function pauseItem(MarketplaceAccount $account, string $itemId): bool
    {
        $shopId      = $this->getShopId($account);
        $accessToken = $this->getValidAccessToken($account);

        if (!$shopId) {
            return ['error' => 'config_error', 'message' => 'Shop ID nao configurado nesta conta Shopee. Reconecte a conta.'];
        }
        if (!$accessToken) {
            return ['error' => 'token_error', 'message' => 'Token Shopee ausente ou expirado. Reconecte a conta.'];
        }

        $result = $this->callApi('/api/v2/product/unlist_item', [
            'item_list'    => [['item_id' => (int) $itemId, 'unlist' => true]],
            'shop_id'      => $shopId,
            'access_token' => $accessToken,
        ]);

        if (!empty($result['error']) && $result['error'] !== '') {
            Log::warning('[ShopeeService] pauseItem falhou', [
                'account_id' => $account->id,
                'item_id'    => $itemId,
                'error'      => $result,
            ]);
            return false;
        }

        return true;
    }

    /**
     * NOV-081: Reativa (relista) um produto no Shopee apos reposicao de estoque.
     * API Shopee: POST /api/v2/product/unlist_item com unlist=false
     */
    public function activateItem(MarketplaceAccount $account, string $itemId): bool
    {
        $shopId      = $this->getShopId($account);
        $accessToken = $this->getValidAccessToken($account);

        if (!$shopId) {
            return ['error' => 'config_error', 'message' => 'Shop ID nao configurado nesta conta Shopee. Reconecte a conta.'];
        }
        if (!$accessToken) {
            return ['error' => 'token_error', 'message' => 'Token Shopee ausente ou expirado. Reconecte a conta.'];
        }

        $result = $this->callApi('/api/v2/product/unlist_item', [
            'item_list'    => [['item_id' => (int) $itemId, 'unlist' => false]],
            'shop_id'      => $shopId,
            'access_token' => $accessToken,
        ]);

        if (!empty($result['error']) && $result['error'] !== '') {
            Log::warning('[ShopeeService] activateItem falhou', [
                'account_id' => $account->id,
                'item_id'    => $itemId,
                'error'      => $result,
            ]);
            return false;
        }

        return true;
    }

    public function updatePriceOnly(MarketplaceAccount $account, int $itemId, float $price): bool
    {
        $shopId      = $this->getShopId($account);
        $accessToken = $this->getValidAccessToken($account);

        if (!$shopId || !$accessToken) {
            Log::warning('[ShopeeService] updatePriceOnly: auth falhou', ['account_id' => $account->id]);
            return false;
        }

        $result = $this->callApi('/api/v2/product/update_price', [
            'item_id'      => $itemId,
            'price_list'   => [['model_id' => 0, 'original_price' => $price]],
            'shop_id'      => $shopId,
            'access_token' => $accessToken,
        ]);

        if (!empty($result['error']) && $result['error'] !== '') {
            Log::warning('[ShopeeService] updatePriceOnly falhou', [
                'item_id' => $itemId,
                'price'   => $price,
                'error'   => $result['error'],
            ]);
            return false;
        }

        Log::info('[ShopeeService] updatePriceOnly OK', ['item_id' => $itemId, 'price' => $price]);
        return true;
    }

    // ---------------------------------------------------------------
    // MUL-095: Shop Info (Selo Indicado)
    // ---------------------------------------------------------------

    /**
     * MUL-095: Busca informacoes da loja Shopee incluindo tier (Selo Indicado).
     *
     * Endpoint: GET /api/v2/shop/get_shop_info
     * Documentacao: https://open.shopee.com/documents/v2/v2.shop.get_shop_info
     *
     * Campos relevantes da resposta:
     *   shop_id, shop_name, status, description, shop_logo,
     *   item_list, images, is_preferred_seller, tier_variation_status
     *
     * NOTA sobre "Selo Indicado" Shopee Brasil:
     * A API Shopee NAO expoe explicitamente o tier do programa Indicado/Preferido/Platinum
     * via get_shop_info. O campo `is_preferred_seller` indica apenas "Preferido".
     * Aproximacao implementada:
     *   - is_preferred_seller = true → tier = 'preferred'
     *   - shop_level = 'GOLD' ou similar → tier = 'indicated'
     *   - Demais → tier = 'normal'
     *
     * Retorna array com:
     *   shop_tier    string  'normal'|'indicated'|'preferred'|'platinum'
     *   is_indicated bool    true se tier for 'indicated' ou superior
     *   raw          array   resposta bruta da API (para depuracao)
     */
    public function getShopInfo(MarketplaceAccount $account): array
    {
        $shopId      = $this->getShopId($account);
        $accessToken = $this->getValidAccessToken($account);

        if (! $shopId || ! $accessToken) {
            return ['shop_tier' => null, 'is_indicated' => false, 'error' => 'missing_credentials'];
        }

        // SEL-423: a URL saia com /api/v2 DUPLICADO —
        //   baseUrl  = https://partner.shopeemobile.com/api/v2
        //   endpoint = /api/v2/shop/get_shop_info
        // e o metodo devolvia 'error_not_found' pra TODA conta, inclusive as
        // saudaveis. Conferido em 30/07 em 3 contas ativas + 2 bloqueadas: as 5
        // davam error_not_found. Quem depende disso e o syncShopTierForAllAccounts,
        // agendado todo dia 04:30 (MUL-095, "Selo Indicado") — ou seja, o job rodava,
        // nao acusava erro visivel, e nunca trouxe dado nenhum.
        //
        // A assinatura sempre esteve certa: ela usa o PATH (/api/v2/...), que e o que
        // o Shopee manda assinar. Errada estava so a concatenacao da URL.
        $endpoint  = '/api/v2/shop/get_shop_info';
        $timestamp = time();
        $sign      = $this->buildRequestSignature($endpoint, $timestamp, $accessToken, $shopId);

        $url = self::HOST . $endpoint . '?' . http_build_query([
            'partner_id'   => $this->partnerId,
            'timestamp'    => $timestamp,
            'access_token' => $accessToken,
            'shop_id'      => $shopId,
            'sign'         => $sign,
        ]);

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(15)->get($url);
            $body     = $response->json() ?? [];

            if (! empty($body['error'])) {
                \Illuminate\Support\Facades\Log::warning('[ShopeeService] getShopInfo error', [
                    'account_id' => $account->id,
                    'shopee_err' => $body['error'],
                    'message'    => $body['message'] ?? '',
                ]);
                return ['shop_tier' => null, 'is_indicated' => false, 'error' => $body['error'], 'raw' => $body];
            }

            $data = $body['response'] ?? $body;

            // Derivar tier a partir dos campos disponíveis
            // is_preferred_seller = true → 'preferred' (ou 'platinum')
            // shop_level present → usar como aproximacao do tier indicado
            $isPreferred = (bool) ($data['is_preferred_seller'] ?? false);
            $shopLevel   = strtolower($data['shop_level'] ?? '');

            // Heuristica: campo shop_level varia por pais/versao da API.
            // 'gold','gold_plus','premium' → indicado ou acima
            $indicatedLevels = ['indicated', 'gold', 'gold_plus', 'silver_plus', 'premium'];
            $isLevelIndicated = in_array($shopLevel, $indicatedLevels, true);

            if ($isPreferred) {
                $tier = 'preferred';
            } elseif ($isLevelIndicated) {
                $tier = 'indicated';
            } else {
                $tier = 'normal';
            }

            return [
                'shop_tier'   => $tier,
                'is_indicated' => $tier !== 'normal',
                'shop_name'    => $data['shop_name'] ?? null,
                'description'  => $data['description'] ?? null,
                'raw'          => $data,
            ];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[ShopeeService] getShopInfo exception', [
                'account_id' => $account->id,
                'error'      => $e->getMessage(),
            ]);
            return ['shop_tier' => null, 'is_indicated' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * MUL-095: Sincroniza shop_tier de todas as contas Shopee ativas.
     * Chamado pelo schedule diario apos refresh de tokens.
     */
    public function syncShopTierForAllAccounts(): array
    {
        $accounts = \App\Models\MarketplaceAccount::where('platform', 'shopee')
            ->where('status', 'active')
            ->whereNotNull('shop_id')
            ->get();

        $stats = ['updated' => 0, 'errors' => 0, 'skipped' => 0];

        foreach ($accounts as $account) {
            // Pular se synced recentemente (< 20h) para economizar chamadas
            if ($account->shop_tier_synced_at && $account->shop_tier_synced_at->diffInHours(now()) < 20) {
                $stats['skipped']++;
                continue;
            }

            $info = $this->getShopInfo($account);

            if (isset($info['error'])) {
                $stats['errors']++;
                continue;
            }

            $account->update([
                'shop_tier'           => $info['shop_tier'],
                'is_indicated'        => $info['is_indicated'],
                'shop_tier_synced_at' => now(),
            ]);
            $stats['updated']++;

            usleep(500000); // Respeitar rate limit Shopee
        }

        return $stats;
    }
}
