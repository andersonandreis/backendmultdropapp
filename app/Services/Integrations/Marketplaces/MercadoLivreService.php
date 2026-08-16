<?php

namespace App\Services\Integrations\Marketplaces;

use App\Models\MarketplaceAccount;
use App\Models\Product;
use App\Models\Order;
use App\Services\Integrations\Contracts\MarketplaceInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MercadoLivreService implements MarketplaceInterface
{
    protected string $baseUrl = 'https://api.mercadolibre.com';
    protected string $appId;
    protected string $clientSecret;
    protected string $redirectUri;

    public function __construct()
    {
        // Settings from ENV or Database Settings model
        $this->appId = config('services.mercadolivre.app_id', env('ML_APP_ID', ''));
        $this->clientSecret = config('services.mercadolivre.client_secret', env('ML_CLIENT_SECRET', ''));
        $this->redirectUri = config('services.mercadolivre.redirect_uri', env('ML_REDIRECT_URI', ''));
    }

    public function authenticate(MarketplaceAccount $account): string|array
    {
        // Se a conta ja tem credentials com access_token valido/refrescavel
        // A rigor devia retornar a URL de consentimento
        $url = "https://auth.mercadolivre.com.br/authorization?response_type=code&client_id={$this->appId}&redirect_uri={$this->redirectUri}&state={$account->id}";

        return [
            'status' => 'redirect',
            'url' => $url
        ];
    }

    public function syncProduct(MarketplaceAccount $account, Product $product): bool|array
    {
        $token = $this->getValidAccessToken($account);

        if (!$token) {
            return ['error' => 'token_error', 'message' => 'Token Mercado Livre ausente ou expirado. Reconecte a conta ML.'];
        }

        // --- INICIO: ESCUDO ANTI-BAN (HIPER-AUTOMACAO) ---
        $forbiddenWords = \App\Models\ForbiddenWord::where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('context')->orWhere('context', 'mercadolivre');
            })->pluck('word')->toArray();

        $textToAnalyze = strtolower($product->name . ' ' . ($product->description ?? ''));
        foreach ($forbiddenWords as $word) {
            if (mb_stripos($textToAnalyze, strtolower($word)) !== false) {
                // Bloqueio preventivo ativado!
                \App\Models\SyncLog::create([
                    'syncable_type' => Product::class,
                    'syncable_id' => $product->id,
                    'platform' => 'mercadolivre',
                    'action' => 'Sync Product',
                    'direction' => 'outbound',
                    'status' => 'failed',
                    'error_message' => "BLOQUEIO PREVENTIVO HUBAI: O produto contem a palavra proibida '{$word}'. Remova este termo para evitar banimento da sua loja no Mercado Livre.",
                    'request_payload' => json_encode(['title' => $product->name, 'description' => $product->description]),
                ]);
                return false;
            }
        }
        // --- FIM: ESCUDO ANTI-BAN ---

        // Busca o ClientProduct pra usar campos custom
        $clientProduct = \App\Models\ClientProduct::where('product_id', $product->id)
            ->where('marketplace_account_id', $account->id)
            ->first();

        $title = $clientProduct?->custom_title ?: $product->ai_title ?: $product->name;
        $description = $clientProduct?->custom_description ?: $product->ai_description ?: $product->description;
        $price = $clientProduct?->custom_price ?: $product->price;
        $condition = $clientProduct?->custom_condition ?: $product->condition ?: 'new';
        $listingType = $clientProduct?->listing_type_id ?: 'gold_special';
        $categoryId = $clientProduct?->external_category_id ?: null;

        // Monta imagens — URLs dinamicas anti-ban
        $pictures = [];
        $images = $clientProduct?->custom_images ?: [];
        if (empty($images)) {
            // Fallback: pega do catalogo do fornecedor
            foreach ($product->media()->orderBy('position')->limit(10)->get() as $media) {
                $url = $media->url ?: asset('storage/' . $media->path);
                $pictures[] = ['source' => $url . '?seller=' . ($clientProduct?->client_id ?? 0) . '&t=' . time()];
            }
        } else {
            foreach ($images as $img) {
                $pictures[] = ['source' => $img];
            }
        }

        // Estoque efetivo (HUB-113: clip em 99.999, limite duro do ML)
        // MUL-234: publishedStock fake por seller — passa client_id do cliente que cria o anuncio
        $stock = $product->publishedStock($clientProduct?->client_id);
        $stock = min((int) $stock, 99999);

        $payload = [
            'title'              => mb_substr($title, 0, 60),
            'category_id'        => $categoryId,
            'price'              => (float) $price,
            'currency_id'        => 'BRL',
            'available_quantity'  => $stock,
            'buying_mode'        => 'buy_it_now',
            'condition'          => $condition,
            'listing_type_id'    => $listingType,
            'description'        => ['plain_text' => $description ?? ''],
            'pictures'           => $pictures,
            'shipping'           => ['mode' => 'me2', 'free_methods' => []],
        ];

        // MES-019 BUG 1 — Dimensoes em INTEIROS (ML rejeita decimal)
        // weight em GRAMAS, dimensoes em CM. Default seguro: 100g + 10x10x10cm.
        $weightKg = $clientProduct?->custom_weight_kg ?: $product->weight_kg;
        $heightCm = $clientProduct?->custom_height_cm ?: $product->height_cm;
        $widthCm  = $clientProduct?->custom_width_cm  ?: $product->width_cm;
        $lengthCm = $clientProduct?->custom_length_cm ?: $product->length_cm;

        // ML rejeita dimensoes "cubicas" iguais (10x10x10) — usar valores assimetricos default
        $weightGrams = $weightKg ? max(1, (int) round(((float) $weightKg) * 1000)) : 200;
        $heightInt   = $heightCm ? max(1, (int) round((float) $heightCm)) : 5;
        $widthInt    = $widthCm  ? max(1, (int) round((float) $widthCm))  : 11;
        $lengthInt   = $lengthCm ? max(1, (int) round((float) $lengthCm)) : 16;

        // ML pattern: AxBxC,grams (comprimento x largura x altura, peso em gramas)
        $payload['shipping']['dimensions'] = "{$lengthInt}x{$widthInt}x{$heightInt},{$weightGrams}";

        // MES-019 BUG 2 — Atributos obrigatorios (BRAND, MODEL, GTIN + required da categoria)
        $attributes = [];
        $brand = $clientProduct?->custom_brand ?: $product->brand ?: 'Generico';
        $attributes[] = ['id' => 'BRAND', 'value_name' => mb_substr($brand, 0, 60)];

        $model = $clientProduct?->custom_model ?: $product->model ?: $product->model_name;
        if (!$model) {
            // Fallback: usa primeiras palavras do titulo como modelo
            $model = mb_substr($title, 0, 60);
        }
        $attributes[] = ['id' => 'MODEL', 'value_name' => mb_substr($model, 0, 60)];

        // GTIN: so envia se brand for confiavel (nao-generica) OU se cliente
        // explicitamente setou custom_gtin. ML rejeita "Generico" + GTIN reusado.
        // Quando nao envia GTIN, ML aceita publicacao com warning (nao bloqueia).
        $gtin = $clientProduct?->custom_gtin ?: $product->gtin ?: $product->ean;
        $brandIsGeneric = in_array(mb_strtolower((string) $brand), ['generico', 'generica', 'sem marca', 'n/a'], true);
        if ($gtin && (!$brandIsGeneric || $clientProduct?->custom_gtin)) {
            $attributes[] = ['id' => 'GTIN', 'value_name' => (string) $gtin];
        }

        // Custom attributes vindos do client_products (override/extras)
        $customAttrs = $clientProduct?->custom_attributes;
        if (is_string($customAttrs)) {
            $customAttrs = json_decode($customAttrs, true) ?: [];
        }
        if (is_array($customAttrs)) {
            foreach ($customAttrs as $k => $v) {
                if (is_array($v) && isset($v['id'])) {
                    $attributes[] = $v;
                } elseif (is_scalar($v)) {
                    $attributes[] = ['id' => strtoupper((string) $k), 'value_name' => (string) $v];
                }
            }
        }

        // Busca atributos required adicionais da categoria (cache 24h) e preenche
        // com valor generico quando o produto nao tiver — evita erro 400 do ML.
        // Cada item: ['id' => 'NETWORK_CABLE_TYPE', 'default' => 'Cat 5e']
        if ($categoryId) {
            $requiredAttrs = $this->getCategoryRequiredAttributes($categoryId);
            $alreadyHas = collect($attributes)->pluck('id')->map(fn($x) => strtoupper((string) $x))->all();
            foreach ($requiredAttrs as $req) {
                $reqId = is_array($req) ? ($req['id'] ?? null) : $req;
                if (!$reqId || in_array(strtoupper($reqId), $alreadyHas, true)) {
                    continue;
                }
                $defaultVal = is_array($req) ? ($req['default'] ?? 'Generico') : 'Generico';
                $attributes[] = ['id' => $reqId, 'value_name' => (string) $defaultVal];
            }
        }

        $payload['attributes'] = $attributes;

        // Cria ou atualiza
        $existingId = $clientProduct?->external_listing_id;

        if ($existingId) {
            // UPDATE
            unset($payload['category_id'], $payload['buying_mode'], $payload['condition'], $payload['listing_type_id']);
            $response = Http::withToken($token)->put("{$this->baseUrl}/items/{$existingId}", $payload);
        } else {
            // CREATE
            if (!$categoryId) {
                Log::warning('[MercadoLivreService] syncProduct sem category_id', ['product_id' => $product->id]);
                return ['error' => 'missing_category', 'message' => 'Categoria ML nao configurada. Clique em Editar Produto, selecione a categoria ML e tente publicar novamente.'];
            }
            $response = Http::withToken($token)->post("{$this->baseUrl}/items", $payload);
        }

        if ($response->failed()) {
            $error = $response->json();
            Log::error('[MercadoLivreService] syncProduct falhou', [
                'product_id' => $product->id,
                'status'     => $response->status(),
                'error'      => $error['message'] ?? $response->body(),
                'cause'      => $error['cause'] ?? [],
            ]);

            \App\Models\SyncLog::create([
                'syncable_type'   => \App\Models\Product::class,
                'syncable_id'     => $product->id,
                'platform'        => 'mercadolivre',
                'action'          => $existingId ? 'Update Listing' : 'Create Listing',
                'direction'       => 'outbound',
                'status'          => 'failed',
                'error_message'   => $error['message'] ?? $response->body(),
                'request_payload' => json_encode($payload),
            ]);

            $mlMsg   = $error['message'] ?? $response->body();
            $mlCause = '';
            if (!empty($error['cause'])) {
                $causes = collect($error['cause'])->pluck('message')->filter()->implode('; ');
                if ($causes) {
                    $mlCause = ' Detalhes: ' . $causes;
                }
            }
            return ['error' => $error['error'] ?? 'api_error', 'message' => $mlMsg . $mlCause];
        }

        $data = $response->json();
        $externalId = $data['id'] ?? $existingId;

        Log::info('[MercadoLivreService] syncProduct sucesso', [
            'product_id'  => $product->id,
            'external_id' => $externalId,
            'action'      => $existingId ? 'updated' : 'created',
        ]);

        return ['external_id' => $externalId, 'permalink' => $data['permalink'] ?? null];
    }

    public function fetchOrders(MarketplaceAccount $account, string $sinceDate = null): array
    {
        $token = $this->getValidAccessToken($account);
        $sellerId = $account->ml_user_id;

        if (!$token || !$sellerId) {
            Log::warning('[MercadoLivreService] fetchOrders: token ou seller_id ausente', ['account_id' => $account->id]);
            return [];
        }

        $sinceDate = $sinceDate ?: now()->subHours(24)->toIso8601String();

        $response = Http::withToken($token)->get("{$this->baseUrl}/orders/search", [
            'seller' => $sellerId,
            'order.date_created.from' => $sinceDate,
            'sort'   => 'date_desc',
            'limit'  => 50,
        ]);

        if ($response->failed()) {
            // MUL-155: 403 com body HTML = proxy ML bloqueou a conta (tengine). Marcar needs_reauth.
            $this->markReauthIfHtmlForbidden($response, $account, 'fetchOrders');
            Log::error('[MercadoLivreService] fetchOrders falhou', [
                'account_id' => $account->id,
                'status'     => $response->status(),
                'body'       => $response->body(),
            ]);
            return [];
        }

        $data = $response->json();
        $orders = $data['results'] ?? [];

        Log::info('[MercadoLivreService] fetchOrders', [
            'account_id' => $account->id,
            'total'      => count($orders),
        ]);

        return $orders;
    }

    public function syncInventoryAndPrice(MarketplaceAccount $account, string $sku, int $quantity, float $price): bool
    {
        $token = $this->getValidAccessToken($account);

        if (!$token) {
            // NOV-202: interface exige bool — array aqui causava TypeError (54 failed jobs/h)
            Log::warning('[MercadoLivreService] syncInventoryAndPrice: token ausente ou expirado', ['account_id' => $account->id]);
            return false;
        }

        // NOV-079: busca pelo external_listing_id ou custom_sku (coluna 'sku' nao existe em client_products)
        // external_listing_id = ML item_id (ex: MLB123456789); custom_sku = SKU customizado pelo cliente
        $clientProduct = \App\Models\ClientProduct::where('marketplace_account_id', $account->id)
            ->where(function ($q) use ($sku) {
                $q->where('external_listing_id', $sku)
                  ->orWhere('custom_sku', $sku)
                  ->orWhere('supplier_product_sku', $sku);
            })
            ->first();

        if (!$clientProduct || !$clientProduct->external_listing_id) {
            Log::warning('[MercadoLivreService] syncInventoryAndPrice: listing nao encontrada', [
                'account_id' => $account->id,
                'sku'        => $sku,
            ]);
            return false;
        }

        $itemId = $clientProduct->external_listing_id;

        // HUB-xxx FIX-3: delay de 200ms entre requests consecutivos da mesma conta
        // para respeitar rate limit do ML (~5 req/s por access_token).
        $rateLimitKey = 'ml_rate_limit_account:' . $account->id;
        $lastRequestAt = \Illuminate\Support\Facades\Cache::get($rateLimitKey, 0);
        $elapsed = (int) round((microtime(true) - $lastRequestAt) * 1000); // ms
        if ($elapsed < 200) {
            usleep((200 - $elapsed) * 1000);
        }
        \Illuminate\Support\Facades\Cache::put($rateLimitKey, microtime(true), now()->addSeconds(5));

        // HUB-xxx FIX-2: verificar status do item antes de tentar atualizar.
        // Items under_review/closed/inactive retornam 400 field_not_updatable (1.936 erros/dia).
        // GET /items/{id}?attributes=id,status e skip silencioso para status bloqueantes.
        $statusResp = Http::withToken($token)
            ->timeout(10)
            ->get("{$this->baseUrl}/items/{$itemId}", ['attributes' => 'id,status']);
        if ($statusResp->ok()) {
            $mlStatus = $statusResp->json('status');
            $skipStatuses = ['under_review', 'closed', 'inactive', 'payment_required', 'not_yet_active'];
            if ($mlStatus && in_array($mlStatus, $skipStatuses, true)) {
                Log::warning('[MercadoLivreService] syncInventoryAndPrice: item em status bloqueante — skip', [
                    'account_id' => $account->id,
                    'item_id'    => $itemId,
                    'ml_status'  => $mlStatus,
                ]);
                return false;
            }
        }

        // HUB-113: clip em 99.999 — limite duro do ML em varias categorias.
        // Guard NOV-151: ML nao aceita available_quantity < 0.
        if ($quantity < 0) {
            Log::warning('[MercadoLivreService] syncInventoryAndPrice: estoque negativo clipado em 0', [
                'account_id' => $account->id,
                'item_id'    => $itemId,
                'original'   => $quantity,
            ]);
            $quantity = 0;
        }

        $maxMlQty = 99999;
        if ($quantity > $maxMlQty) {
            Log::warning('[MercadoLivreService] syncInventoryAndPrice: estoque clipado em 99999 (limite ML)', [
                'account_id' => $account->id,
                'item_id'    => $itemId,
                'original'   => $quantity,
                'clipped'    => $maxMlQty,
            ]);
            $quantity = $maxMlQty;
        }

        $response = Http::withToken($token)->put("{$this->baseUrl}/items/{$itemId}", [
            'available_quantity' => $quantity,
            'price'             => $price,
        ]);

        // HUB-xxx FIX-1: throttling 429 — sleep 60s e skip (nao retenta na mesma execucao).
        // O ML retorna 429 quando a conta ultrapassa o rate limit por access_token (~7.982 erros/dia).
        if ($response->status() === 429) {
            Log::warning('[MercadoLivreService] syncInventoryAndPrice: 429 too_many_requests — dormindo 60s e pulando item', [
                'account_id' => $account->id,
                'item_id'    => $itemId,
            ]);
            sleep(60);
            return false;
        }

        if ($response->failed()) {
            $errorBody = $response->json() ?? [];
            $errorCode = $errorBody['error'] ?? '';
            // Detectar field_not_updatable especificamente para logar como WARNING (nao ERROR)
            if ($errorCode === 'field_not_updatable' || str_contains($response->body(), 'field_not_updatable')) {
                Log::warning('[MercadoLivreService] syncInventoryAndPrice: campo nao atualizavel (field_not_updatable) — skip', [
                    'account_id' => $account->id,
                    'item_id'    => $itemId,
                    'status'     => $response->status(),
                    'error'      => $errorCode,
                ]);
                return false;
            }
            Log::error('[MercadoLivreService] syncInventoryAndPrice falhou', [
                'account_id' => $account->id,
                'item_id'    => $itemId,
                'status'     => $response->status(),
                'body'       => $response->body(),
            ]);
            return false;
        }

        Log::info('[MercadoLivreService] Estoque/preco atualizado', [
            'account_id' => $account->id,
            'item_id'    => $itemId,
            'quantity'   => $quantity,
            'price'      => $price,
        ]);

        return true;
    }


    /**
     * MES-019 BUG 2 — Lista atributos `required` da categoria ML com valor default.
     * Cache de 24h pra evitar request a cada publicacao.
     *
     * @param  string  $categoryId  ex: "MLB270088"
     * @return array<array{id:string,default:string}>  ex: [["id"=>"BRAND","default"=>"Generico"], ["id"=>"NETWORK_CABLE_TYPE","default"=>"Cat 5e"]]
     */
    protected function getCategoryRequiredAttributes(string $categoryId): array
    {
        $cacheKey = "ml_category_required_attrs_v2:{$categoryId}";
        try {
            return \Illuminate\Support\Facades\Cache::remember($cacheKey, now()->addHours(24), function () use ($categoryId) {
                $response = Http::timeout(10)->get("{$this->baseUrl}/categories/{$categoryId}/attributes");
                if ($response->failed()) {
                    Log::warning('[MercadoLivreService] getCategoryRequiredAttributes falhou', [
                        'category_id' => $categoryId,
                        'status'      => $response->status(),
                    ]);
                    return [];
                }
                $required = [];
                foreach ($response->json() ?? [] as $attr) {
                    $tags = $attr['tags'] ?? [];
                    if (!is_array($tags)) continue;
                    $isReq = (!empty($tags['required'])) || (!empty($tags['catalog_required']));
                    if (!$isReq) continue;

                    // Default value: primeiro value enum disponivel, senao "Generico"
                    $default = 'Generico';
                    $values = $attr['values'] ?? null;
                    if (is_array($values) && count($values) > 0) {
                        $default = $values[0]['name'] ?? 'Generico';
                    }
                    $required[] = ['id' => $attr['id'], 'default' => $default];
                }
                return $required;
            });
        } catch (\Throwable $e) {
            Log::warning('[MercadoLivreService] getCategoryRequiredAttributes exception', [
                'category_id' => $categoryId,
                'error'       => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Retorna o access_token valido (wrapper publico).
     */
    public function getAccessToken(MarketplaceAccount $account): ?string
    {
        return $this->getValidAccessToken($account);
    }

    /**
     * Retorna o access_token valido. Se expirado, renova automaticamente.
     */
    protected function getValidAccessToken(MarketplaceAccount $account): ?string
    {
        // Guard NOV-156: nao tentar operar em conta pending/fantasma (OAuth abandonado).
        if ($account->status === 'pending' || empty($account->seller_id)) {
            \Illuminate\Support\Facades\Log::warning('[MercadoLivreService] Tentativa de uso de conta pending/fantasma ignorada', [
                'account_id' => $account->id,
                'client_id'  => $account->client_id,
                'status'     => $account->status,
                'seller_id'  => $account->seller_id,
            ]);
            return null;
        }

        // PR5: token marcado como quebrado permanentemente -- parar retry ate reconexao OAuth
        if ($account->is_token_broken) {
            \Illuminate\Support\Facades\Log::warning('[MercadoLivreService] Conta com token quebrado (is_token_broken=1) -- skip refresh', [
                'account_id' => $account->id,
                'reason'     => $account->token_broken_reason,
            ]);
            return null;
        }

        $token = $account->ml_access_token;

        if (!$token) {
            return null;
        }

        // NOV-061: token ainda valido com margem de 5min -> retornar direto sem lock
        if ($account->ml_token_expires_at && now()->lt($account->ml_token_expires_at->subMinutes(5))) {
            try {
                return decrypt($token);
            } catch (\Illuminate\Contracts\Encryption\DecryptException) {
                return $token; // legacy plain text
            }
        }

        // NOV-061 (Bug 1): lock distribuido evita thundering herd com N workers
        // simultaneos refrescando o mesmo token (gera erro invalid_grant na ML e gasta refresh_token).
        $lock = \Illuminate\Support\Facades\Cache::store('redis')->lock("ml_token_refresh:{$account->id}", 30);
        try {
            $lock->block(10);
            $account->refresh(); // re-ler do banco - outro worker pode ter renovado
            if ($account->ml_token_expires_at && now()->lt($account->ml_token_expires_at->subMinutes(5))) {
                try {
                    return decrypt($account->ml_access_token);
                } catch (\Illuminate\Contracts\Encryption\DecryptException) {
                    return $account->ml_access_token;
                }
            }
            return $this->refreshToken($account);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            \Illuminate\Support\Facades\Log::warning('[MercadoLivreService] Lock timeout no refresh', ['account_id' => $account->id]);
            return null;
        } finally {
            optional($lock)->release();
        }
    }

    public function refreshToken(MarketplaceAccount $account): ?string
    {
        $refreshToken = $account->ml_refresh_token;

        if (!$refreshToken) {
            Log::warning('[MercadoLivreService] refreshToken: refresh_token ausente', ['account_id' => $account->id]);
            return null;
        }

        // Tokens are stored encrypted — decrypt before sending to ML API
        try {
            $refreshToken = decrypt($refreshToken);
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            // Token not encrypted (legacy plain text) — use as-is
        }

        // ML OAuth token endpoint requires application/x-www-form-urlencoded, NOT JSON
        $response = Http::asForm()->post("{$this->baseUrl}/oauth/token", [
            'grant_type'    => 'refresh_token',
            'client_id'     => $this->appId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $refreshToken,
        ]);

        if ($response->failed()) {
            $errorBody = $response->json() ?? [];
            $errorCode = $errorBody['error'] ?? '';

            // HUB-182: conta ja marcada needs_reauth/bloqueada — falha esperada (so reauth
            // manual resolve), warning pra nao poluir o canal de ERROR.
            $level = ($account->status === 'needs_reauth' || $account->sync_blocked_at) ? 'warning' : 'error';
            Log::log($level, '[MercadoLivreService] refreshToken falhou', [
                'account_id' => $account->id,
                'status'     => $response->status(),
                'error_code' => $errorCode,
                'body'       => $response->body(),
            ]);

            // MUL-155: 403 com body HTML = tengine proxy bloqueou antes de chegar na API ML.
            if ($response->status() === 403 && $this->isHtmlResponse($response)) {
                $account->update([
                    'status'              => 'needs_reauth',
                    'sync_blocked_at'     => now(),
                    'sync_errors_count'   => 99,
                    'last_error_message'  => '403 HTML no refresh -- proxy ML bloqueou a conta. Requer reconexao OAuth manual.',
                    'is_token_broken'     => 1,
                    'token_broken_reason' => '403_html_proxy',
                    'token_broken_at'     => now(),
                ]);
                Log::warning('[MercadoLivreService] 403+HTML no refreshToken -- bloqueio permanente aplicado + is_token_broken=1', [
                    'account_id' => $account->id,
                ]);
                return null;
            }

            // MES-023 -- invalid_grant = refresh_token de uso unico ja consumido ou revogado.
            // Bloquear permanentemente: sync_blocked_at + sync_errors_count=99 impede retry automatico.
            // O comando ml:recover-tokens filtra whereNull(sync_blocked_at), entao esta conta sai da fila.
            // Unica saida: reconexao OAuth manual pelo usuario.
            if ($errorCode === 'invalid_grant') {
                $account->update([
                    'status'              => 'needs_reauth',
                    'sync_blocked_at'     => now(),
                    'sync_errors_count'   => 99,
                    'last_error_message'  => 'invalid_grant -- refresh_token revogado ou ja consumido. Requer reconexao OAuth manual.',
                    'is_token_broken'     => 1,
                    'token_broken_reason' => 'invalid_grant',
                    'token_broken_at'     => now(),
                ]);
                Log::warning('[MercadoLivreService] invalid_grant -- bloqueio permanente aplicado + is_token_broken=1', [
                    'account_id' => $account->id,
                ]);
                try {
                    \App\Jobs\NotifyTokenBrokenJob::dispatch($account->id, 'mercadolivre');
                } catch (\Throwable $e) {
                    Log::warning('[MercadoLivreService] Falha ao despachar NotifyTokenBrokenJob', ['error' => $e->getMessage()]);
                }
                return null;
            }

            // Erros definitivos (nao invalid_grant): marcar needs_reauth apenas se NAO for erro temporario
            // 429 = rate limit ML (temporario), 502/503/504 = gateway/servico temporario
            $temporaryErrors = [429, 502, 503, 504];
            if (!in_array($response->status(), $temporaryErrors)) {
                $account->update(['status' => 'needs_reauth']);
                Log::warning('[MercadoLivreService] Conta marcada como needs_reauth', ['account_id' => $account->id]);
            } else {
                Log::warning('[MercadoLivreService] refreshToken erro temporario (nao marcando needs_reauth)', ['account_id' => $account->id, 'http_status' => $response->status()]);
            }
            return null;
        }

        $data = $response->json();
        $newAccessToken  = $data['access_token'];
        $newRefreshToken = $data['refresh_token'];

        // FOR-097: refresh bem-sucedido prova que o token/reauth funciona -- mas o
        // codigo so gravava os campos do token, nunca limpava as flags de erro
        // (needs_reauth/is_token_broken/sync_blocked_at ficavam presas mesmo com
        // token saudavel, ex: account #884 recuperou sozinho e continuou marcado).
        // NAO mexe em status=suspended (bloqueio comercial do ML pelo item.403
        // user_not_active, nao-relacionado a validade do token OAuth).
        $recoveryUpdate = [
            'needs_reauth'         => false,
            'is_token_broken'      => false,
            'token_broken_reason'  => null,
            'token_broken_at'      => null,
            'sync_blocked_at'      => null,
            'refresh_errors_count' => 0,
        ];
        if ($account->status === 'needs_reauth') {
            $recoveryUpdate['status'] = 'active';
        }

        // Store tokens encrypted to match OAuth service convention
        $account->update(array_merge([
            'ml_access_token'       => encrypt($newAccessToken),
            'ml_refresh_token'      => encrypt($newRefreshToken),
            'ml_token_expires_at'   => now()->addSeconds($data['expires_in'] ?? 21600),
            'last_token_refresh_at' => now(),
        ], $recoveryUpdate));

        Log::info('[MercadoLivreService] Token renovado', ['account_id' => $account->id]);

        return $newAccessToken;
    }

    /**
     * Configura o webhook na conta do vendedor no Mercado Livre.
     *
     * ATENCAO: O endpoint POST /users/{id}/applications/{appId}/notifications_settings
     * foi DESCONTINUADO pelo Mercado Livre. O proxy edge (tengine) retorna 403 HTML
     * antes de chegar na API em todas as contas — confirmado em 2026-06-27.
     *
     * Os webhooks do ML devem ser configurados UMA UNICA VEZ no Developer Portal:
     * https://developers.mercadolibre.com.br
     * (App ID: 7460452711550142)
     *
     * Este metodo e mantido na interface por compatibilidade, mas nao faz chamada HTTP.
     *
     * @param  MarketplaceAccount  $account      Conta do lojista
     * @param  string              $callbackUrl  URL de callback (ignorada — configurada no portal)
     * @return bool
     */
    public function configureWebhook(MarketplaceAccount $account, string $callbackUrl): bool
    {
        // HUB-136 (2026-06-27): endpoint notifications_settings descontinuado pelo ML.
        // Webhook configurado uma vez no Developer Portal — nao e por seller via API.
        Log::info("[MercadoLivreService] configureWebhook: no-op — webhook ML gerenciado via Developer Portal (app 7460452711550142)", [
            'account_id' => $account->id,
        ]);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getShippingLabel(MarketplaceAccount $account, Order $order): ?string
    {
        $token = $this->getValidAccessToken($account);
        if (!$token || !$order->external_shipping_id) {
            return null;
        }

        $response = Http::withToken($token)
            ->get("{$this->baseUrl}/shipment_labels", [
                'shipment_ids'  => $order->external_shipping_id,
                'response_type' => 'pdf',
            ]);

        if ($response->failed()) {
            Log::warning('[MercadoLivreService] Falha ao baixar etiqueta', [
                'order_id'    => $order->id,
                'shipping_id' => $order->external_shipping_id,
                'status'      => $response->status(),
            ]);
            return null;
        }

        $filename = "labels/order-{$order->id}-{$order->external_shipping_id}.pdf";
        // FOR-101: mesmo disk configurado que o ShippingLabelService usa.
        \Illuminate\Support\Facades\Storage::disk((string) config('filesystems.labels_disk', 'public'))
            ->put($filename, $response->body());

        $url = '/storage/' . $filename;
        $order->update(['label_url' => $url]);

        return $url;
    }


    /**
     * MUL-205: consulta shipment ML e retorna a primeira data prevista de
     * liberacao da etiqueta (buffering.date > estimated_schedule_limit.date).
     * Se nenhuma disponivel, retorna null (chamador cai no fallback).
     */
    public function getShipmentReadyDate(MarketplaceAccount $account, string $shipmentId): ?\Carbon\Carbon
    {
        $token = $this->getValidAccessToken($account);
        if (! $token) {
            return null;
        }

        $resp = Http::withToken($token)
            ->timeout(15)
            ->get($this->baseUrl . '/shipments/' . $shipmentId);

        if ($resp->failed()) {
            \Illuminate\Support\Facades\Log::warning('[MercadoLivreService] getShipmentReadyDate falhou', [
                'shipment_id' => $shipmentId,
                'status'      => $resp->status(),
            ]);
            return null;
        }

        $data = $resp->json() ?: [];
        $so = $data['shipping_option'] ?? [];

        // Preferencia: buffering.date (previsao real do ML pra terminar handling)
        $candidates = [
            $so['buffering']['date'] ?? null,
            $so['estimated_schedule_limit']['date'] ?? null,
        ];

        foreach ($candidates as $date) {
            if ($date) {
                try { return \Carbon\Carbon::parse($date); } catch (\Throwable $e) {}
            }
        }

        return null;
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
     * NOV-081: Pausa um anuncio no MercadoLivre por estoque zero.
     * API ML: PUT /items/{itemId} {status: 'paused'}
     */
    public function pauseItem(MarketplaceAccount $account, string $itemId): bool
    {
        $token = $this->getValidAccessToken($account);
        if (!$token) {
            return ['error' => 'token_error', 'message' => 'Token Mercado Livre ausente ou expirado. Reconecte a conta ML.'];
        }

        $response = Http::withToken($token)
            ->put("{$this->baseUrl}/items/{$itemId}", ['status' => 'paused']);

        if ($response->failed()) {
            Log::warning('[MercadoLivreService] pauseItem falhou', [
                'account_id' => $account->id,
                'item_id'    => $itemId,
                'status'     => $response->status(),
            ]);
            return false;
        }

        return true;
    }

    /**
     * NOV-081: Reativa um anuncio no MercadoLivre apos reposicao de estoque.
     * API ML: PUT /items/{itemId} {status: 'active'}
     */
    public function activateItem(MarketplaceAccount $account, string $itemId): bool
    {
        $token = $this->getValidAccessToken($account);
        if (!$token) {
            return ['error' => 'token_error', 'message' => 'Token Mercado Livre ausente ou expirado. Reconecte a conta ML.'];
        }

        $response = Http::withToken($token)
            ->put("{$this->baseUrl}/items/{$itemId}", ['status' => 'active']);

        if ($response->failed()) {
            Log::warning('[MercadoLivreService] activateItem falhou', [
                'account_id' => $account->id,
                'item_id'    => $itemId,
                'status'     => $response->status(),
            ]);
            return false;
        }

        return true;
    }
    /**
     * MUL-155: 403+HTML em chamada de API = proxy tengine bloqueou antes da API real.
     * Marca a conta como needs_reauth com bloqueio permanente.
     */
    protected function markReauthIfHtmlForbidden(
        \Illuminate\Http\Client\Response $response,
        MarketplaceAccount $account,
        string $context = ''
    ): void {
        if ($response->status() === 403 && $this->isHtmlResponse($response)) {
            $account->update([
                'status'             => 'needs_reauth',
                'sync_blocked_at'    => now(),
                'sync_errors_count'  => 99,
                'last_error_message' => "403 HTML em {$context} -- proxy ML bloqueou a conta. Requer reconexao OAuth manual.",
            ]);
            Log::warning("[MercadoLivreService] 403+HTML em {$context} -- needs_reauth marcado", [
                'account_id' => $account->id,
                'context'    => $context,
            ]);
        }
    }

    /**
     * Retorna true se a resposta tem Content-Type HTML ou body iniciando com tag HTML.
     */
    protected function isHtmlResponse(\Illuminate\Http\Client\Response $response): bool
    {
        $contentType = $response->header('Content-Type') ?? '';
        if (str_contains(strtolower($contentType), 'text/html')) {
            return true;
        }
        $body = ltrim($response->body());
        return str_starts_with($body, '<') || str_starts_with($body, '<!');
    }
}