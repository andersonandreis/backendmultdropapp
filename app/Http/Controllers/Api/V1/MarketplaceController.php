<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\PublishClientProductToMLJob;
use App\Jobs\ExportProductToBlingJob;
use App\Models\ClientProduct;
use App\Models\MarketplaceAccount;
use App\Services\GoolhubBridgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Marketplace', description: 'Conexao e publicacao em marketplaces (direto ou via ponte goolhub)')]
class MarketplaceController extends Controller
{
    public function __construct(private readonly GoolhubBridgeService $bridge)
    {
    }

    // =========================================================================
    // CONNECT
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/marketplace/connect/{platform}',
        summary: 'Iniciar conexao com um marketplace',
        description: 'Inicia o fluxo de conexao com um marketplace. Plataformas com method=direct sao redirecionadas para o fluxo OAuth. Plataformas com method=bridge consultam a API legada goolhub.io e retornam a URL de autorizacao. Response: { redirect_url }.',
        tags: ['Marketplace'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'platform',
                in: 'path',
                required: true,
                description: 'Plataforma de destino',
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['mercadolivre', 'bling', 'shopee', 'magalu', 'amazon', 'tiktok']
                ),
                example: 'shopee'
            ),
            new OA\Parameter(
                name: 'deposito_id',
                in: 'query',
                required: false,
                description: 'ID do deposito no legado (obrigatorio para plataformas bridge)',
                schema: new OA\Schema(type: 'integer', example: 1)
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'URL de conexao gerada',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'redirect_url', type: 'string',
                            example: 'https://partner.shopeemobile.com/api/v2/shop/auth_partner?partner_id=...'),
                        new OA\Property(property: 'method', type: 'string', enum: ['direct', 'bridge'], example: 'bridge'),
                        new OA\Property(property: 'platform', type: 'string', example: 'shopee'),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Plataforma nao suportada'),
            new OA\Response(response: 401, description: 'Nao autenticado'),
            new OA\Response(response: 502, description: 'Falha ao comunicar com API legada (bridge)'),
        ]
    )]
    public function connect(Request $request, string $platform): JsonResponse
    {
        $config = config("marketplaces.{$platform}");

        if (! $config) {
            return response()->json(['error' => "Plataforma '{$platform}' nao suportada."], 400);
        }

        $method = $config['method'] ?? 'direct';

        if ($method === 'direct') {
            // Redireciona para o OAuthController existente
            $params = ['client_id' => $request->user()->client?->id];
            if ($request->get('return_url')) {
                $params['return_url'] = $request->get('return_url');
            }
            if ($request->get('supplier_id')) {
                $params['supplier_id'] = (int) $request->get('supplier_id');
            }
            // MUL-314: o nome que o seller digita ao criar a integracao chegava ate aqui
            // e era descartado -- o redirect ja sabia ler account_name (usava o default
            // 'Minha Loja'), so ninguem mandava. Por isso so funcionava editando depois.
            if ($request->get('account_name')) {
                $params['account_name'] = (string) $request->get('account_name');
            }
            // NOV-077: source_system identifica o tenant pra Bling OAuth relay centralizado
            if ($platform === 'bling') {
                $params['source_system'] = config('bling.app_tenant', 'hubai');
                // SEL-324: relay de WL resolve client no hub por EMAIL (MUL-183) — sem email o hub aborta 422
                $params['email'] = $request->user()->email;
            }
            // SEL-375: assinatura HMAC curta (10 min) sobre client_id|supplier_id|exp.
            // O OAuthController::redirect valida quando OAUTH_REDIRECT_REQUIRE_SIG=true
            // (flag por tenant no .env) — fecha o CSRF de binding onde qualquer request
            // anonimo iniciava OAuth com client_id arbitrario. Params extras sao
            // inofensivos nos backends sem a flag.
            $params['exp'] = time() + 600;
            $params['sig'] = hash_hmac(
                'sha256',
                ($params['client_id'] ?? '') . '|' . ($params['supplier_id'] ?? '') . '|' . $params['exp'],
                (string) config('app.key')
            );

            // MUL-076: Bling OAuth com relay ativo precisa redirect pro hub central api.hubai.io.
            // URL::to() resolveria pra APP_URL local (api.multdrop.app/api.fornecefy.io), fazendo
            // o OAuthController do WL processar o redirect E o exchange — viola arquitetura
            // de relay unico (so o hubai.io tem BLING_CLIENT_SECRET autorizado pelo Bling).
            // OAUTH_RELAY_URL aponta pro hub central (default api.hubai.io).
            // MUL-411: o comentario acima descreve a limitacao que deixou de existir —
            // "so o hubai.io tem BLING_CLIENT_SECRET autorizado" era verdade enquanto o
            // Multdrop nao tinha app proprio. Agora tem, entao a conexao nova nao precisa
            // mais desviar pelo hub: vai direto ao nosso /api/oauth/bling/redirect.
            // A flag use_relay continua intacta para todo o resto que depende dela.
            $blingAppProprio = $platform === 'bling'
                && (string) config('bling.app_novo.client_id', '') !== '';

            if ($platform === 'bling' && config('bling.use_relay', false) && ! $blingAppProprio) {
                $relayBase = rtrim(config('app.oauth_relay_url') ?: 'https://api.hubai.io', '/');
                $redirectUrl = "{$relayBase}/api/oauth/{$platform}/redirect?" . http_build_query($params);
            } else {
                $redirectUrl = URL::to("/api/oauth/{$platform}/redirect") . '?' . http_build_query($params);
            }

            return response()->json([
                'redirect_url' => $redirectUrl,
                'method'       => 'direct',
                'platform'     => $platform,
            ]);
        }

        // method === bridge
        $user       = $request->user();
        $goolhubId  = (int) ($user->client?->document ?? 0);
        // Se document e 0 (usuario novo sem legacy), usa email como identificador
        $email      = ($goolhubId === 0) ? $user->email : null;
        $depositoId = (int) $request->query('deposito_id', config('multdrop.deposito_id', 1));
        $canal      = (int) ($config['canal_id'] ?? 0);

        $result = $this->bridge->getConnectionUrl($goolhubId, $depositoId, $canal, $email);

        if (! $result['success']) {
            return response()->json([
                'error' => 'Nao foi possivel obter URL de conexao via API legada.',
                'detail' => $result['error'],
            ], 502);
        }

        $redirectUrl = $result['data']['url'] ?? $result['data']['redirect_url'] ?? null;

        if (! $redirectUrl) {
            return response()->json([
                'error' => 'API legada nao retornou URL de conexao.',
            ], 502);
        }

        return response()->json([
            'redirect_url' => $redirectUrl,
            'method'       => 'bridge',
            'platform'     => $platform,
        ]);
    }

    // =========================================================================
    // SEL-375: CONFIRMACAO DE CONTA (OAUTH_CONFIRM_BEFORE_ACTIVE)
    // =========================================================================

    /**
     * Confirma (ou rejeita) uma conta recem-conectada via OAuth quando o tenant
     * roda com OAUTH_CONFIRM_BEFORE_ACTIVE=true. O confirm_token e opaco, vive
     * 30min no cache e foi emitido pelo OAuthController::callback.
     */
    public function confirmConnection(Request $request): JsonResponse
    {
        $data = $request->validate([
            'confirm_token' => 'required|string|max:64',
            'accept'        => 'required|boolean',
        ]);

        $clientId = $request->user()?->client?->id;
        if (!$clientId) {
            return response()->json(['error' => 'Cliente nao encontrado para o usuario autenticado.'], 422);
        }

        $accountId = \Illuminate\Support\Facades\Cache::pull("oauth_confirm_{$data['confirm_token']}");
        if (!$accountId) {
            return response()->json(['error' => 'Token de confirmacao invalido ou expirado. Conecte novamente.'], 410);
        }

        $account = MarketplaceAccount::where('id', $accountId)
            ->where('client_id', $clientId)
            ->first();
        if (!$account) {
            return response()->json(['error' => 'Conta nao encontrada para este cliente.'], 404);
        }

        if (!$data['accept']) {
            $platform = $account->platform;
            $account->delete();
            Log::info('[SEL-375] Conexao OAuth rejeitada pelo usuario — conta removida', [
                'account_id' => $accountId, 'client_id' => $clientId, 'platform' => $platform,
            ]);
            return response()->json(['status' => 'rejected', 'message' => 'Conta descartada.']);
        }

        $account->update(['status' => 'active']);
        if (in_array($account->platform, ['mercadolivre', 'shopee'])) {
            try {
                \App\Jobs\ImportMarketplaceAccountDataJob::dispatch($account->id)->onQueue('default');
            } catch (\Throwable $e) {
                Log::warning('[SEL-375] Falha ao disparar ImportMarketplaceAccountDataJob: ' . $e->getMessage());
            }
        }

        return response()->json([
            'status'   => 'active',
            'account'  => [
                'id'              => $account->id,
                'platform'        => $account->platform,
                'seller_nickname' => $account->seller_nickname,
            ],
        ]);
    }

    // =========================================================================
    // STATUS
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/marketplace/status',
        summary: 'Status de todas as integracoes do usuario',
        description: 'Combina MarketplaceAccounts (integracoes diretas OAuth) com as configuracoes de plataformas suportadas, retornando o status de conexao de cada marketplace.',
        tags: ['Marketplace'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Status das integracoes',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'platform', type: 'string', example: 'mercadolivre'),
                            new OA\Property(property: 'method', type: 'string', enum: ['direct', 'bridge'], example: 'direct'),
                            new OA\Property(property: 'connected', type: 'boolean', example: true),
                            new OA\Property(property: 'account_id', type: 'integer', nullable: true, example: 12),
                            new OA\Property(property: 'seller_name', type: 'string', nullable: true, example: 'loja-xpto'),
                            new OA\Property(property: 'status', type: 'string', nullable: true, example: 'active'),
                            new OA\Property(property: 'last_sync', type: 'string', nullable: true, format: 'date-time'),
                        ]
                    )
                )
            ),
            new OA\Response(response: 401, description: 'Nao autenticado'),
        ]
    )]
    public function status(Request $request): JsonResponse
    {
        $user      = $request->user();
        $clientId  = $user->client?->id;
        $platforms = config('marketplaces', []);

        // Carrega contas diretas (MarketplaceAccount)
        $accounts = collect();
        if ($clientId) {
            $accounts = MarketplaceAccount::where('client_id', $clientId)
                ->get(['id', 'platform', 'seller_nickname', 'status', 'last_sync_at'])
                ->keyBy('platform');
        }

        $result = [];
        foreach ($platforms as $platformKey => $cfg) {
            $account = $accounts->get($platformKey);
            // "connected" so e true quando a conta existe E tem status "active".
            // Contas com needs_reauth / pending / inactive / expired NAO sao consideradas conectadas.
            $isConnected = $account !== null && $account->status === 'active';
            $productsCount = 0;
            if ($account) {
                $productsCount = \App\Models\ClientProduct::where('marketplace_account_id', $account->id)
                    ->whereNotNull('external_listing_id')
                    ->count();
            }
            $result[] = [
                'platform'       => $platformKey,
                'method'         => $cfg['method'] ?? 'direct',
                'connected'      => $isConnected,
                'account_id'     => $account?->id,
                'seller_name'    => $account?->seller_nickname,
                'seller_nickname'=> $account?->seller_nickname,
                'status'         => $account?->status,
                'last_sync'      => $account?->last_sync_at?->toIso8601String(),
                'last_sync_at'   => $account?->last_sync_at?->toIso8601String(),
                'products_count' => $productsCount,
                'sync_errors_count' => 0,
            ];
        }

        return response()->json($result);
    }

    // =========================================================================
    // PUBLISH
    // =========================================================================

    #[OA\Post(
        path: '/api/v1/marketplace/{platform}/publish',
        summary: 'Publicar produto em um marketplace',
        description: 'Publica um produto em um marketplace especifico. Plataformas diretas usam o fluxo interno de publicacao. Plataformas bridge (Shopee, Magalu, Amazon, TikTok) delegam para a API legada goolhub.io.',
        tags: ['Marketplace'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'platform',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', enum: ['mercadolivre', 'bling', 'shopee', 'magalu', 'amazon', 'tiktok']),
                example: 'shopee'
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['product_id'],
                properties: [
                    new OA\Property(property: 'product_id', type: 'integer', example: 42,
                        description: 'ID do produto (Product.id) a ser publicado'),
                    new OA\Property(property: 'title', type: 'string', nullable: true, example: 'Camiseta Polo Masculina Tamanho M'),
                    new OA\Property(property: 'price', type: 'number', format: 'float', nullable: true, example: 59.90),
                    new OA\Property(property: 'category_id', type: 'string', nullable: true, example: 'MLB12345',
                        description: 'ID da categoria no marketplace de destino'),
                    new OA\Property(property: 'integrations', type: 'array',
                        items: new OA\Items(type: 'integer'),
                        nullable: true,
                        description: 'IDs de integracoes no legado (obrigatorio para bridge)',
                        example: [101, 102]),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Produto publicado com sucesso',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'Produto enviado para publicacao com sucesso.'),
                    new OA\Property(property: 'platform', type: 'string', example: 'shopee'),
                    new OA\Property(property: 'data', type: 'object', nullable: true),
                ])
            ),
            new OA\Response(response: 400, description: 'Plataforma nao suportada'),
            new OA\Response(response: 401, description: 'Nao autenticado'),
            new OA\Response(response: 422, description: 'Dados invalidos'),
            new OA\Response(response: 502, description: 'Falha ao comunicar com API legada (bridge)'),
        ]
    )]
    public function publish(Request $request, string $platform): JsonResponse
    {
        $config = config("marketplaces.{$platform}");

        if (! $config) {
            return response()->json(['error' => "Plataforma '{$platform}' nao suportada."], 400);
        }

        $data = $request->validate([
            'product_id'   => 'required|integer|exists:products,id',
            'title'        => 'nullable|string|max:255',
            'price'        => 'nullable|numeric|min:0',
            'category_id'  => 'nullable|string|max:50',
            'integrations' => 'nullable|array',
            'integrations.*' => 'integer',
        ]);

        $method = $config['method'] ?? 'direct';

        if ($method === 'direct') {
            // Plataformas diretas (ML, Bling) usam Jobs proprios
            $user      = $request->user();
            $clientId  = $user->client?->id;

            // Localiza o ClientProduct do lojista para este produto
            $clientProduct = ClientProduct::where('product_id', $data['product_id'])
                ->when($clientId, fn($q) => $q->whereHas('marketplaceAccount', fn($qa) => $qa->where('client_id', $clientId)->where('platform', $platform)))
                ->first();

            if ($clientProduct) {
                // SEL-423: Bling usa ExportProductToBlingJob; ML e outros usam PublishClientProductToMLJob
                if ($platform === 'bling') {
                    ExportProductToBlingJob::dispatch($clientProduct->id);
                    Log::info('MarketplaceController: ExportProductToBlingJob enfileirado', [
                        'client_product_id' => $clientProduct->id,
                        'platform'          => $platform,
                    ]);
                } else {
                    PublishClientProductToMLJob::dispatch($clientProduct->id);
                    Log::info('MarketplaceController: PublishClientProductToMLJob enfileirado', [
                        'client_product_id' => $clientProduct->id,
                        'platform'          => $platform,
                    ]);
                }
            } else {
                Log::warning("MarketplaceController: ClientProduct nao encontrado para product_id={$data['product_id']} platform={$platform}");
            }

            return response()->json([
                'message'  => 'Produto enviado para publicacao com sucesso.',
                'platform' => $platform,
                'data'     => ['queued' => true, 'product_id' => $data['product_id']],
            ]);
        }

        // bridge — usa GoolhubBridgeService
        $integrations = $data['integrations'] ?? [];

        if (empty($integrations)) {
            return response()->json([
                'error' => 'Campo integrations e obrigatorio para plataformas bridge.',
            ], 422);
        }

        // legacy_sku_pai_id e a referencia usada pelo sistema legado
        $product   = \App\Models\Product::findOrFail($data['product_id']);
        $skuPaiId  = $product->legacy_sku_pai_id ?? $product->id;

        $result = $this->bridge->publishProduct((int) $skuPaiId, $integrations);

        if (! $result['success']) {
            return response()->json([
                'error'  => 'Falha ao publicar produto via API legada.',
                'detail' => $result['error'],
            ], 502);
        }

        return response()->json([
            'message'  => 'Produto enviado para publicacao com sucesso.',
            'platform' => $platform,
            'data'     => $result['data'],
        ]);
    }

    // =========================================================================
    // FETCH LISTING (GET /api/v1/marketplace/listing)
    // =========================================================================

    public function fetchListing(Request $request): JsonResponse
    {
        $request->validate([
            'marketplace_account_id' => 'required|integer',
            'product_id'             => 'required|integer',
        ]);

        $user     = $request->user();
        $clientId = $user->client?->id;

        $account = MarketplaceAccount::where('id', $request->marketplace_account_id)
            ->where('client_id', $clientId)
            ->first();

        if (! $account) {
            return response()->json(['error' => 'Conta de marketplace nao encontrada.'], 404);
        }

        $clientProduct = \App\Models\ClientProduct::where('product_id', $request->product_id)
            ->where('marketplace_account_id', $account->id)
            ->first();

        if (! $clientProduct) {
            return response()->json(['error' => 'Produto nao encontrado nesta conta de marketplace.'], 404);
        }

        if (! $clientProduct->external_listing_id) {
            return response()->json(['error' => 'Produto ainda nao publicado neste marketplace.'], 422);
        }

        if ($account->platform === 'mercadolivre') {
            /** @var \App\Services\Integrations\Marketplaces\MercadoLivreService $mlService */
            $mlService = app(\App\Services\Integrations\Marketplaces\MercadoLivreService::class);
            $token = $mlService->getAccessToken($account);

            if (! $token) {
                return response()->json(['error' => 'Token do Mercado Livre invalido ou expirado. Reconecte a conta.'], 422);
            }

            $itemId = $clientProduct->external_listing_id;

            $response = \Illuminate\Support\Facades\Http::withToken($token)
                ->get("https://api.mercadolibre.com/items/{$itemId}");

            if ($response->failed()) {
                Log::error('[MarketplaceController] fetchListing ML falhou', [
                    'item_id' => $itemId,
                    'status'  => $response->status(),
                    'body'    => $response->body(),
                ]);
                return response()->json([
                    'error' => 'Falha ao buscar anuncio no Mercado Livre: ' . ($response->json('message') ?? $response->body()),
                ], $response->status());
            }

            $item = $response->json();

            $descResponse = \Illuminate\Support\Facades\Http::withToken($token)
                ->get("https://api.mercadolibre.com/items/{$itemId}/description");

            $description = null;
            if ($descResponse->successful()) {
                $description = $descResponse->json('plain_text');
            }

            $images = collect($item['pictures'] ?? [])->map(fn($p) => $p['secure_url'] ?? $p['url'] ?? null)->filter()->values()->toArray();

            // Calcula qualidade do anuncio por completude
            $score = 0;
            $details = [];
            if (!empty($item['title'])) { $score += 20; } else { $details[] = 'Adicione um titulo ao anuncio'; }
            if (!empty($description)) { $score += 20; } else { $details[] = 'Adicione uma descricao detalhada'; }
            if (count($images) >= 3) { $score += 20; } elseif (count($images) >= 1) { $score += 10; $details[] = 'Adicione ao menos 3 fotos (voce tem ' . count($images) . ')'; } else { $details[] = 'Adicione fotos ao anuncio'; }
            if (in_array('good_quality_thumbnail', $item['tags'] ?? [])) { $score += 10; } else { $details[] = 'Use uma foto principal de alta qualidade'; }
            if (!empty($item['warranty'])) { $score += 10; } else { $details[] = 'Informe a garantia do produto'; }
            $attrs = $item['attributes'] ?? [];
            $filledAttrs = array_filter($attrs, fn($a) => !empty($a['value_name']) && $a['value_name'] !== 'Nao se aplica');
            $pct = count($attrs) > 0 ? count($filledAttrs) / count($attrs) : 0;
            if ($pct >= 0.8) { $score += 20; } elseif ($pct >= 0.5) { $score += 10; $details[] = 'Preencha mais atributos do produto'; } else { $details[] = 'Preencha os atributos obrigatorios do produto'; }
            if ($score >= 100) { $details = ['Anuncio completo!']; }

            return response()->json([
                'title'          => $item['title'] ?? null,
                'description'    => $description,
                'price'          => $item['price'] ?? null,
                'stock'          => $item['available_quantity'] ?? null,
                'images'         => $images,
                'status'         => $item['status'] ?? null,
                'condition'      => $item['condition'] ?? null,
                'listing_type'   => $item['listing_type_id'] ?? null,
                'permalink'      => $item['permalink'] ?? null,
                'warranty'       => $item['warranty'] ?? null,
                'category_id'    => $item['category_id'] ?? null,
                'attributes'     => collect($attrs)->map(fn($a) => ['name' => $a['name'], 'value' => $a['value_name']])->filter(fn($a) => !empty($a['value']))->values()->toArray(),
                'health_score'   => min(100, $score),
                'health_details' => $details,
            ]);
        }

        if ($account->platform === 'shopee') {
            $shopeeService = app(\App\Services\Integrations\Marketplaces\ShopeeService::class);
            $itemId = (int) $clientProduct->external_listing_id;
            $resp = $shopeeService->fetchItemDetail($account, $itemId);

            if (!empty($resp['error'])) {
                return response()->json(['error' => $resp['message'] ?? 'Erro ao buscar anuncio na Shopee.'], 422);
            }

            $images = collect($resp['image']['image_url_list'] ?? [])->values()->toArray();
            $priceInfo = $resp['price_info'][0] ?? [];
            $price = $priceInfo['current_price'] ?? $priceInfo['original_price'] ?? 0;
            $stockInfo = $resp['stock_info'][0] ?? [];
            $stock = $stockInfo['current_stock'] ?? $stockInfo['seller_stock'] ?? 0;
            $condition = match($resp['condition'] ?? 'NEW') { 'NEW' => 'new', 'USED' => 'used', default => 'new' };
            $statusMap = ['NORMAL' => 'active', 'BANNED' => 'paused', 'DELETED' => 'paused', 'UNLIST' => 'paused'];
            $status = $statusMap[$resp['item_status'] ?? ''] ?? 'active';
            $attrs = collect($resp['attribute_list'] ?? [])->map(fn($a) => [
                'name' => $a['attribute_name'] ?? '',
                'value' => collect($a['attribute_value_list'] ?? [])->pluck('display_value_name')->implode(', ')
            ])->filter(fn($a) => !empty($a['name']) && !empty($a['value']))->values()->toArray();

            // Score de qualidade por completude
            $score = 0; $details = [];
            if (!empty($resp['item_name'])) { $score += 20; } else { $details[] = 'Adicione um titulo ao anuncio'; }
            if (!empty($resp['description'])) { $score += 20; } else { $details[] = 'Adicione uma descricao detalhada'; }
            if (count($images) >= 3) { $score += 20; } elseif (count($images) >= 1) { $score += 10; $details[] = 'Adicione ao menos 3 fotos (voce tem ' . count($images) . ')'; } else { $details[] = 'Adicione fotos ao anuncio'; }
            if (!empty($resp['brand']['original_brand_name']) && $resp['brand']['original_brand_name'] !== 'NoBrand') { $score += 10; } else { $details[] = 'Informe a marca do produto'; }
            if (!empty($attrs)) { $score += 20; } else { $details[] = 'Preencha os atributos do produto no painel da Shopee'; }
            if ($price > 0) { $score += 10; }
            if ($score >= 100) { $details = ['Anuncio completo!']; }

            $shopUrl = 'https://shopee.com.br/product/' . ($account->shop_id ?? $account->seller_id ?? '') . '/' . $itemId;

            return response()->json([
                'title'          => $resp['item_name'] ?? null,
                'description'    => $resp['description'] ?? null,
                'price'          => $price,
                'stock'          => $stock,
                'images'         => $images,
                'status'         => $status,
                'condition'      => $condition,
                'listing_type'   => 'shopee',
                'permalink'      => $shopUrl,
                'warranty'       => null,
                'category_id'    => (string) ($resp['category_id'] ?? ''),
                'attributes'     => $attrs,
                'health_score'   => min(100, $score),
                'health_details' => $details,
            ]);
        }

        return response()->json(['error' => 'Plataforma nao suportada para esta operacao.'], 400);
    }

    // =========================================================================
    // UPDATE LISTING (PUT /api/v1/marketplace/listing)
    // =========================================================================

    public function updateListing(Request $request): JsonResponse
    {
        $request->validate([
            'marketplace_account_id' => 'required|integer',
            'product_id'             => 'required|integer',
            'title'                  => 'nullable|string|max:255',
            'description'            => 'nullable|string',
            'price'                  => 'nullable|numeric|min:0',
            'stock'                  => 'nullable|integer|min:0',
            'status'                 => 'nullable|string|in:active,paused',
            'warranty'               => 'nullable|string|max:100',
        ]);

        $user     = $request->user();
        $clientId = $user->client?->id;

        $account = MarketplaceAccount::where('id', $request->marketplace_account_id)
            ->where('client_id', $clientId)
            ->first();

        if (! $account) {
            return response()->json(['error' => 'Conta de marketplace nao encontrada.'], 404);
        }

        $clientProduct = \App\Models\ClientProduct::where('product_id', $request->product_id)
            ->where('marketplace_account_id', $account->id)
            ->first();

        if (! $clientProduct || ! $clientProduct->external_listing_id) {
            return response()->json(['error' => 'Anuncio nao encontrado. Publique o produto primeiro.'], 404);
        }

        if ($account->platform === 'mercadolivre') {
            /** @var \App\Services\Integrations\Marketplaces\MercadoLivreService $mlService */
            $mlService = app(\App\Services\Integrations\Marketplaces\MercadoLivreService::class);
            $token = $mlService->getAccessToken($account);

            if (! $token) {
                return response()->json(['error' => 'Token do Mercado Livre invalido ou expirado. Reconecte a conta.'], 422);
            }

            $itemId  = $clientProduct->external_listing_id;
            $payload = [];

            if ($request->filled('title'))       { $payload['title']              = mb_substr($request->title, 0, 60); }
            if ($request->filled('price'))        { $payload['price']              = (float) $request->price; }
            if ($request->filled('stock'))        { $payload['available_quantity'] = (int) $request->stock; }

            if (! empty($payload)) {
                $response = \Illuminate\Support\Facades\Http::withToken($token)
                    ->put("https://api.mercadolibre.com/items/{$itemId}", $payload);

                if ($response->failed()) {
                    Log::error('[MarketplaceController] updateListing ML PUT falhou', [
                        'item_id' => $itemId,
                        'status'  => $response->status(),
                        'body'    => $response->body(),
                    ]);
                    return response()->json([
                        'error' => 'Falha ao atualizar anuncio no Mercado Livre: ' . ($response->json('message') ?? $response->body()),
                    ], 422);
                }
            }

            if ($request->filled('description')) {
                $descResponse = \Illuminate\Support\Facades\Http::withToken($token)
                    ->put("https://api.mercadolibre.com/items/{$itemId}/description", [
                        'plain_text' => $request->description,
                    ]);

                if ($descResponse->failed()) {
                    Log::warning('[MarketplaceController] updateListing ML description PUT falhou', [
                        'item_id' => $itemId,
                        'status'  => $descResponse->status(),
                        'body'    => $descResponse->body(),
                    ]);
                }
            }

            $cpUpdate = [];
            if ($request->filled('title'))       { $cpUpdate['custom_title']       = $request->title; }
            if ($request->filled('description')) { $cpUpdate['custom_description'] = $request->description; }
            if ($request->filled('price'))       { $cpUpdate['custom_price']       = $request->price; }
            if (! empty($cpUpdate)) {
                $clientProduct->update($cpUpdate);
            }

            Log::info('[MarketplaceController] updateListing ML sucesso', [
                'item_id'    => $itemId,
                'account_id' => $account->id,
                'fields'     => array_keys($payload),
            ]);

            return response()->json(['success' => true]);
        }

        if ($account->platform === 'shopee') {
            $shopeeService = app(\App\Services\Integrations\Marketplaces\ShopeeService::class);
            $itemId = (int) $clientProduct->external_listing_id;
            $resp = $shopeeService->updateItemDetail($account, $itemId,
                $request->input('title', ''),
                $request->input('description', ''),
                (float) $request->input('price', 0),
                $request->input('status')
            );
            if (!empty($resp['error'])) {
                return response()->json(['error' => $resp['message'] ?? 'Erro ao atualizar anuncio na Shopee.'], 422);
            }
            return response()->json(['success' => true, 'platform' => 'shopee', 'item_id' => $itemId]);
        }

        return response()->json(['error' => 'Plataforma nao suportada para esta operacao.'], 400);
    }
    // =========================================================================
    // ACCOUNT STATS (GET /api/v1/marketplace/accounts/{accountId}/stats)
    // =========================================================================
    public function accountStats(Request $request, int $accountId): JsonResponse
    {
        $user = $request->user();
        $clientId = $user->client?->id;

        $account = MarketplaceAccount::where('id', $accountId)
            ->where('client_id', $clientId)
            ->first();

        if (! $account) {
            return response()->json(['error' => 'Conta nao encontrada.'], 404);
        }

        $productsCount = \App\Models\ClientProduct::where('marketplace_account_id', $accountId)
            ->whereNotNull('external_listing_id')
            ->count();

        $totalProducts = \App\Models\ClientProduct::where('marketplace_account_id', $accountId)->count();

        return response()->json([
            'account_id'     => $account->id,
            'platform'       => $account->platform,
            'seller_nickname'=> $account->seller_nickname,
            'status'         => $account->status,
            'last_sync_at'   => $account->last_sync_at?->toIso8601String(),
            'products_count' => $productsCount,
            'total_products' => $totalProducts,
            'sync_errors_count' => 0,
        ]);
    }

    // =========================================================================
    // ACCOUNT SYNC (POST /api/v1/marketplace/accounts/{accountId}/sync)
    // =========================================================================
    public function accountSync(Request $request, int $accountId): JsonResponse
    {
        $user = $request->user();
        $clientId = $user->client?->id;

        $account = MarketplaceAccount::where('id', $accountId)
            ->where('client_id', $clientId)
            ->first();

        if (! $account) {
            return response()->json(['error' => 'Conta nao encontrada.'], 404);
        }

        // MUL-214 item 35: sync manual real — despacha o job de importacao da conta.
        // Throttle 5 min pra nao empilhar jobs na queue legacy-import.
        if ($account->last_sync_at && $account->last_sync_at->gt(now()->subMinutes(5))) {
            return response()->json([
                'success' => false,
                'message' => 'Sincronizacao ja solicitada ha menos de 5 minutos. Aguarde um pouco.',
            ], 429);
        }

        // MUL-214 item 35 fix arquitetura (ordem Ruan 11/07): WL NUNCA puxa pedido de
        // conta gerida centralmente — pede via API pro hub; o hub puxa, trata (pipeline
        // custo/imagem) e devolve via fanout. Puxar na WL deixava o hub cego (MUL-187/212).
        $cfg = app(\App\Services\InstallationConfig::class);
        if (! $cfg->isHub() && $account->centrally_managed) {
            $relay = $this->relayAccountSyncToHub($account, $cfg);

            if ($relay === 429) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sincronizacao ja solicitada ha menos de 5 minutos. Aguarde um pouco.',
                ], 429);
            }

            if ($relay !== 200) {
                return response()->json([
                    'success' => false,
                    'message' => 'Hub central indisponivel no momento. Tente novamente em instantes.',
                ], 502);
            }

            $account->last_sync_at = now();
            $account->save();

            return response()->json(['success' => true, 'message' => 'Sincronizacao iniciada. Os dados serao atualizados em alguns minutos.']);
        }

        $account->last_sync_at = now();
        $account->save();

        \App\Jobs\ImportMarketplaceAccountDataJob::dispatch($account->id, true);

        return response()->json(['success' => true, 'message' => 'Sincronizacao iniciada. Os dados serao atualizados em alguns minutos.']);
    }

    /**
     * MUL-214 item 35: pede ao hub central que sincronize a conta correspondente.
     * Mapeamento: shopee => shop_id; mercadolivre => ml_user_id;
     * bling => service (tenant) + wl_client_id. Retorna o status HTTP do hub (0 = inacessivel).
     */
    private function relayAccountSyncToHub(MarketplaceAccount $account, \App\Services\InstallationConfig $cfg): int
    {
        $secret = (string) config('services.shopee.bridge_secret', '');
        if ($secret === '') {
            Log::error('[MarketplaceController] relayAccountSyncToHub: bridge_secret ausente', [
                'account_id' => $account->id,
            ]);
            return 0;
        }

        $body = json_encode([
            'platform'     => (string) $account->platform,
            'shop_id'      => (string) ($account->shop_id ?? ''),
            'ml_user_id'   => (string) ($account->ml_user_id ?? ''),
            'tenant'       => (string) config('bling.app_tenant', 'hubai'),
            'wl_client_id' => (int) $account->client_id,
            'requested_by' => (string) config('app.url'),
        ]);
        $sig = hash_hmac('sha256', $body, $secret);

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(20)
                ->withHeaders([
                    'X-HubAI-Bridge-Sig' => $sig,
                    'Content-Type'       => 'application/json',
                ])
                ->withBody($body, 'application/json')
                ->post($cfg->hubUrl() . '/api/marketplace/bridge-sync');
        } catch (\Throwable $e) {
            Log::warning('[MarketplaceController] Hub inacessivel no relay de sync', [
                'account_id' => $account->id,
                'error'      => $e->getMessage(),
            ]);
            return 0;
        }

        Log::info('[MarketplaceController] Relay de sync pro hub', [
            'account_id' => $account->id,
            'platform'   => $account->platform,
            'status'     => $response->status(),
        ]);

        return $response->status();
    }

    /**
     * MUL-214 item 35: POST /api/marketplace/bridge-sync (roda no HUB, HMAC bridge).
     * A WL pede; o hub localiza a conta central equivalente, despacha o
     * ImportMarketplaceAccountDataJob e entrega os pedidos de volta via fanout.
     */
    public function accountSyncBridge(Request $request): JsonResponse
    {
        $secret = (string) config('services.shopee.bridge_secret', '');
        $sig    = (string) $request->header('X-HubAI-Bridge-Sig', '');
        if ($secret === '' || $sig === '' || ! hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $sig)) {
            return response()->json(['error' => 'invalid_signature'], 403);
        }

        $cfg = app(\App\Services\InstallationConfig::class);
        if (! $cfg->isHub()) {
            return response()->json(['error' => 'not_a_hub'], 409);
        }

        $platform = (string) $request->input('platform', '');

        $query = MarketplaceAccount::query()->where('status', 'active');
        if ($platform === 'shopee' && $request->filled('shop_id')) {
            $query->where('platform', 'shopee')->where('shop_id', (string) $request->input('shop_id'));
        } elseif (in_array($platform, ['mercadolivre', 'mercado_livre'], true) && $request->filled('ml_user_id')) {
            $query->whereIn('platform', ['mercadolivre', 'mercado_livre'])
                ->where('ml_user_id', (string) $request->input('ml_user_id'));
        } elseif ($platform === 'bling' && $request->filled('tenant') && $request->filled('wl_client_id')) {
            $query->where('platform', 'bling')
                ->where('service', (string) $request->input('tenant'))
                ->where('wl_client_id', (int) $request->input('wl_client_id'));
        } else {
            return response()->json(['error' => 'unsupported_platform_or_missing_identifier'], 422);
        }

        $account = $query->orderByDesc('id')->first();
        if (! $account) {
            return response()->json(['error' => 'unknown_account'], 404);
        }

        if ($account->last_sync_at && $account->last_sync_at->gt(now()->subMinutes(5))) {
            return response()->json(['error' => 'throttled'], 429);
        }

        $account->last_sync_at = now();
        $account->save();

        \App\Jobs\ImportMarketplaceAccountDataJob::dispatch($account->id, true);

        Log::info('[MarketplaceController] bridge-sync aceito — hub vai puxar e entregar via fanout', [
            'hub_account_id' => $account->id,
            'platform'       => $account->platform,
            'requested_by'   => (string) $request->input('requested_by', ''),
        ]);

        return response()->json(['success' => true]);
    }
    // =========================================================================
    // ACCOUNT HEALTH (GET /api/v1/marketplace/accounts/{accountId}/health)
    // =========================================================================
    public function accountHealth(Request $request, int $accountId): JsonResponse
    {
        $user     = $request->user();
        $clientId = $user->client?->id;

        $account = MarketplaceAccount::where('id', $accountId)
            ->where('client_id', $clientId)
            ->first();

        if (! $account) {
            return response()->json(['error' => 'Conta nao encontrada.'], 404);
        }

        // MUL-172: metadados da conta conectada — identificar rapidamente conta cruzada/fantasma
        $tokenExpiresAt = match ($account->platform) {
            'mercadolivre' => $account->ml_token_expires_at ?? $account->token_expires_at,
            'bling'        => $account->bling_token_expires_at ?? $account->token_expires_at,
            default        => $account->token_expires_at,
        };
        $accountInfo = [
            'account_id'               => $account->id,
            'account_name'             => $account->account_name,
            'db_status'                => $account->status,
            'client_id'                => $account->client_id,
            'connected_user_email'     => $user->email,
            'seller_id'                => $account->seller_id,
            'shop_id'                  => $account->shop_id,
            'ml_user_id'               => $account->ml_user_id,
            'token_expires_at'         => $tokenExpiresAt,
            'refresh_token_expires_at' => $account->refresh_token_expires_at,
            'last_token_refresh_at'    => $account->last_token_refresh_at,
            'last_sync_at'             => $account->last_sync_at,
            'connected_at'             => $account->created_at,
        ];

        if ($account->platform === 'mercadolivre') {
            $mlService = app(\App\Services\Integrations\Marketplaces\MercadoLivreService::class);
            $token     = $mlService->getAccessToken($account);

            if (! $token) {
                return response()->json(['error' => 'Token invalido. Reconecte a conta.'], 422);
            }

            // Busca dados do vendedor via ML API
            $userResp = \Illuminate\Support\Facades\Http::withToken($token)
                ->get("https://api.mercadolibre.com/users/me");

            if ($userResp->failed()) {
                return response()->json(['error' => 'Falha ao buscar dados do Mercado Livre.'], 422);
            }

            $userData   = $userResp->json();
            $reputation = $userData['seller_reputation'] ?? [];
            $metrics    = $reputation['metrics'] ?? [];
            $levelMap   = [
                '1_red'    => ['label' => 'Vermelho',   'color' => 'red'],
                '2_orange' => ['label' => 'Laranja',    'color' => 'orange'],
                '3_yellow' => ['label' => 'Amarelo',    'color' => 'yellow'],
                '4_light_green' => ['label' => 'Verde Claro', 'color' => 'lime'],
                '5_green'  => ['label' => 'Verde',      'color' => 'green'],
            ];
            $levelId    = $reputation['level_id'] ?? null;
            $level      = $levelMap[$levelId] ?? ['label' => $levelId ?? 'Sem dados', 'color' => 'gray'];

            return response()->json([
                'platform'           => 'mercadolivre',
                'seller_id'          => $userData['id'] ?? null,
                'nickname'           => $userData['nickname'] ?? $account->seller_nickname,
                'level_id'           => $levelId,
                'level_label'        => $level['label'],
                'level_color'        => $level['color'],
                'power_seller'       => $reputation['power_seller_status'] ?? null,
                'transactions_60d'   => $reputation['transactions']['completed'] ?? null,
                'claims_rate'        => $metrics['claims']['rate'] ?? null,
                'delayed_rate'       => $metrics['delayed_handling_time']['rate'] ?? null,
                'cancellations_rate' => $metrics['cancellations']['rate'] ?? null,
                'points'             => $userData['points'] ?? null,
                'site_id'            => $userData['site_id'] ?? null,
                'permalink'          => $userData['permalink'] ?? null,
                'platform_email'     => $userData['email'] ?? null,
                'account'            => $accountInfo,
            ]);
        }

        if ($account->platform === 'shopee') {
            $shopeeService = app(\App\Services\Integrations\Marketplaces\ShopeeService::class);
            $ref      = new \ReflectionClass($shopeeService);
            $getShopId    = $ref->getMethod('getShopId'); $getShopId->setAccessible(true);
            $getToken     = $ref->getMethod('getValidAccessToken'); $getToken->setAccessible(true);
            $callApi      = $ref->getMethod('callApi'); $callApi->setAccessible(true);

            $shopId      = $getShopId->invoke($shopeeService, $account);
            $accessToken = $getToken->invoke($shopeeService, $account);

            if (! $shopId || ! $accessToken) {
                return response()->json(['error' => 'Token Shopee invalido. Reconecte a conta.'], 422);
            }

            $shopInfo = $callApi->invoke($shopeeService, '/api/v2/shop/get_shop_info', [
                'shop_id'      => $shopId,
                'access_token' => $accessToken,
            ], 'GET');

            // get_shop_info retorna dados direto na raiz (sem wrapper 'response')

            // MUL-166: get_penalty_point e get_recommended_shop_class NAO existem na API v2
            // (retornavam error_not_found — a nota de "escopo shop.info" era diagnostico errado).
            // Endpoints corretos: account_health/get_penalty_point_history e account_health/get_shop_performance.
            $penaltyPoints = null;
            $ratingStar    = null;
            $healthNote    = null;

            try {
                $penaltyResp = $callApi->invoke($shopeeService, '/api/v2/account_health/get_penalty_point_history', [
                    'shop_id'      => $shopId,
                    'access_token' => $accessToken,
                ], 'GET');
                if (isset($penaltyResp['response']['penalty_point_list'])) {
                    $penaltyPoints = 0;
                    foreach ($penaltyResp['response']['penalty_point_list'] as $item) {
                        $penaltyPoints += (int) ($item['penalty_points'] ?? $item['points'] ?? 0);
                    }
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::info('[Shopee health] penalty_points unavailable: ' . $e->getMessage());
            }

            try {
                $perfResp = $callApi->invoke($shopeeService, '/api/v2/account_health/get_shop_performance', [
                    'shop_id'      => $shopId,
                    'access_token' => $accessToken,
                ], 'GET');
                foreach ($perfResp['response']['metric_list'] ?? [] as $metric) {
                    if (($metric['metric_name'] ?? '') === 'shop_rating' && isset($metric['current_period'])) {
                        $ratingStar = (float) $metric['current_period'];
                        break;
                    }
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::info('[Shopee health] shop_performance unavailable: ' . $e->getMessage());
            }

            // MUL-360 item 1: o selo "Indicado" (Shopee Verified) nao existe na OpenAPI v2
            // (MUL-166 ja provou que os endpoints tentados nao existem). Fonte real: endpoint
            // publico da vitrine v4 — best-effort, cacheado, e null quando indisponivel.
            $isIndicated = \Illuminate\Support\Facades\Cache::remember(
                "shopee_verified_badge_{$shopId}",
                6 * 3600,
                function () use ($shopId) {
                    try {
                        $resp = \Illuminate\Support\Facades\Http::timeout(8)
                            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'])
                            ->get('https://shopee.com.br/api/v4/shop/get_shop_detail', ['shopid' => $shopId]);
                        if ($resp->ok() && ($resp->json('error') === 0)) {
                            $d = $resp->json('data') ?? [];
                            return (bool) (($d['is_shopee_verified'] ?? false) || ($d['show_shopee_verified_label'] ?? false));
                        }
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::info('[Shopee health] v4 shop_detail unavailable: ' . $e->getMessage());
                    }
                    return null; // indisponivel ≠ inativo — front distingue
                }
            );

            return response()->json([
                'platform'       => 'shopee',
                'shop_id'        => $shopId,
                'shop_name'      => $shopInfo['shop_name'] ?? $account->seller_nickname,
                'description'    => $shopInfo['description'] ?? null,
                'shop_logo'      => $shopInfo['shop_logo'] ?? null,
                'shop_status'    => $shopInfo['status'] ?? null,
                'region'         => $shopInfo['region'] ?? null,
                'shop_url'       => $shopId ? ('https://shopee.com.br/shop/' . $shopId) : null,
                'is_mart'        => $shopInfo['is_mart_shop'] ?? false,
                'rating_star'    => $ratingStar,
                'penalty_points' => $penaltyPoints,
                'is_indicated'   => $isIndicated,
                'health_note'    => $healthNote,
                'account'        => $accountInfo,
            ]);
        }

        // MUL-161-BE1 #2: health para Bling — status da conta via getAccountPlan.
        // /usuarios/me retorna situacao (A=ativa, I=inativa) e dados do plano.
        // O escopo /usuarios/me pode retornar 403 se o app Bling nao tiver esse scope;
        // nesse caso retorna degradado com mensagem clara.
        if ($account->platform === 'bling') {
            $blingClient = app(\App\Services\Integrations\Erps\Bling\BlingApiClient::class);

            // MUL-172: /empresas/me/dados-basicos funciona no escopo atual (ao contrário
            // de /usuarios/me que dá 403) — traz nome, email e CNPJ da empresa conectada.
            $company = null;
            try {
                $companyResp = $blingClient->get($account, '/empresas/me/dados-basicos');
                if (! empty($companyResp['data'])) {
                    $company = [
                        'name'  => $companyResp['data']['nome'] ?? null,
                        'email' => $companyResp['data']['email'] ?? null,
                        'cnpj'  => $companyResp['data']['cnpj'] ?? null,
                    ];
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::info('[Bling health] dados-basicos unavailable: ' . $e->getMessage());
            }

            try {
                $planData = $blingClient->getAccountPlan($account);

                $situacao    = $planData['user_status'] ?? null;
                $status      = match($situacao) {
                    'A' => 'ativa',
                    'I' => 'suspensa',
                    default => $situacao ?? 'desconhecido',
                };

                return response()->json([
                    'platform'    => 'bling',
                    'account_id'  => $account->id,
                    'status'      => $status,
                    'situacao'    => $situacao,
                    'plan'        => $planData['plan_name'] ?? null,
                    'expires_at'  => $planData['expires_at'] ?? null,
                    'store_name'  => $planData['store_name'] ?? null,
                    'user_name'   => $planData['user_name'] ?? null,
                    'user_email'  => $planData['user_email'] ?? ($company['email'] ?? null),
                    'company_name'  => $company['name'] ?? null,
                    'company_email' => $company['email'] ?? null,
                    'company_cnpj'  => $company['cnpj'] ?? null,
                    'fetched_at'  => $planData['fetched_at'] ?? null,
                    'account'     => $accountInfo,
                    'note'        => isset($planData['error'])
                        ? 'Falha ao buscar dados: ' . $planData['error'] . '. Verificar escopo /usuarios/me no portal do app Bling.'
                        : null,
                ]);
            } catch (\Throwable $e) {
                return response()->json([
                    'platform'   => 'bling',
                    'account_id' => $account->id,
                    'status'     => 'desconhecido',
                    'company_name'  => $company['name'] ?? null,
                    'company_email' => $company['email'] ?? null,
                    'company_cnpj'  => $company['cnpj'] ?? null,
                    'account'    => $accountInfo,
                    'note'       => 'Erro ao buscar status Bling: ' . $e->getMessage() . '. Verificar escopo /usuarios/me no portal do app Bling.',
                ], 200);
            }
        }

        return response()->json(['error' => 'Plataforma sem suporte a metricas.'], 400);
    }

}
