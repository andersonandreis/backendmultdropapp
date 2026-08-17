<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Models\Client;
use App\Services\Integrations\Marketplaces\MercadoLivreService;
use App\Services\Integrations\Erps\Bling\BlingAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;
use App\Services\Logging\MigracaoLogger;
use App\Jobs\RelayBlingTokenRetryJob;
use App\Jobs\SyncInventoryJob;
use App\Models\ClientProduct;

class OAuthController extends Controller
{
    #[OA\Get(
        path: '/api/oauth/{platform}/redirect',
        operationId: 'oauthRedirect',
        summary: 'Inicia o fluxo OAuth para conexao com marketplace',
        description: 'Gera a URL de autorizacao da plataforma e redireciona o usuario. Suporta MercadoLivre (PKCE S256), Bling e Shopee. Nao requer autenticacao Sanctum — o client_id e passado via query param para permitir iniciacao pelo frontend hubai.io. O state PKCE e armazenado na URL de callback para validacao. Antes do redirect, cria automaticamente um registro MarketplaceAccount com status=pending (firstOrCreate), garantindo que o vendedor veja "Aguardando conexao" imediatamente, tanto pelo fluxo Filament quanto pela API REST. O registro e atualizado para status=active no callback apos troca bem-sucedida de tokens.',
        tags: ['OAuth'],
        parameters: [
            new OA\Parameter(
                name: 'platform',
                in: 'path',
                required: true,
                description: 'Plataforma de destino',
                schema: new OA\Schema(type: 'string', enum: ['mercadolivre', 'bling', 'shopee', 'shopify']),
                example: 'mercadolivre'
            ),
            new OA\Parameter(
                name: 'client_id',
                in: 'query',
                required: true,
                description: 'ID do cliente HubAI ou goolhub user_id',
                schema: new OA\Schema(type: 'integer'),
                example: 267076
            ),
            new OA\Parameter(
                name: 'supplier_id',
                in: 'query',
                required: false,
                description: 'ID do fornecedor associado (default 1)',
                schema: new OA\Schema(type: 'integer', default: 1),
                example: 1
            ),
            new OA\Parameter(
                name: 'account_name',
                in: 'query',
                required: false,
                description: 'Nome amigavel para a conta conectada',
                schema: new OA\Schema(type: 'string'),
                example: 'Minha Loja ML'
            ),
        ],
        responses: [
            new OA\Response(
                response: 302,
                description: 'Redirect para URL de autorizacao da plataforma'
            ),
            new OA\Response(
                response: 400,
                description: 'Plataforma nao suportada',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'error', type: 'string', example: 'Plataforma nao suportada'),
                    ]
                )
            ),
            new OA\Response(
                response: 422,
                description: 'client_id ausente',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'error', type: 'string', example: 'client_id required'),
                    ]
                )
            ),
        ]
    )]
    /**
     * Inicia o fluxo OAuth. Aceita client_id e supplier_id como query params
     * para permitir que o frontend (hubai.io) inicie o fluxo sem Sanctum auth.
     */
    public function redirect(Request $request, string $platform)
    {
        // INF-039: forca marker WL + mapping hub_supplier_id
        $appTenant = env("APP_TENANT");
        if ($appTenant && $appTenant !== "hubai" && !$request->has("source_system")) {
            $request->merge(["source_system" => $appTenant]);
        }
        if ($appTenant && $appTenant !== "hubai" && $request->has("supplier_id")) {
            $localSup = \App\Models\Supplier::find((int)$request->get("supplier_id"));
            if ($localSup && $localSup->hub_supplier_id) {
                $request->merge(["hub_supplier_id" => $localSup->hub_supplier_id]);
            }
        }
        // Aceita client_id via: query param > Sanctum Bearer token > sessao web (Filament)
        $clientId = $request->get('client_id');
        if (!$clientId) {
            // Tentar resolver pelo token Sanctum (Bearer) ou sessao web
            $authUser = $request->user('sanctum') ?? $request->user('web') ?? $request->user();
            if ($authUser) {
                $clientId = $authUser->client?->id;
            }
        }
        if (!$clientId && \Illuminate\Support\Facades\Auth::guard('web')->check()) {
            $clientId = \Illuminate\Support\Facades\Auth::guard('web')->user()->client?->id;
        }
        // SEL-375: com OAUTH_REDIRECT_REQUIRE_SIG=true (flag por tenant no .env),
        // request ANONIMO so inicia OAuth com assinatura HMAC emitida pelo
        // MarketplaceController::connect() autenticado — fecha o CSRF de binding
        // onde qualquer request iniciava OAuth com client_id arbitrario na query.
        if (env('OAUTH_REDIRECT_REQUIRE_SIG', false)) {
            $sigAuthUser = $request->user('sanctum') ?? $request->user('web') ?? $request->user();
            if (!$sigAuthUser && !\Illuminate\Support\Facades\Auth::guard('web')->check()) {
                $sig = (string) $request->get('sig', '');
                $exp = (int) $request->get('exp', 0);
                $expected = hash_hmac(
                    'sha256',
                    ($request->get('client_id') ?? '') . '|' . ($request->get('supplier_id') ?? '') . '|' . $exp,
                    (string) config('app.key')
                );
                if ($sig === '' || $exp < time() || !hash_equals($expected, $sig)) {
                    return response()->json(['error' => 'unauthorized'], 401);
                }
            }
        }
        // NOV-153: supplier ERP nao precisa de client_id
        $accountTypeEarly  = $request->get("account_type");
        $supplierIdEarly   = $request->get("supplier_id") ?: null;
        $skipClientIdCheck = ($accountTypeEarly === "supplier_erp" && $supplierIdEarly);
        if (!$clientId && !$skipClientIdCheck) {
            return response()->json(["error" => "client_id obrigatorio. Passe ?client_id=X ou use Bearer token"], 422);
        }

        // Validar que o client existe antes de prosseguir (evita FK violation no firstOrCreate)
        // Aceita tanto clients.id quanto legacy_id_login (vendor_id do painel)
        // NOV-153: supplier_erp nao usa client, pular validacao de client
        $clientModel = null;
        if (!$skipClientIdCheck) {
            // MUL-183: em relay de WL (source_system presente e != tenant local), o client_id
            // recebido pertence ao espaco de IDs DA WL — resolver por ID aqui gravaria tokens
            // no client errado do hub (incidente conta 694: fallback user_id acertou o Super
            // Admin e um full import poluiu 3 catalogos). Resolver SOMENTE por email.
            $earlySourceSystem = $request->get('source_system');
            $isWlRelay = $earlySourceSystem !== null
                && $earlySourceSystem !== config('bling.app_tenant', 'hubai');
            if ($isWlRelay) {
                $wlEmail = $request->get('email');
                if ($wlEmail) {
                    $clientModel = \App\Models\Client::whereHas('user', function ($q) use ($wlEmail) {
                        $q->where('email', $wlEmail);
                    })->first();
                }
                if (!$clientModel) {
                    Log::warning('OAuth relay WL sem client resolvivel por email — abortando (MUL-183)', [
                        'source_system' => $earlySourceSystem,
                        'wl_client_id'  => $clientId,
                        'email'         => $wlEmail,
                    ]);
                    return response()->json([
                        'error' => 'Relay de WL exige parametro email correspondente a um usuario do hub (MUL-183). Nao e possivel resolver client por ID de outra instalacao.',
                    ], 422);
                }
            } else {
                $clientModel = \App\Models\Client::find((int) $clientId)
                    ?? \App\Models\Client::where('legacy_id_login', (int) $clientId)->first()
                    ?? \App\Models\Client::where('user_id', (int) $clientId)->first();
            }
            if (!$clientModel) {
                return response()->json(['error' => 'Cliente nao encontrado para client_id=' . $clientId], 404);
            }
            $rawWlClientId = (int) $clientId; // MUL-076: preservar WL client_id antes da normalizacao HubAI
            $clientId = $clientModel->id; // Normaliza para o ID real da tabela clients
        }

        // Se plataforma usa bridge (goolhub), redirecionar via GoolhubBridgeService
        $marketplaceConfig = config("marketplaces.{$platform}");
        if ($marketplaceConfig && ($marketplaceConfig['method'] ?? '') === 'bridge') {
            try {
                $bridge = app(\App\Services\GoolhubBridgeService::class);
                $canalId = $marketplaceConfig['canal_id'] ?? 3;
                $supplierId = $request->get('supplier_id') ?: null;
                $userEmail = $request->user()?->email ?? $request->get('email', '');
                // Usar legacy_id_login do client como id_login no Goolhub
                $clientModel = \App\Models\Client::find((int) $clientId);
                $goolhubLoginId = $clientModel?->legacy_id_login ?? (int) $clientId;
                $goolhubDepositoId = (int) env('GOOLHUB_DEFAULT_DEPOSITO_ID', $supplierId);
                $result = $bridge->getConnectionUrl($goolhubLoginId, $goolhubDepositoId, $canalId, $userEmail);
                if (!empty($result['data']['url'])) {
                    // Criar conta com status pending ANTES do redirect (mesmo comportamento do Filament)
                    MarketplaceAccount::firstOrCreate(
                        [
                            'client_id'   => (int) $clientId,
                            'supplier_id' => $supplierId ?: null,
                            'platform'    => $platform,
                        ],
                        [
                            'status'       => 'pending',
                            'account_name' => $request->get('account_name', 'Minha Loja'),
                        ]
                    );
                    return redirect($result['data']['url']);
                }
                return $this->redirectWithError('Ponte goolhub nao retornou URL de conexao para ' . $platform);
            } catch (\Throwable $e) {
                Log::error("Bridge OAuth redirect failed [{$platform}]: " . $e->getMessage());
                return $this->redirectWithError('Erro na ponte goolhub: ' . $e->getMessage());
            }
        }

        // MUL-029: log de migracao (apenas se cliente esta na lista de migrados)
        try {
            $emailHook = $clientModel?->user?->email ?? $request->get('email', '');
            MigracaoLogger::log('oauth.redirect.init', $emailHook, [
                'platform' => $platform,
                'client_id' => $clientId,
                'legacy_id_login' => $clientModel?->legacy_id_login,
                'return_url' => $request->get('return_url'),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        } catch (\Throwable $e) {}

        $supplierId = $request->get('supplier_id') ?: null;

        // SEL-077: WL isolado (seller.global, fornecefy, mestoredrop) tem SEU proprio
        // banco com suppliers.id iniciando em N (SG: 26, MES: 25, FOR: 24). Quando o
        // front do WL manda supplier_id=1 (herdado de multdrop/hub), a FK
        // marketplace_accounts.supplier_id explode. Se supplier_id nao existe LOCAL,
        // usa LOCAL_SUPPLIER_ID do .env como fallback (o supplier proprio do WL).
        if ($supplierId !== null && $supplierId > 0) {
            $localSupplierExists = \App\Models\Supplier::whereKey($supplierId)->exists();
            if (! $localSupplierExists) {
                $fallback = (int) config('app.local_supplier_id', env('LOCAL_SUPPLIER_ID', 0));
                if ($fallback > 0 && \App\Models\Supplier::whereKey($fallback)->exists()) {
                    \Log::warning("OAuth redirect: supplier_id={$supplierId} nao existe localmente, usando LOCAL_SUPPLIER_ID={$fallback} (SEL-077)");
                    $supplierId = $fallback;
                } else {
                    \Log::warning("OAuth redirect: supplier_id={$supplierId} nao existe e LOCAL_SUPPLIER_ID invalido — supplier_id ficara NULL (SEL-077)");
                    $supplierId = null;
                }
            }
        }

        // PKCE: gerar code_verifier e code_challenge pra ML
        $codeVerifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        // Capturar return_url: redirect_after (nome canonico) > return_url (alias legado) > HTTP_REFERER > fallback
        $returnUrl = $request->get('redirect_after')
            ?? $request->get('return_url')
            ?? $request->header('Referer')
            ?? config('app.frontend_url', 'https://hubai.io') . '/integracoes';
        $sourceSystem = $request->get('source_system'); // identifica o sistema WL de origem (ex: fornecefy, multdrop)
        // NOV-046-G: validar source_system contra endpoints registrados (anti-SSRF)
        if ($sourceSystem !== null && config('bling.use_relay', false)) {
            if (! in_array($sourceSystem, array_keys(config('bling.relay_endpoints', [])), true)) {
                abort(422, 'invalid source_system');
            }
        }

        // NOV-153 (Bling supplier ERP): account_type permite distinguir conexão
        // do Bling do FORNECEDOR (account_type=supplier_erp → ErpAccount) da conexão
        // do Bling do LOJISTA (default null → MarketplaceAccount).
        $accountType = $request->get('account_type');

        $statePayload = json_encode([
            'client_id'     => (int) $clientId,
            'wl_client_id'  => $rawWlClientId ?? (int) $clientId,
            'supplier_id'   => $supplierId ?: null,
            'account_type'  => $accountType, // NOV-153: 'supplier_erp' | null
            'account_name'  => $request->get('account_name', 'Minha Loja'),
            'platform'      => $platform,
            'code_verifier' => $codeVerifier,
            'return_url'    => $returnUrl,
            'redirect_after' => $returnUrl,  // alias canonico — mesma URL, nome novo para clareza no contrato
            'source_system' => $sourceSystem, // identifica sistema WL de origem para logging
            'shop_domain'   => $request->get('shop_domain', ''),
            'origin_callback' => $platform === 'shopee' ? url('/api/oauth/shopee/hubai-relay') : url('/api/oauth/' . $platform . '/callback'),
            // FOR-021: identifica sistema de origem para suporte a redirect_after no callback
            'redirect_after'  => $returnUrl,
        ]);
        $stateHash = base64_encode($statePayload);

        $authUrl = '';

        switch ($platform) {
            case 'mercadolivre':
                $appId = config('services.mercadolivre.app_id');
                // redirect_uri DEVE ser identica a registrada no app ML
                // Usar config('services.mercadolivre.redirect_uri') garante consistencia total
                $redirectUri = config('services.mercadolivre.redirect_uri', env('ML_REDIRECT_URI'));
                $authUrl = "https://auth.mercadolivre.com.br/authorization?" . http_build_query([
                    'response_type' => 'code',
                    'client_id' => $appId,
                    'redirect_uri' => $redirectUri,
                    'state' => $stateHash,
                    'code_challenge' => $codeChallenge,
                    'code_challenge_method' => 'S256',
                ]);
                break;

            case 'bling':
                // MUL-411: com app proprio configurado, a conexao nova sai por ele e vai
                // direto ao NOSSO callback — sem passar pelo hub. O relay so existia porque
                // o app compartilhado aceita uma unica redirect_uri (api.hubai.io).
                $blingAppNovo = (string) config('bling.app_novo.client_id', '');

                if ($blingAppNovo !== '') {
                    $blingClientId = $blingAppNovo;
                    $redirectUri   = config('bling.app_novo.redirect_uri');
                } else {
                    $blingClientId = config('bling.client_id');
                    // MUL-029-2: se relay esta ativo, FORCAR redirect_uri pra hub central api.hubai.io.
                    // Bling so aceita 1 redirect_uri por app — todas as WLs vao pelo mesmo callback,
                    // que troca code->tokens e relay HMAC pro WL de origem (identificado por source_system).
                    $redirectUri = config('bling.use_relay', false)
                        ? 'https://api.hubai.io/bling/callback'
                        : config('bling.redirect_uri');
                }
                $authUrl = config('bling.auth_url') . '?' . http_build_query([
                    'response_type' => 'code',
                    'client_id' => $blingClientId,
                    'redirect_uri' => $redirectUri,
                    'state' => $stateHash,
                ]);
                break;

            case 'shopee':
                // NOV-033: service e return_url dinamicos por sistema via SHOPEE_OAUTH_SERVICE no .env
                // Valores: hubai (HubAI) | multdrop (MultDrop) | fornecefy (Fornecefy)
                $shopeeService = config("services.shopee.oauth_service", "hubai");
                $shopeeReturnUrl = $request->get("return_url")
                    ?? (config("app.frontend_url", "https://hubai.io") . "/integracoes");
                // NOV-180: supplier_id explicito do request; fallback 30 SOMENTE quando origem multdrop
                $shopeeSupplierId = $request->get("supplier_id")
                    ?: ($sourceSystem === "multdrop" ? (int) config("multdrop.supplier_id", 30) : null);
                // MUL-314: o GET /api/shopee/oauth/init responde 410 desde a SEL-326
                // Fase D (rota publica com user_id cru no query dava pra iniciar OAuth em
                // nome de qualquer cliente). Este caminho nunca migrou -- 31 conexoes
                // Shopee falharam em 24h, 28 do hubai e 3 do multdrop.
                //
                // Agora o proprio backend do WL emite o JWT e chama o POST server-to-server.
                // Feito aqui, e nao no front, por dois motivos: o JWT nunca chega ao browser,
                // e esta rota e navegacao de pagina (o browser nao manda o Bearer do Sanctum).
                $shopeeUserId = (int) (\App\Models\Client::where('id', $clientId)->value('user_id') ?: 0);
                if ($shopeeUserId <= 0) {
                    Log::channel('marketplace')->error('[Shopee Init MUL-314] client sem user_id', [
                        'client_id' => $clientId,
                    ]);
                    return $this->redirectWithError('Nao foi possivel identificar sua conta. Fale com o suporte.', $shopeeReturnUrl);
                }

                $shopeeJwt = \App\Services\Shopee\ShopeeInitTokenService::emitir($shopeeUserId);
                if (! $shopeeJwt) {
                    Log::channel('marketplace')->error('[Shopee Init MUL-314] SHOPEE_INIT_JWT_SECRET ausente neste backend');
                    return $this->redirectWithError('Integracao Shopee indisponivel no momento. Fale com o suporte.', $shopeeReturnUrl);
                }

                $shopeeHub = rtrim((string) (config('app.oauth_relay_url') ?: 'https://api.hubai.io'), '/');
                try {
                    $shopeeResp = Http::timeout(15)
                        ->withToken($shopeeJwt)
                        ->acceptJson()
                        ->post($shopeeHub . '/api/shopee/oauth/init', [
                            'supplier_id'   => $shopeeSupplierId,
                            'return_url'    => $shopeeReturnUrl,
                            'source_system' => $shopeeService,
                            // MUL-314: nome digitado pelo seller viaja ate o state e volta no relay
                            'account_name'  => $request->get('account_name'),
                        ]);
                } catch (\Throwable $e) {
                    Log::channel('marketplace')->error('[Shopee Init MUL-314] POST ao hub falhou', [
                        'erro' => $e->getMessage(), 'hub' => $shopeeHub,
                    ]);
                    return $this->redirectWithError('Nao foi possivel falar com a Shopee agora. Tente de novo em instantes.', $shopeeReturnUrl);
                }

                $shopeeUrl = $shopeeResp->json('shopee_url');
                if (! $shopeeResp->successful() || ! $shopeeUrl) {
                    Log::channel('marketplace')->error('[Shopee Init MUL-314] hub recusou o init', [
                        'status' => $shopeeResp->status(),
                        'corpo'  => mb_substr((string) $shopeeResp->body(), 0, 500),
                    ]);
                    return $this->redirectWithError('A Shopee recusou a conexao. Tente de novo ou fale com o suporte.', $shopeeReturnUrl);
                }

                return redirect()->away($shopeeUrl);

            case 'aliexpress':
                $connector = app(\App\Services\Drop\Suppliers\AliExpressConnector::class);
                $url = $connector->getOAuthUrl(session()->getId());
                return redirect($url);

            case 'aliexpress':
                $aliConnector = app(\App\Services\Drop\Suppliers\AliExpressConnector::class);
                $tokens = $aliConnector->exchangeOAuthCode($request->get('code', ''));
                $aliAccessToken  = $tokens['access_token'] ?? null;
                $aliRefreshToken = $tokens['refresh_token'] ?? null;
                $aliExpiresIn    = $tokens['expire_time'] ?? null; // timestamp Unix de expiracao

                \Illuminate\Support\Facades\Log::info('AliExpress OAuth sucesso', [
                    'access_token_preview' => $aliAccessToken ? substr($aliAccessToken, 0, 20) . '...' : null,
                    'expires_in'           => $aliExpiresIn,
                ]);

                if ($aliAccessToken && auth()->check()) {
                    // Salvar token na DropStore nativa do usuario (platform=native ou a primeira disponivel)
                    $dropStore = \App\Models\Drop\DropStore::where('client_id', auth()->user()->client_id ?? auth()->id())
                        ->orderByDesc('id')
                        ->first();

                    if ($dropStore) {
                        $dropStore->update([
                            'access_token'  => $aliAccessToken,
                            'status'        => 'active',
                        ]);

                        \Illuminate\Support\Facades\Log::info('AliExpress token salvo na DropStore', [
                            'drop_store_id' => $dropStore->id,
                        ]);
                    } else {
                        // Nao tem DropStore ainda — salvar na config do modulo Drop
                        \App\Models\Drop\DropModuleConfig::updateOrCreate(
                            ['client_id' => auth()->user()->client_id ?? auth()->id()],
                            ['aliexpress_access_token' => encrypt($aliAccessToken)]
                        );
                    }
                }

                return $this->redirectWithSuccess('AliExpress conectado com sucesso!');

            case 'shopify':
                $shopifyKey = config('services.shopify.api_key');
                $shopDomain = $request->get('shop_domain', '');
                if (!$shopDomain) {
                    return $this->redirectWithError('Informe o dominio da loja Shopify (ex: minhaloja.myshopify.com)');
                }
                $scopes = config('services.shopify.scopes');
                $redirectUri = config('services.shopify.redirect_uri');
                $authUrl = "https://{$shopDomain}/admin/oauth/authorize?" . http_build_query([
                    'client_id'    => $shopifyKey,
                    'scope'        => $scopes,
                    'redirect_uri' => $redirectUri,
                    'state'        => $stateHash,
                ]);
                break;

            case 'hubaisimulator':
                $redirectUri = url("/api/oauth/hubaisimulator/callback?state={$stateHash}&code=sandbox_fake_code_aprovado");
                $authUrl = $redirectUri;
                break;

            default:
                return response()->json(['error' => 'Plataforma não suportada'], 400);
        }

        // NOV-153: supplier_erp não usa MarketplaceAccount — pular criação de pending
        if ($accountType !== 'supplier_erp') {
            // FOR-039: se ja existe conta ativa com supplier_id=null (legado pre-supplier_id obrigatorio),
            // atualizar o supplier_id em vez de criar pending orfao.
            $legacyOauthActive = MarketplaceAccount::where('client_id', (int) $clientId)
                ->where('platform', $platform)
                ->whereIn('status', ['active', 'needs_reauth'])
                ->whereNull('supplier_id')
                ->first();
    
            if ($legacyOauthActive && $supplierId) {
                $legacyOauthActive->update(['supplier_id' => $supplierId]);
            } else {
                // Criar conta com status pending ANTES do redirect (mesmo comportamento do Filament)
                MarketplaceAccount::firstOrCreate(
                    [
                        'client_id'   => (int) $clientId,
                        'supplier_id' => $supplierId ?: null,
                        'platform'    => $platform,
                    ],
                    [
                        'status'       => 'pending',
                        'account_name' => $request->get('account_name', 'Minha Loja'),
                    ]
                );
            }
        }

        return new \Illuminate\Http\RedirectResponse($authUrl);
    }

    #[OA\Get(
        path: '/api/oauth/{platform}/callback',
        operationId: 'oauthCallback',
        summary: 'Callback OAuth apos autorizacao na plataforma',
        description: 'Recebe o authorization code e o state PKCE retornados pela plataforma. Troca o code por tokens reais, busca o perfil do vendedor, cria ou atualiza a MarketplaceAccount (atualizando o registro pending criado em /redirect para status=active) e redireciona o usuario para o painel hubai.io. Em caso de erro, redireciona com query param ?error=.',
        tags: ['OAuth'],
        parameters: [
            new OA\Parameter(
                name: 'platform',
                in: 'path',
                required: true,
                description: 'Plataforma que retornou o callback',
                schema: new OA\Schema(type: 'string', enum: ['mercadolivre', 'bling', 'shopee', 'shopify']),
                example: 'mercadolivre'
            ),
            new OA\Parameter(
                name: 'code',
                in: 'query',
                required: true,
                description: 'Authorization code retornado pela plataforma',
                schema: new OA\Schema(type: 'string'),
                example: 'TG-abc123def456'
            ),
            new OA\Parameter(
                name: 'state',
                in: 'query',
                required: true,
                description: 'State PKCE Base64 gerado em /redirect',
                schema: new OA\Schema(type: 'string'),
                example: 'eyJjbGllbnRfaWQiOjEsInBsYXRmb3JtIjoibWVyY2Fkb2xpdnJlIn0='
            ),
        ],
        responses: [
            new OA\Response(
                response: 302,
                description: 'Redirect para hubai.io/painel-cliente/integracoes?connected={platform} em caso de sucesso ou ?error= em caso de falha'
            ),
        ]
    )]
    /**
     * Callback OAuth. Troca code por tokens REAIS e salva MarketplaceAccount.
     */
    public function callback(Request $request, string $platform)
    {
        $state = $request->get('state');
        if (!$state) {
            return $this->redirectWithError('State ausente na resposta de autorizacao');
        }

        // FLUXO LEGADO ML: state = "legado:{nonce}" — PKCE via bridge ml_oauth_pop.php
        if (str_starts_with($state, 'legado:')) {
            return $this->handleLegadoMLCallback($request->get('code', ''), $state);
        }

        $payload = json_decode(base64_decode($state), true);
        if (!$payload || !isset($payload['client_id'])) {
            return $this->redirectWithError('State corrompido ou invalido');
        }

        // Proxy whitelabel: se o state traz origin_callback (backend do whitelabel),
        // este callback e apenas um relay — repassar code+state pro backend real.
        if (!empty($payload['origin_callback'])) {
            $selfHost     = parse_url(config('app.url'), PHP_URL_HOST);
            $relayHost    = config('app.oauth_relay_url') ? parse_url(config('app.oauth_relay_url'), PHP_URL_HOST) : null;
            $frontendHost = parse_url(config('app.frontend_url'), PHP_URL_HOST);
            // Dominios extras confiáveis (whitelabels/parceiros) via variável OAUTH_RELAY_TRUSTED_DOMAINS
            // Ex: OAUTH_RELAY_TRUSTED_DOMAINS=api.fornecefy.io,api.outrosite.com
            $extraTrusted = config('app.oauth_relay_trusted_domains', []);
            $trustedDomains = array_filter(array_unique(array_merge([$selfHost, $relayHost, $frontendHost], $extraTrusted)));
            $host = parse_url($payload['origin_callback'], PHP_URL_HOST);
            $selfHost = parse_url(url("/"), PHP_URL_HOST);
            if ($host && in_array($host, $trustedDomains) && $host !== $selfHost) {
                $code = $request->get('code');
                $error = $request->get('error');
                $target = $payload['origin_callback'] . '?' . http_build_query(array_filter([
                    'code'  => $code,
                    'state' => $state,
                    'error' => $error,
                ]));
                return redirect($target);
            }
        }

        $clientId = $payload['client_id'];
        $supplierId = $payload['supplier_id'] ?? null;
        // MUL-078: fallback supplier_id quando NULL no state OAuth.
        // Bug origem: contas como Vaneide ML (mkt acct id=44 no MultDrop) ficavam com
        // supplier_id=NULL, o que quebra TenantSupplierScope nos jobs de sync.
        if (($payload['source_system'] ?? null) === 'multdrop') {
            // NOV-180: fallback 30 restrito a origem multdrop (antes marcava TODA conexao como multdrop)
            // MUL-183: SEMPRE usar config, nunca o supplier_id cru da WL — o valor da WL vive em
            // outro espaco de IDs e ja gravou tokens no supplier errado do hub (incidente conta 694)
            $configSupplierId = (int) config('multdrop.supplier_id', 30);
            if ($supplierId && (int) $supplierId !== $configSupplierId) {
                \Log::warning("OAuth callback [{$platform}]: supplier_id cru da WL ({$supplierId}) difere do config ({$configSupplierId}) — usando config (MUL-183, client_id={$clientId})");
            }
            $supplierId = $configSupplierId;
        }
        $accountType = $payload['account_type'] ?? null; // NOV-153: 'supplier_erp' | null
        $codeVerifier = $payload['code_verifier'] ?? null;
        $code = $request->get('code');

        if (!$code) {
            return $this->redirectWithError('Codigo de autorizacao ausente', $payload['return_url'] ?? null);
        }

        try {
            $tokenData = $this->exchangeCodeForTokens($platform, $code, $codeVerifier, ['shop_domain' => $payload['shop_domain'] ?? '', 'shop_id' => $request->get('shop_id', '')]);
        } catch (\Throwable $e) {
            Log::error("OAuth token exchange failed [{$platform}]: " . $e->getMessage());
            return $this->redirectWithError('Erro ao trocar codigo por token: ' . $e->getMessage(), $payload['return_url'] ?? null);
        }

        // Dados extras por plataforma (nickname, user_id, etc)
        $extraFields = $this->fetchPlatformProfile($platform, $tokenData);

        // redirect_after (nome canonico novo) tem prioridade sobre return_url (alias legado)
        $returnUrl = $payload['redirect_after'] ?? $payload['return_url'] ?? config('app.frontend_url', 'https://hubai.io') . '/integracoes';
        // Sanitizar: aceitar mesmo dominio, dominios conhecidos ou qualquer HTTPS (whitelabel)
        $frontendHost = parse_url(config('app.frontend_url'), PHP_URL_HOST);
        $apiHost      = parse_url(config('app.url'), PHP_URL_HOST);
        $relayHost    = config('app.oauth_relay_url') ? parse_url(config('app.oauth_relay_url'), PHP_URL_HOST) : null;
        $allowedHosts = array_filter(array_unique([$frontendHost, $apiHost, $relayHost, 'localhost']));
        $parsedHost = parse_url($returnUrl, PHP_URL_HOST);
        if ($parsedHost && !in_array($parsedHost, $allowedHosts)) {
            if (!str_starts_with($returnUrl, 'https://')) {
                $returnUrl = config('app.frontend_url', 'https://hubai.io') . '/integracoes';
            }
            // HTTPS externo = whitelabel, aceitar
        }

        // Garantir que o Client existe. Se nao existe, criar com user admin (ID 1) como owner.
        // O client_id vindo do frontend pode ser o user_id do goolhub (ex: 267076) que nao existe como Client.
        $client = Client::find($clientId);
        if (!$client) {
            // Tenta encontrar user pelo email do perfil da plataforma
            $profileEmail = $extraFields['email'] ?? null;
            $ownerUser    = $profileEmail ? \App\Models\User::where('email', $profileEmail)->first() : null;

            if (!$ownerUser) {
                // Fallback: admin (ID 1). Logar para rastrear e corrigir via SSO futuro.
                Log::warning("OAuth [{$platform}]: nao encontrou user para client_id={$clientId} email={$profileEmail}. Usando admin fallback.");
                $ownerUser = \App\Models\User::find(1);
            }

            // MUL-269 fase 2: company_name removido de clients — nome vem do user (accessor).
            $client = Client::updateOrCreate(
                ['user_id' => $ownerUser?->id ?? 1],
                [
                    'document'     => (string) $clientId,
                    'is_active'    => true,
                ]
            );
            Log::info("Auto-created Client #{$client->id} (goolhub user {$clientId}) during OAuth [{$platform}]");
        }

        // NOV-153: Bling do FORNECEDOR (ERP) — desvia pra ErpAccount em vez de MarketplaceAccount.
        // O Bling do fornecedor é usado para importar/exportar produtos e estoque entre
        // a plataforma e o ERP do fornecedor — NÃO é uma conta de vendedor (lojista).
        // Ainda assim, queremos relayar tokens via HMAC pra WL local (se source_system != tenant)
        // pra manter a credencial sob domínio da WL correta.
        if ($platform === 'bling' && $accountType === 'supplier_erp' && $supplierId) {
            // 1. Salva localmente em erp_accounts (ou relaya se for WL remota)
            if (config('bling.use_relay', false)) {
                $sourceSystem = $payload['source_system'] ?? null;
                if ($sourceSystem && $sourceSystem !== config('bling.app_tenant', 'hubai')) {
                    // WL remota: encaminhar tokens via HMAC (BlingRelayController desvia pra ErpAccount)
                    try {
                        $this->relayBlingTokenToWL(
                            $sourceSystem,
                            $client,
                            (int) $supplierId,
                            $tokenData,
                            $extraFields['account_name'] ?? $payload['account_name'] ?? 'Bling ERP',
                            'supplier_erp'
                        );
                    } catch (\Throwable $e) {
                        Log::error('[Bling Relay supplier_erp] Falha — enfileirando retry', [
                            'error'         => $e->getMessage(),
                            'source_system' => $sourceSystem,
                            'supplier_id'   => $supplierId,
                        ]);
                        try {
                            \App\Jobs\RelayBlingTokenRetryJob::dispatch(
                                tenant: $sourceSystem,
                                clientId: $client->id,
                                supplierId: (int) $supplierId,
                                tokenData: $tokenData,
                                accountName: $extraFields['account_name'] ?? $payload['account_name'] ?? 'Bling ERP',
                                secret: (string) config('bling.relay_secret', ''),
                                endpoint: config('bling.relay_endpoints.' . $sourceSystem, ''),
                                accountType: 'supplier_erp',
                            );
                        } catch (\Throwable $dispatchEx) {
                            Log::critical('[Bling Relay supplier_erp] Falha ao enfileirar retry', [
                                'error' => $dispatchEx->getMessage(),
                            ]);
                        }
                    }
                    return redirect("{$returnUrl}?connected=bling_erp");
                }
            }
            // Tenant local OU relay desabilitado: salvar diretamente em erp_accounts.
            return $this->handleBlingSupplierErp((int) $supplierId, $tokenData, $extraFields, $payload, $returnUrl);
        }

        // Verificar limite de conexoes do plano antes de criar nova conta
        $existingCount = MarketplaceAccount::where('client_id', $client->id)->count();
        $activeSub = \App\Models\Subscription::where('client_id', $client->id)
            ->where('status', 'active')
            ->with('plan')
            ->latest()
            ->first();
        $maxConnections = $activeSub?->plan?->max_marketplace_connections;
        // MUL-098: Conta como nova somente se nao existe conta com MESMO seller_id/shop_id.
        // Permite multiplas contas ML/Shopee do mesmo lojista (contas diferentes = seller_id diferente).
        // Discriminador: ML = ml_user_id, Shopee = shop_id, demais = supplier_id+platform (1 por plataforma).
        $incomingSellerId = $extraFields['ml_user_id'] ?? $extraFields['shop_id'] ?? null;
        if ($incomingSellerId) {
            $alreadyExists = MarketplaceAccount::where('client_id', $client->id)
                ->where('platform', $platform)
                ->where(function ($q) use ($platform, $incomingSellerId) {
                    if ($platform === 'mercadolivre') {
                        $q->where('ml_user_id', (string) $incomingSellerId);
                    } else {
                        $q->where('shop_id', (string) $incomingSellerId);
                    }
                })
                ->exists();
        } else {
            $alreadyExists = MarketplaceAccount::where('client_id', $client->id)
                ->where('supplier_id', $supplierId)
                ->where('platform', $platform)
                ->exists();
        }
        if ($maxConnections !== null && !$alreadyExists && $existingCount >= $maxConnections) {
            return $this->redirectWithError(
                "Limite de {$maxConnections} conexoes atingido. Faca upgrade.",
                $returnUrl
            );
        }

        // MUL-029: log de migracao no callback
        try {
            MigracaoLogger::log('oauth.callback.success', $client, [
                'platform' => $platform,
                'client_id' => $client->id,
                'supplier_id' => $supplierId,
                'has_access_token' => !empty($tokenData['access_token']),
                'token_expires_in' => $tokenData['expires_in'] ?? null,
                'extra_fields_keys' => array_keys($extraFields),
            ]);
        } catch (\Throwable $e) {}

        // Salvar MarketplaceAccount com tokens reais
        $accountData = array_merge([
            'status'                => 'active',
            'last_token_refresh_at' => now(),
            'sync_blocked_at'       => null,
            'sync_errors_count'     => 0,
            // PR5: limpar flag de token quebrado ao reconectar com sucesso
            'is_token_broken'       => 0,
            'token_broken_reason'   => null,
            'token_broken_at'       => null,
        ], $this->buildTokenFields($platform, $tokenData), $extraFields);

        // MUL-098: chave de upsert usa discriminador por plataforma.
        // ML: ml_user_id identifica conta unica do vendedor (permite N contas por lojista).
        // Shopee: shop_id identifica loja unica.
        // Demais plataformas (Bling, Shopify): 1 conta por lojista/supplier (comportamento original).
        $upsertKey = match($platform) {
            'mercadolivre' => isset($extraFields['ml_user_id']) ? [
                'client_id'  => $client->id,
                'platform'   => $platform,
                'ml_user_id' => (string) $extraFields['ml_user_id'],
            ] : [
                'client_id'   => $client->id,
                'supplier_id' => $supplierId,
                'platform'    => $platform,
            ],
            'shopee' => isset($extraFields['shop_id']) ? [
                'client_id' => $client->id,
                'platform'  => $platform,
                'shop_id'   => (string) $extraFields['shop_id'],
            ] : [
                'client_id'   => $client->id,
                'supplier_id' => $supplierId,
                'platform'    => $platform,
            ],
            default => [
                'client_id'   => $client->id,
                'supplier_id' => $supplierId,
                'platform'    => $platform,
            ],
        };
        // Garantir supplier_id na conta quando nao e chave do upsert
        $accountData['supplier_id'] = $supplierId;
        // SEL-375: com OAUTH_CONFIRM_BEFORE_ACTIVE=true (flag por tenant no .env),
        // conta NOVA nasce pending_confirm e so vira active depois do dono
        // confirmar o nickname no front (POST /api/v1/marketplace/confirm).
        // Reconexao de conta ja existente vai direto pra active (nao quebra sync).
        $confirmBeforeActive = env('OAUTH_CONFIRM_BEFORE_ACTIVE', false)
            && !MarketplaceAccount::where($upsertKey)->exists();
        if ($confirmBeforeActive) {
            $accountData['status'] = 'pending_confirm';
        }
        // MUL-314: precedencia do nome da conta. Antes o $extraFields (nome vindo da
        // plataforma) era o ULTIMO do array_merge e ganhava sempre -- por isso o nome
        // digitado ao criar sumia, e reconectar desfazia o nome que o seller tinha
        // editado depois. Ordem que passa a valer:
        //   1. nome digitado agora  2. nome ja salvo na conta  3. nome da plataforma
        $nomeDigitado = trim((string) ($payload['account_name'] ?? ''));
        if ($nomeDigitado !== '' && $nomeDigitado !== 'Minha Loja') {
            $accountData['account_name'] = $nomeDigitado;
        } else {
            $nomeSalvo = MarketplaceAccount::where($upsertKey)->value('account_name');
            if (! empty($nomeSalvo)) {
                $accountData['account_name'] = $nomeSalvo;
            }
        }

        $account = MarketplaceAccount::updateOrCreate($upsertKey, $accountData);

        // NOV-156: limpa registros pending/fantasma duplicados para este client+platform.
        // O updateOrCreate acima ja cobre o caso exato (mesmo supplier_id), mas pode haver
        // contas pending com supplier_id diferente (ex.: redirects sucessivos sem conclusao).
        MarketplaceAccount::where('client_id', $client->id)
            ->where('platform', $platform)
            ->where('id', '!=', $account->id)
            ->where(function ($q) {
                $q->where('status', 'pending')->orWhereNull('seller_id');
            })
            ->whereNull('access_token')
            ->whereNull('ml_access_token')
            ->whereNull('bling_access_token')
            ->delete();

        // NOV-087: limpar bloqueio de reauth apos reconexao bem-sucedida
        $this->clearReauthBlock($account);

        Log::info("OAuth [{$platform}] conectado. Client: {$clientId} | Supplier: {$supplierId} | Account: {$account->id}");

        // Webhook automatico pro ML
        if ($platform === 'mercadolivre') {
            try {
                $callbackUrl = url('/api/webhooks/mercadolivre');
                app(MercadoLivreService::class)->configureWebhook($account, $callbackUrl);
            } catch (\Throwable $e) {
                Log::warning("ML webhook config failed: " . $e->getMessage());
            }

            // FOR-053-D: capturar identification_type (CPF/CNPJ) do vendedor pra decidir
            // depois se aviso de invoice_pending mostra DC-e (CPF) ou NF-e (CNPJ).
            try {
                if ($account->ml_user_id && ($tokenData['access_token'] ?? null)) {
                    $me = \Illuminate\Support\Facades\Http::withToken($tokenData['access_token'])
                        ->get("https://api.mercadolibre.com/users/{$account->ml_user_id}")
                        ->json();
                    $idType = $me['identification']['type'] ?? null;
                    $idNum  = $me['identification']['number'] ?? null;
                    if ($idType) {
                        $account->update([
                            'identification_type'   => $idType,
                            'identification_number' => $idNum,
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("ML identification fetch failed: " . $e->getMessage());
            }

            // Migração lazy ML legado→NovoHubAI:
            // Se o client_id do state corresponde a um legacy_id_login no legado,
            // sincronizar tokens imediatamente no bridge do legado via HMAC.
            // (HUB-073, 2026-06-19)
            $this->relayMLTokenToLegado($client, $account, $tokenData);
        }

        if ($platform === 'bling') {
            // MUL-091: seller_id fallback — /usuarios/me retorna 403 no scope atual.
            // Usar account->id como identificador unico para routing de webhooks.
            // BlingWebhookHandler le ?seller_id= da URL; ProcessBlingWebhookJob faz where('seller_id', X).
            if (! $account->seller_id) {
                $account->update(['seller_id' => (string) $account->id]);
                $account->refresh();
            }

            try {
                $blingCallbackUrl = url('/api/webhooks/bling');
                app(BlingAuthService::class)->configureWebhooks($account, $blingCallbackUrl);
            } catch (\Throwable $e) {
                Log::warning("Bling webhook config failed: " . $e->getMessage());
            }

            // MUL-029-2: Relay Bling pra WL de origem (multdrop/fornecefy/hubai).
            // Como Bling so aceita 1 redirect_uri por app, todas as WLs usam api.hubai.io/bling/callback.
            // Aqui o hubai.io ja trocou code->tokens (tem o client_secret), entao
            // empurra os tokens pro WL via HMAC POST. Feature-flag: BLING_USE_RELAY.
            // Tenant identificado por source_system no state OAuth.
            if (config('bling.use_relay', false)) {
                try {
                    $sourceSystem = $payload['source_system'] ?? null;
                    $wlClientId = isset($payload['wl_client_id']) ? (int) $payload['wl_client_id'] : null;
                    if ($sourceSystem && $sourceSystem !== config('bling.app_tenant', 'hubai')) {
                        // MUL-188: persistir origem WL pra push pos-renovacao central (TokenRefreshService)
                        $account->update(['service' => $sourceSystem, 'wl_client_id' => $wlClientId]);
                        $this->relayBlingTokenToWL(
                            $sourceSystem,
                            $client,
                            $supplierId,
                            $tokenData,
                            $extraFields['account_name'] ?? $payload['account_name'] ?? 'Bling',
                            null, // account_type=null → fluxo lojista (MarketplaceAccount na WL)
                            $wlClientId
                        );
                    } else {
                        Log::info('[Bling Relay] Skip — sem source_system ou tenant local', [
                            'source_system' => $sourceSystem,
                            'app_tenant'    => config('bling.app_tenant', 'hubai'),
                            'client_id'     => $client->id,
                        ]);
                    }
                } catch (\Throwable $e) {
                    // NOV-046-G: em vez de só logar, enfileira retry com backoff exponencial.
                    // Preserva Log::error para rastreabilidade imediata + Job para persistência.
                    Log::error('[Bling Relay] Falha ao relayar tokens pra WL — enfileirando retry', [
                        'error'         => $e->getMessage(),
                        'source_system' => $payload['source_system'] ?? null,
                        'client_id'     => $client->id,
                    ]);
                    try {
                        \App\Jobs\RelayBlingTokenRetryJob::dispatch(
                            tenant: $sourceSystem,
                            clientId: $client->id,
                            supplierId: $supplierId,
                            tokenData: $tokenData,
                            accountName: $extraFields['account_name'] ?? $payload['account_name'] ?? 'Bling',
                            secret: (string) config('bling.relay_secret', ''),
                            endpoint: config('bling.relay_endpoints.' . $sourceSystem, ''),
                            accountType: null, // fluxo lojista padrão
                        );
                    } catch (\Throwable $dispatchEx) {
                        Log::critical('[Bling Relay] Falha ao enfileirar retry job', [
                            'error'     => $dispatchEx->getMessage(),
                            'client_id' => $client->id,
                        ]);
                    }
                    // Nao bloqueia o redirect — usuario ainda volta pra WL
                }
            }
        }

        // Auto-sync: importar produtos e pedidos dos ultimos 90d ao conectar conta nova
        // SEL-375: quando aguarda confirmacao, o import so dispara no confirm (accept).
        if (in_array($platform, ['mercadolivre', 'shopee']) && !$confirmBeforeActive) {
            try {
                \App\Jobs\ImportMarketplaceAccountDataJob::dispatch($account->id)->onQueue('default');
                Log::info('[OAuth] ImportMarketplaceAccountDataJob dispatched', ['account_id' => $account->id, 'platform' => $platform]);
            } catch (\Throwable $e) {
                Log::warning('[OAuth] Falha ao disparar ImportMarketplaceAccountDataJob: ' . $e->getMessage());
            }
        }

        // Redireciona pro return_url do state (dinamico — suporta multi-dominio e whitelabel)
        // SEL-375: rota de confirmacao — token opaco em cache 30min + nickname pro modal do front.
        if ($confirmBeforeActive) {
            $confirmToken = \Illuminate\Support\Str::random(40);
            \Illuminate\Support\Facades\Cache::put("oauth_confirm_{$confirmToken}", $account->id, now()->addMinutes(30));
            $nickname = $account->seller_nickname ?? $account->account_name ?? '';
            return redirect("{$returnUrl}?connected={$platform}&confirm_token={$confirmToken}&nickname=" . urlencode($nickname));
        }
        return redirect("{$returnUrl}?connected={$platform}");
    }

    /**
     * Troca o authorization code por tokens REAIS da plataforma.
     */
    private function exchangeCodeForTokens(string $platform, string $code, ?string $codeVerifier = null, array $extras = []): array
    {
        switch ($platform) {
            case 'mercadolivre':
                $body = [
                    'grant_type' => 'authorization_code',
                    'client_id' => config('services.mercadolivre.app_id'),
                    'client_secret' => config('services.mercadolivre.secret_key'),
                    'code' => $code,
                    // Identica a ML_REDIRECT_URI — garante consistencia com etapa de autorizacao
                    'redirect_uri' => config('services.mercadolivre.redirect_uri', env('ML_REDIRECT_URI')),
                ];
                // PKCE: incluir code_verifier se disponivel
                if ($codeVerifier) {
                    $body['code_verifier'] = $codeVerifier;
                }
                $response = Http::asForm()->post('https://api.mercadolibre.com/oauth/token', $body);

                if ($response->failed()) {
                    throw new \RuntimeException('ML token exchange failed: ' . $response->body());
                }
                return $response->json();

            case 'bling':
                return app(BlingAuthService::class)->exchangeCode($code);

            case 'hubaisimulator':
                return [
                    'access_token' => 'sim_access_' . rand(10000, 99999),
                    'refresh_token' => 'sim_refresh_' . rand(10000, 99999),
                    'expires_in' => 21600,
                ];

            case 'aliexpress':
                $connector = app(\App\Services\Drop\Suppliers\AliExpressConnector::class);
                $url = $connector->getOAuthUrl(session()->getId());
                return redirect($url);

            case 'shopify':
                $shopDomain = $extras['shop_domain'] ?? '';
                $body = [
                    'client_id'     => config('services.shopify.api_key'),
                    'client_secret' => config('services.shopify.api_secret'),
                    'code'          => $code,
                ];
                $response = Http::post("https://{$shopDomain}/admin/oauth/access_token", $body);
                if ($response->failed()) {
                    throw new \RuntimeException('Shopify token exchange failed: ' . $response->body());
                }
                return $response->json();

            case 'shopee':
                // Shopee OAuth v2 — troca authorization code por access_token
                // Ref: https://open.shopee.com/documents/v2/v2.auth.token.get
                $shopeePartnerId = (int) env('SHOPEE_PARTNER_ID');
                $shopeePartnerKey = env('SHOPEE_PARTNER_KEY');
                $shopeeTimestamp = time();
                $shopeePath = '/api/v2/auth/token/get';
                $shopeeSign = hash_hmac('sha256', $shopeePartnerId . $shopeePath . $shopeeTimestamp, $shopeePartnerKey);
                $shopeeShopId = (int) ($extras['shop_id'] ?? 0);
                $shopeeBody = [
                    'code'       => $code,
                    'partner_id' => $shopeePartnerId,
                    'timestamp'  => $shopeeTimestamp,
                    'sign'       => $shopeeSign,
                ];
                if ($shopeeShopId) {
                    $shopeeBody['shop_id'] = $shopeeShopId;
                }
                $shopeeResponse = Http::timeout(30)->post('https://partner.shopeemobile.com/api/v2/auth/token/get', $shopeeBody);
                if ($shopeeResponse->failed() || !empty($shopeeResponse->json()['error'])) {
                    throw new \RuntimeException('Shopee token exchange failed: ' . $shopeeResponse->body());
                }
                return $shopeeResponse->json();

            default:
                throw new \RuntimeException("Token exchange não implementado para {$platform}");
        }
    }

    /**
     * Busca perfil da plataforma (nickname, user_id, etc) apos obter tokens.
     */
    private function fetchPlatformProfile(string $platform, array $tokenData): array
    {
        if ($platform === 'mercadolivre' && isset($tokenData['access_token'])) {
            try {
                $me = Http::withToken($tokenData['access_token'])
                    ->get('https://api.mercadolibre.com/users/me')
                    ->json();

                return [
                    'ml_user_id' => $me['id'] ?? null,
                    'seller_nickname' => $me['nickname'] ?? null,
                    'seller_id' => (string) ($me['id'] ?? ''),
                    'account_name' => $me['nickname'] ?? 'Minha Loja ML',
                ];
            } catch (\Throwable $e) {
                Log::warning("ML profile fetch failed: " . $e->getMessage());
            }
        }


        if ($platform === 'shopee' && isset($tokenData['access_token'])) {
            try {
                $spId   = (int) env('SHOPEE_PARTNER_ID');
                $spKey  = env('SHOPEE_PARTNER_KEY');
                $shopId = (int) ($tokenData['shop_id'] ?? 0);
                $spToken = $tokenData['access_token'];
                $shopName = 'Loja Shopee #' . $shopId;

                if ($shopId && $spToken && $spId && $spKey) {
                    $spPath = '/api/v2/shop/get_shop_info';
                    $spTs   = time();
                    $spBase = $spId . $spPath . $spTs . $spToken . $shopId;
                    $spSign = hash_hmac('sha256', $spBase, $spKey);
                    $infoResp = Http::timeout(15)->get(
                        'https://partner.shopeemobile.com/api/v2/shop/get_shop_info',
                        ['partner_id' => $spId, 'timestamp' => $spTs,
                         'access_token' => $spToken, 'shop_id' => $shopId, 'sign' => $spSign]
                    );
                    if ($infoResp->ok()) {
                        $shopInfo = $infoResp->json('response') ?? [];
                        $shopName = $shopInfo['shop_name'] ?? $shopName;
                    }
                }

                return [
                    'shop_id'         => (string) $shopId,
                    'seller_id'       => (string) $shopId,
                    'seller_nickname' => $shopName,
                    'account_name'    => $shopName,
                ];
            } catch (\Throwable $e) {
                Log::warning('Shopee profile fetch failed: ' . $e->getMessage());
            }
        }


        if ($platform === 'bling' && isset($tokenData['access_token'])) {
            try {
                $me     = \App\Services\Integrations\Erps\Bling\BlingAuthService::getUserProfile($tokenData['access_token']);
                $userId = (string) ($me['data']['id'] ?? '');
                $nome   = $me['data']['nome'] ?? 'Minha Conta Bling';
                if ($userId) {
                    return [
                        'seller_id'    => $userId,
                        'account_name' => $nome,
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning('Bling profile fetch failed: ' . $e->getMessage());
            }
        }

        return [];
    }

    /**
     * Monta os campos de token corretos por plataforma (todos encrypted).
     */
    private function buildTokenFields(string $platform, array $tokenData): array
    {
        $expiresIn = $tokenData['expires_in'] ?? 21600;

        switch ($platform) {
            case 'mercadolivre':
                return [
                    'ml_access_token' => encrypt($tokenData['access_token']),
                    'ml_refresh_token' => encrypt($tokenData['refresh_token'] ?? ''),
                    'ml_token_expires_at' => now()->addSeconds($expiresIn),
                    'access_token' => encrypt($tokenData['access_token']),
                    'refresh_token' => encrypt($tokenData['refresh_token'] ?? ''),
                    'token_expires_at' => now()->addSeconds($expiresIn),
                ];

            case 'bling':
                return [
                    'bling_access_token' => encrypt($tokenData['access_token']),
                    'bling_refresh_token' => encrypt($tokenData['refresh_token'] ?? ''),
                    'bling_token_expires_at' => now()->addSeconds($expiresIn),
                    'access_token' => encrypt($tokenData['access_token']),
                    'refresh_token' => encrypt($tokenData['refresh_token'] ?? ''),
                    'token_expires_at' => now()->addSeconds($expiresIn),
                ];

            case 'shopee':
                // access_token Shopee expira em expire_in segundos (padrao 4h = 14400s)
                // refresh_token Shopee expira em 30 dias
                return [
                    'access_token'             => encrypt($tokenData['access_token'] ?? ''),
                    'refresh_token'            => encrypt($tokenData['refresh_token'] ?? ''),
                    'token_expires_at'         => now()->addSeconds((int) ($tokenData['expire_in'] ?? $expiresIn)),
                    'refresh_token_expires_at' => now()->addDays(30),
                ];

            default:
                return [
                    'access_token' => encrypt($tokenData['access_token'] ?? ''),
                    'refresh_token' => encrypt($tokenData['refresh_token'] ?? ''),
                    'token_expires_at' => now()->addSeconds($expiresIn),
                ];
        }
    }

    /**
     * Processa callback OAuth ML vindo do legado (goolhub.io / whitelabels).
     * State formato: "legado:{nonce}"
     * O nonce é usado para buscar o code_verifier salvo em ml_oauth_pending via ml_oauth_pop.php.
     * PKCE exigido pelo ML — o code_verifier deve corresponder ao code_challenge gerado no redirect.
     * (HUB-076, 2026-06-19)
     */
    private function handleLegadoMLCallback(string $code, string $state): \Illuminate\Http\RedirectResponse
    {
        // NOV-085: defaultReturn dinamico via config — nao hardcodar goolhub.io para WLs
        $defaultReturn = config('app.frontend_url', 'https://hubai.io') . '/integracoes';

        try {
            // 1. Extrair nonce do state: "legado:{nonce}"
            $nonce = substr($state, strlen('legado:'));
            if (!$nonce) {
                Log::error('[ML legado callback] State invalido', ['state' => $state]);
                return redirect($defaultReturn . '?ml=error&reason=invalid_state');
            }

            // 2. Buscar code_verifier + dados de origem via bridge (ml_oauth_pop.php)
            $bridgeKey = config('services.goolhub.bridge_key', 'hb-bridge-2026-xK9mP3qR7vL2nW8');
            $sig = hash_hmac('sha256', "mlpop:{$nonce}", $bridgeKey);

            $popResponse = Http::timeout(8)->get('https://goolhub.io/api/bridge/ml_oauth_pop.php', [
                'nonce' => $nonce,
                'sig'   => $sig,
            ]);

            if (!$popResponse->successful() || !$popResponse->json('success')) {
                Log::error('[ML legado callback] Pop nonce falhou', [
                    'nonce'    => $nonce,
                    'status'   => $popResponse->status(),
                    'response' => mb_substr($popResponse->body(), 0, 500),
                ]);
                return redirect($defaultReturn . '?ml=error&reason=nonce_not_found');
            }

            $pending      = $popResponse->json();
            $codeVerifier = $pending['code_verifier'] ?? null;
            $idLogin      = (int) ($pending['id_login'] ?? 0);
            $idDeposito   = (int) ($pending['id_deposito'] ?? 0);
            $returnUrl = $pending['return_url'] ?? null;
            // NOV-085: se bridge nao retornou return_url, resolver dominio WL via DB legado
            if (!$returnUrl && $idLogin) {
                try {
                    $empresaUrl = \Illuminate\Support\Facades\DB::connection('legacy')
                        ->table('login as l')
                        ->join('empresas as e', 'e.id', '=', 'l.id_empresa')
                        ->where('l.id', $idLogin)
                        ->value('e.url');
                    if ($empresaUrl && filter_var($empresaUrl, FILTER_VALIDATE_URL)) {
                        $returnUrl = rtrim($empresaUrl, '/') . '/integracoes';
                        Log::info('[ML legado callback] return_url resolvido via DB legado', [
                            'id_login'    => $idLogin,
                            'return_url'  => $returnUrl,
                        ]);
                    }
                } catch (\Throwable $dbEx) {
                    Log::warning('[ML legado callback] Falha ao resolver URL via DB legado', [
                        'id_login' => $idLogin,
                        'error'    => $dbEx->getMessage(),
                    ]);
                }
            }
            $returnUrl = $returnUrl ?? $defaultReturn;

            if (!$codeVerifier || !$idLogin) {
                Log::error('[ML legado callback] Dados incompletos no pop', [
                    'nonce'        => $nonce,
                    'has_verifier' => !empty($codeVerifier),
                    'id_login'     => $idLogin,
                ]);
                return redirect($returnUrl . '?ml=error&reason=incomplete_pop_data');
            }

            // 3. Exchange code por tokens usando PKCE (NOV-085: inline HTTP — MercadoLivreService nao tem exchangeCode)
            $mlExchangeBody = [
                'grant_type'    => 'authorization_code',
                'client_id'     => config('services.mercadolivre.app_id'),
                'client_secret' => config('services.mercadolivre.secret_key'),
                'code'          => $code,
                'redirect_uri'  => config('services.mercadolivre.redirect_uri', env('ML_REDIRECT_URI')),
            ];
            if ($codeVerifier) {
                $mlExchangeBody['code_verifier'] = $codeVerifier;
            }
            $mlExchangeResp = Http::timeout(15)->asForm()->post('https://api.mercadolibre.com/oauth/token', $mlExchangeBody);
            if ($mlExchangeResp->failed()) {
                Log::error('[ML legado callback] Exchange code HTTP falhou', [
                    'id_login' => $idLogin,
                    'status'   => $mlExchangeResp->status(),
                    'body'     => mb_substr($mlExchangeResp->body(), 0, 300),
                ]);
                return redirect($returnUrl . '?ml=error&reason=token_exchange_failed');
            }
            $tokenData = $mlExchangeResp->json();

            if (empty($tokenData['access_token'])) {
                Log::error('[ML legado callback] Exchange code falhou', [
                    'id_login'  => $idLogin,
                    'response'  => $tokenData,
                ]);
                return redirect($returnUrl . '?ml=error&reason=token_exchange_failed');
            }

            $accessToken  = $tokenData['access_token'];
            $refreshToken = $tokenData['refresh_token'] ?? '';
            $expiresIn    = (int) ($tokenData['expires_in'] ?? 21600);
            $mlUserId     = (string) ($tokenData['user_id'] ?? '');

            // 4. Relay tokens ao legado via ml_save_tokens.php (mesmo HMAC de relayMLTokenToLegado)
            $relaySig = hash_hmac('sha256', "ml:{$idLogin}:{$mlUserId}:{$accessToken}", $bridgeKey);

            $relayResponse = Http::timeout(8)->asForm()->post(
                'https://goolhub.io/api/bridge/ml_save_tokens.php',
                [
                    'user_id'       => $idLogin,
                    'ml_user_id'    => $mlUserId,
                    'access_token'  => $accessToken,
                    'refresh_token' => $refreshToken,
                    'expire_in'     => $expiresIn,
                    'id_deposito'   => $idDeposito,
                    'sig'           => $relaySig,
                ]
            );

            Log::info('[ML legado callback] Tokens relayados ao legado', [
                'id_login'     => $idLogin,
                'ml_user_id'   => $mlUserId,
                'relay_status' => $relayResponse->status(),
                'relay_body'   => mb_substr($relayResponse->body(), 0, 300),
            ]);

            // 5. Migracao lazy: criar/atualizar Client e MarketplaceAccount no NovoHubAI
            // Nunca bloqueia o fluxo OAuth — falhas sao apenas logadas
            try {
                $client = Client::where('legacy_id_login', $idLogin)->first();
                if (!$client) {
                    try {
                        // NOV-085: inline HTTP — MercadoLivreService nao tem getMe()
                        $meResp = Http::timeout(8)->withToken($accessToken)->get('https://api.mercadolibre.com/users/me');
                        $mlNick = $meResp->ok() ? ($meResp->json('nickname') ?? 'Usuario ML ' . $mlUserId) : 'Usuario ML ' . $mlUserId;
                    } catch (\Throwable $meEx) {
                        $mlNick = 'Usuario ML ' . $mlUserId;
                    }
                    // MUL-269 fase 2: company_name removido de clients — nome vem do user (accessor).
                    $client = Client::updateOrCreate(
                        ['legacy_id_login' => $idLogin],
                        [
                            'user_id'      => 1,
                            'document'     => (string) $idLogin,
                            'is_active'    => true,
                        ]
                    );
                    Log::info('[ML legado callback] Client criado (migracao lazy)', [
                        'client_id'    => $client->id,
                        'legacy_login' => $idLogin,
                        'ml_user_id'   => $mlUserId,
                    ]);
                }

                $legadoAccount = MarketplaceAccount::updateOrCreate(
                    [
                        'client_id'   => $client->id,
                        'supplier_id' => $idDeposito ?: null,
                        'platform'    => 'mercadolivre',
                    ],
                    [
                        'ml_user_id'            => $mlUserId ?: null,
                        'ml_access_token'       => encrypt($accessToken),
                        'ml_refresh_token'      => encrypt($refreshToken),
                        'ml_token_expires_at'   => now()->addSeconds($expiresIn),
                        'access_token'          => encrypt($accessToken),
                        'refresh_token'         => encrypt($refreshToken),
                        'token_expires_at'      => now()->addSeconds($expiresIn),
                        'status'                => 'active',
                        'last_token_refresh_at' => now(),
                        'sync_blocked_at'       => null,
                        'sync_errors_count'     => 0,
                    ]
                );
                // NOV-087: limpar bloqueio de reauth
                $this->clearReauthBlock($legadoAccount);

                Log::info('[ML legado callback] MarketplaceAccount sincronizada (NovoHubAI)', [
                    'client_id'  => $client->id,
                    'ml_user_id' => $mlUserId,
                ]);
            } catch (\Throwable $migEx) {
                Log::warning('[ML legado callback] Migracao lazy falhou (nao critico)', [
                    'id_login' => $idLogin,
                    'error'    => $migEx->getMessage(),
                ]);
            }

            // 6. Redirecionar com sucesso
            $sep = str_contains($returnUrl, '?') ? '&' : '?';
            return redirect($returnUrl . $sep . 'connected=ml');

        } catch (\Throwable $e) {
            Log::error('[ML legado callback] Excecao inesperada', ['error' => $e->getMessage()]);
            return redirect($defaultReturn . '?ml=error&reason=exception');
        }
    }

    /**
     * Faz relay dos tokens ML recém-obtidos ao bridge do legado (goolhub.io).
     * Chamado apenas quando o Client possui legacy_id_login preenchido.
     * Falhas sao logadas mas NUNCA interrompem o fluxo OAuth.
     *
     * (HUB-073, 2026-06-19)
     */
    private function relayMLTokenToLegado(Client $client, MarketplaceAccount $account, array $tokenData): void
    {
        try {
            $legacyUserId = $client->legacy_id_login ?? null;
            if (!$legacyUserId) {
                return; // Conta nativa NovoHubAI — sem relay
            }

            $accessToken  = $tokenData['access_token'] ?? null;
            $refreshToken = $tokenData['refresh_token'] ?? null;
            $mlUserId     = (string) ($account->ml_user_id ?? '');
            $expireIn     = (int) ($tokenData['expires_in'] ?? 21600);

            if (!$accessToken || !$refreshToken) {
                Log::warning('[ML-OAuth relay legado] tokens ausentes no tokenData', [
                    'client_id'  => $client->id,
                    'legacy_id'  => $legacyUserId,
                    'account_id' => $account->id,
                ]);
                return;
            }

            $bridgeKey = config('services.goolhub.bridge_key', 'hb-bridge-2026-xK9mP3qR7vL2nW8');
            $sig = hash_hmac('sha256', "ml:{$legacyUserId}:{$mlUserId}:{$accessToken}", $bridgeKey);

            $response = \Illuminate\Support\Facades\Http::timeout(8)
                ->asForm()
                ->post('https://goolhub.io/api/bridge/ml_save_tokens.php', [
                    'user_id'       => $legacyUserId,
                    'ml_user_id'    => $mlUserId,
                    'access_token'  => $accessToken,
                    'refresh_token' => $refreshToken,
                    'expire_in'     => $expireIn,
                    'sig'           => $sig,
                ]);

            Log::info('[ML-OAuth relay legado] Token relayed ao bridge', [
                'client_id'      => $client->id,
                'legacy_user_id' => $legacyUserId,
                'ml_user_id'     => $mlUserId,
                'bridge_status'  => $response->status(),
                'bridge_body'    => mb_substr($response->body(), 0, 500),
            ]);
        } catch (\Throwable $e) {
            Log::error('[ML-OAuth relay legado] Excecao no relay', [
                'client_id'  => $client->id ?? null,
                'account_id' => $account->id ?? null,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * NOV-087: Limpa bloqueio de reauth apos reconexao OAuth bem-sucedida.
     *
     * - Reseta sync_blocked_at = null e sync_errors_count = 0 na conta (safety net).
     * - Limpa paused_reason = 'needs_reauth' em client_products desta conta,
     *   permitindo que o reconcile reative os anuncios pausados.
     * - Dispara SyncInventoryJob por produto para forcar reativacao imediata.
     *
     * Nunca lanca excecao — falhas sao apenas logadas para nao bloquear o fluxo OAuth.
     */
    private function clearReauthBlock(MarketplaceAccount $account): void
    {
        try {
            // Safety net: garantir que a conta esta desbloqueada
            $account->forceFill([
                'sync_blocked_at'   => null,
                'sync_errors_count' => 0,
            ])->save();

            // Buscar client_products pausados por needs_reauth desta conta
            $blocked = ClientProduct::where('marketplace_account_id', $account->id)
                ->where('paused_reason', 'needs_reauth')
                ->get();

            if ($blocked->isEmpty()) {
                Log::info('[NOV-087] clearReauthBlock: nenhum client_product pausado por needs_reauth', [
                    'account_id' => $account->id,
                    'platform'   => $account->platform,
                ]);
                return;
            }

            // Coletar product_ids antes de limpar
            $productIds = $blocked->pluck('product_id')->unique()->filter();

            // Limpar paused_reason em massa
            ClientProduct::where('marketplace_account_id', $account->id)
                ->where('paused_reason', 'needs_reauth')
                ->update(['paused_reason' => null]);

            Log::info('[NOV-087] clearReauthBlock: paused_reason limpo', [
                'account_id'         => $account->id,
                'platform'           => $account->platform,
                'client_products'    => $blocked->count(),
                'unique_product_ids' => $productIds->count(),
            ]);

            // Disparar SyncInventoryJob por produto para forcar reativacao
            // (gate MARKETPLACE_SYNC_INVENTORY_ENABLED protege internamente)
            foreach ($productIds as $productId) {
                SyncInventoryJob::dispatch((int) $productId)->onQueue('default');
            }

            Log::info('[NOV-087] clearReauthBlock: SyncInventoryJobs disparados', [
                'account_id'    => $account->id,
                'product_count' => $productIds->count(),
            ]);

        } catch (\Throwable $e) {
            Log::error('[NOV-087] clearReauthBlock falhou (nao critico)', [
                'account_id' => $account->id ?? null,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Redirect de erro pro frontend. Usa return_url quando disponivel.
     */
    private function redirectWithError(string $message, ?string $returnUrl = null): \Illuminate\Http\RedirectResponse
    {
        // MUL-029: log erro de migracao
        try {
            // Tenta resolver email a partir do state (base64 JSON) ou do user autenticado
            $emailErr = request()->get('email', '');
            if (!$emailErr) {
                $state = request()->get('state');
                if ($state) {
                    $payload = json_decode(base64_decode($state), true) ?: [];
                    $cId = (int) ($payload['client_id'] ?? 0);
                    if ($cId) {
                        $client = \App\Models\Client::find($cId);
                        $emailErr = $client?->user?->email ?? '';
                    }
                }
            }
            MigracaoLogger::error('oauth.error', $emailErr, [
                'message' => $message,
                'return_url' => $returnUrl,
                'url' => request()->fullUrl(),
                'ip' => request()->ip(),
            ]);
        } catch (\Throwable $e) {}
        $url = $returnUrl ?? config('app.frontend_url', 'https://hubai.io') . '/integracoes';
        return redirect("{$url}?error=" . urlencode($message));
    }

    /**

    /**
     * Receiver do bridge relay do api.hubai.io.
     * O HubAI trocou o OAuth code por tokens e os enviou aqui via POST com assinatura HMAC.
     * Header: X-HubAI-Bridge-Sig = HMAC-SHA256(json_encode(payload), SHOPEE_BRIDGE_SECRET)
     */
    public function shopeeHubAiRelay(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $bridgeSecret = config('services.shopee.bridge_secret', '');
        $clientId     = (int) $request->get('client_id');
        $rawUserId    = (int) $request->get('user_id');

        // SEL-325 (22/07): resolver client via user_id do payload quando disponivel.
        // Substitui band-aid SEL-081 que so disparava se client_id nao existia — falhava
        // em colisao numerica (users.id de A == clients.id de B). Caso Luciano/Brenda SG.
        // Hub sempre envia user_id (ShopeeOAuthController::relayToService::$payload).
        // Ordem: (1) lookup por user_id -> autoritativo; (2) fallback SEL-081 preservado.
        if ($rawUserId > 0) {
            $resolvedByUser = (int) (\App\Models\Client::where('user_id', $rawUserId)->value('id') ?? 0);
            if ($resolvedByUser > 0) {
                if ($resolvedByUser !== $clientId) {
                    \Log::warning('[Shopee HubAI Relay] client_id do hub divergiu do lookup por user_id — corrigido (SEL-325)', [
                        'raw_client_id_from_hub' => $clientId,
                        'raw_user_id_from_hub'   => $rawUserId,
                        'resolved_client_id'     => $resolvedByUser,
                    ]);
                }
                $clientId = $resolvedByUser;
            }
        }

        // SEL-081 retrocompat: sem user_id ou lookup falhou, tenta resolver client_id via user_id fallback.
        if ($clientId > 0 && ! \App\Models\Client::whereKey($clientId)->exists()) {
            $resolved = (int) (\App\Models\Client::where('user_id', $clientId)->value('id') ?? 0);
            if ($resolved > 0) {
                \Log::info('[Shopee HubAI Relay] client_id resolvido via clients.user_id (SEL-081 fallback)', [
                    'raw_client_id_from_hub' => $clientId,
                    'resolved_client_id'     => $resolved,
                ]);
                $clientId = $resolved;
            } else {
                \Log::warning('[Shopee HubAI Relay] client_id do hub nao existe local nem via user_id (SEL-081)', [
                    'raw_client_id' => $clientId,
                ]);
            }
        }

        $shopId       = $request->get('shop_id');
        $accessToken  = $request->get('access_token');
        $refreshToken = $request->get('refresh_token', '');
        $expireIn     = (int) $request->get('expire_in', 14400);
        $relayedBy    = $request->get('relayed_by', '');
        $rawSupplierId = (int) ($request->get('supplier_id') ?? 0);
        // HUB-080: validar FK — supplier_id pode nao existir no banco
        $supplierId   = ($rawSupplierId && \App\Models\Supplier::find($rawSupplierId)) ? $rawSupplierId : null;
        $service      = $request->get('service', 'hubai'); // servico de origem: hubai|multdrop|fornecefy

        // Verificar assinatura HMAC usando o raw body (exatamente como o remetente gerou)
        // O HubAI assina json_encode($relayPayload) antes de enviar — usamos o mesmo raw body
        $sig      = $request->header('X-HubAI-Bridge-Sig', '');
        $rawBody  = $request->getContent();
        $expected = hash_hmac('sha256', $rawBody, $bridgeSecret);

        if (! $sig || ! hash_equals($expected, $sig)) {
            \Log::warning('[Shopee HubAI Relay] Assinatura invalida', [
                'client_id' => $clientId,
                'shop_id'   => $shopId,
                'sig_recv'  => substr($sig, 0, 20),
            ]);
            return response()->json(['error' => 'invalid_signature'], 401);
        }

        if (! $clientId || ! $shopId || ! $accessToken) {
            return response()->json(['error' => 'missing_fields'], 422);
        }

        try {
            // HUB-079: upsert por shop_id — se o mesmo shop_id ja existe em qualquer client,
            // atualizar o registro existente (reconexao de outra plataforma).
            // Fallback: buscar por client_id (conta nova, shop_id ainda nao salvo).
            $account = \App\Models\MarketplaceAccount::where('platform', 'shopee')
                ->where('shop_id', (string) $shopId)
                ->orderByDesc('id')
                ->first();

            if (!$account) {
                $account = \App\Models\MarketplaceAccount::where('client_id', $clientId)
                    ->where('platform', 'shopee')
                    ->orderByDesc('id')
                    ->first();
            }

            // MUL-314: o account_name vinha forcado como 'Loja Shopee #<id>', jogando
            // fora o nome que o seller digitou -- que o relay ja trazia no corpo.
            // seller_nickname continua sendo o identificador tecnico da loja; quem passa
            // a respeitar o seller e o account_name, que e o rotulo que ele ve na tela.
            // Ordem: nome digitado > nome ja salvo na conta > 'Loja Shopee #<id>'.
            $shopName     = 'Loja Shopee #' . $shopId;
            $nomeDigitado = trim((string) $request->get('account_name', ''));
            $nomeConta    = $nomeDigitado !== '' && $nomeDigitado !== 'Minha Loja'
                ? $nomeDigitado
                : (! empty($account?->account_name) ? $account->account_name : $shopName);
            $tokenFields = [
                'status'                   => 'active',
                'shop_id'                  => (string) $shopId,
                'seller_id'                => (string) $shopId,
                'seller_nickname'          => $shopName,
                'account_name'             => $nomeConta,
                'access_token'             => encrypt($accessToken),
                'refresh_token'            => encrypt($refreshToken),
                'token_expires_at'         => now()->addSeconds($expireIn),
                'refresh_token_expires_at' => now()->addDays(30),
                'last_token_refresh_at'    => now(),
                'last_sync_at'             => now(),
                'service'                  => $service ?: 'hubai',
                'client_id'                => $clientId,
                'sync_blocked_at'          => null,
                'sync_errors_count'        => 0,
            ];

            // NOV-180: fallback 30 restrito a service multdrop; demais ficam NULL
            $resolvedSupplierId = $supplierId
                ?: ($service === 'multdrop' ? (int) config('multdrop.supplier_id', 30) : null);

            if ($account) {
                // NOV-102: atualizar supplier_id se estiver NULL na conta existente
                if (! $account->supplier_id && $resolvedSupplierId) {
                    $tokenFields['supplier_id'] = $resolvedSupplierId;
                }
                $account->update($tokenFields);
                // NOV-087: limpar bloqueio de reauth apos reconexao Shopee relay
                $this->clearReauthBlock($account);
                \Log::channel('marketplace')->info('[Shopee HubAI Relay] Account atualizado', [
                    'account_id' => $account->id,
                    'shop_id'    => $shopId,
                    'client_id'  => $clientId,
                    'supplier_id'  => $account->supplier_id,
                    'upsert_by'  => 'shop_id',
                ]);
            } else {
                $account = \App\Models\MarketplaceAccount::create(array_merge([
                    'client_id'   => $clientId,
                    'supplier_id' => $resolvedSupplierId,
                    'platform'    => 'shopee',
                ], $tokenFields));
                \Log::channel('marketplace')->info('[Shopee HubAI Relay] Account criado', [
                    'account_id' => $account->id,
                    'client_id'  => $clientId,
                    'shop_id'    => $shopId,
                ]);
            }

            // FOR-025: limpar registros pending sem shop_id para o mesmo client_id
            // que nao sao a conta que acabou de ser atualizada/criada.
            // Evita que o usuario veja pendente quando a conta real ja esta ativa.
            \App\Models\MarketplaceAccount::where('platform', 'shopee')
                ->where('client_id', $clientId)
                ->whereIn('status', ['pending', 'needs_reauth'])
                ->where(function (\Illuminate\Database\Eloquent\Builder $q) {
                    $q->whereNull('shop_id')->orWhere('shop_id', '');
                })
                ->where('id', '!=', $account->id)
                ->delete();

        } catch (\Throwable $e) {
            \Log::error('[Shopee HubAI Relay] Erro ao salvar', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'internal_error'], 500);
        }

        return response()->json(['success' => true, 'shop_id' => $shopId]);
    }

    /**
     * Recebe callback do bridge goolhub após OAuth Shopee concluído.
     * O goolhub redireciona o browser do usuário aqui após salvar a integração.
     */
    public function shopeeBridgeCallback(\Illuminate\Http\Request $request): \Illuminate\Http\RedirectResponse
    {
        $bridgeKey    = config('services.goolhub.bridge_key', 'hb-bridge-2026-xK9mP3qR7vL2nW8');
        $clientId     = (int) $request->get('client_id');
        $shopId       = $request->get('shop_id');
        $shopName     = $request->get('shop_name', 'Loja Shopee #' . $shopId);
        $accessToken  = $request->get('access_token');
        $refreshToken = $request->get('refresh_token', '');
        $expireIn     = (int) $request->get('expire_in', 14400);
        $sig          = $request->get('sig');

        // Verificar assinatura HMAC
        $expected = hash_hmac('sha256', "shopee:{$clientId}:{$shopId}:{$accessToken}", $bridgeKey);
        if (!$sig || !hash_equals($expected, $sig)) {
            \Log::warning('shopeeBridgeCallback: assinatura invalida', [
                'client_id' => $clientId, 'shop_id' => $shopId,
            ]);
            return redirect(config('app.frontend_url', 'https://hubai.io') . '/integracoes?error=sig_invalida');
        }

        if (!$clientId || !$shopId || !$accessToken) {
            return redirect(config('app.frontend_url', 'https://hubai.io') . '/integracoes?error=dados_incompletos');
        }

        try {
            // HUB-079: upsert por shop_id — se o mesmo shop_id ja existe, atualizar.
            $account = \App\Models\MarketplaceAccount::where('platform', 'shopee')
                ->where('shop_id', (string) $shopId)
                ->orderByDesc('id')
                ->first();

            if (!$account) {
                $account = \App\Models\MarketplaceAccount::where('client_id', $clientId)
                    ->where('platform', 'shopee')
                    ->orderByDesc('id')
                    ->first();
            }

            $tokenFields = [
                'status'                   => 'active',
                'shop_id'                  => (string) $shopId,
                'seller_id'                => (string) $shopId,
                'seller_nickname'          => $shopName,
                'account_name'             => $shopName,
                'access_token'             => encrypt($accessToken),
                'refresh_token'            => encrypt($refreshToken),
                'token_expires_at'         => now()->addSeconds($expireIn),
                'refresh_token_expires_at' => now()->addDays(30),
                'last_token_refresh_at'    => now(),
                'last_sync_at'             => now(),
                'client_id'                => $clientId,
                'sync_blocked_at'          => null,
                'sync_errors_count'        => 0,
            ];

            if ($account) {
                $account->update($tokenFields);
                // NOV-087: limpar bloqueio de reauth apos reconexao Shopee bridge
                $this->clearReauthBlock($account);
                \Log::info('shopeeBridgeCallback: account atualizado', ['id' => $account->id, 'shop_id' => $shopId, 'client_id' => $clientId, 'upsert_by' => 'shop_id']);
            } else {
                $account = \App\Models\MarketplaceAccount::create(array_merge([
                    'client_id'   => $clientId,
                    'supplier_id' => null,
                    'platform'    => 'shopee',
                ], $tokenFields));
                \Log::info('shopeeBridgeCallback: account criado', ['client_id' => $clientId, 'shop_id' => $shopId]);
            }

            // NOV-156: limpar registros pending sem shop_id para o mesmo client_id
            // (mesmo cleanup ja feito na variante HubAI Relay — vide FOR-025).
            \App\Models\MarketplaceAccount::where('platform', 'shopee')
                ->where('client_id', $clientId)
                ->whereIn('status', ['pending', 'needs_reauth'])
                ->where(function (\Illuminate\Database\Eloquent\Builder $q) {
                    $q->whereNull('shop_id')->orWhere('shop_id', '');
                })
                ->where('id', '!=', $account->id)
                ->delete();
        } catch (\Throwable $e) {
            \Log::error('shopeeBridgeCallback: erro ao salvar', ['error' => $e->getMessage()]);
            return redirect(config('app.frontend_url', 'https://hubai.io') . '/integracoes?error=erro_interno');
        }

        return redirect(config('app.frontend_url', 'https://hubai.io') . '/integracoes?connected=shopee');
    }
    /**
     * Endpoint para refresh de token Shopee relayed pelos sistemas externos (multdrop, fornecefy).
     * Autenticado via HMAC-SHA256 no header X-HubAI-Bridge-Sig.
     * POST /api/oauth/shopee/relay-token-refresh
     */
    public function shopeeRelayTokenRefresh(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $bridgeSecret = config('services.shopee.bridge_secret', '');
        $sig          = $request->header('X-HubAI-Bridge-Sig', '');
        $payload      = $request->getContent();
        $expected     = hash_hmac('sha256', $payload, $bridgeSecret);

        if (! $sig || ! hash_equals($expected, $sig)) {
            \Log::warning('[Shopee RelayTokenRefresh] Assinatura invalida', [
                'sig_recv' => substr($sig, 0, 20),
                'shop_id'  => $request->get('shop_id'),
            ]);
            return response()->json(['error' => 'invalid_signature'], 401);
        }

        try {
            $data = $request->validate([
                'shop_id'                  => 'required',
                'access_token'             => 'required|string',
                'refresh_token'            => 'required|string',
                'token_expires_at'         => 'required|date',
                'refresh_token_expires_at' => 'required|date',
            ]);
        } catch (\Illuminate\Validation\ValidationException $ve) {
            return response()->json(['error' => 'validation_failed', 'details' => $ve->errors()], 422);
        }

        try {
            $updated = \App\Models\MarketplaceAccount::where('platform', 'shopee')
                ->where('shop_id', (string) $data['shop_id'])
                ->update([
                    'access_token'             => encrypt($data['access_token']),
                    'refresh_token'            => encrypt($data['refresh_token']),
                    'token_expires_at'         => $data['token_expires_at'],
                    'refresh_token_expires_at' => $data['refresh_token_expires_at'],
                    'status'                   => 'active',
                    'last_token_refresh_at'    => now(),
                ]);

            \Log::info('[Shopee RelayTokenRefresh] Tokens atualizados', [
                'shop_id'    => $data['shop_id'],
                'updated'    => $updated,
                'expires_at' => $data['token_expires_at'],
            ]);

            return response()->json(['success' => true, 'updated' => $updated, 'shop_id' => $data['shop_id']]);
        } catch (\Throwable $e) {
            \Log::error('[Shopee RelayTokenRefresh] Erro ao atualizar tokens', [
                'shop_id' => $data['shop_id'] ?? null,
                'error'   => $e->getMessage(),
            ]);
            return response()->json(['error' => 'internal_error'], 500);
        }
    }

    /**
     * MUL-029-2: Envia tokens Bling pro endpoint receiver da WL (multdrop/fornecefy).
     *
     * Espelha padrao Shopee (handleBridgeRelay). HMAC-SHA256 sobre raw body com
     * BLING_RELAY_HMAC_SECRET, header X-HubAI-Bridge-Sig.
     *
     * Endpoints registrados em config/bling.php (anti-SSRF).
     *
     * @throws \RuntimeException Se endpoint nao configurado ou POST falhar
     */
    private function relayBlingTokenToWL(
        string $tenant,
        \App\Models\Client $client,
        int $supplierId,
        array $tokenData,
        string $accountName,
        ?string $accountType = null,
        ?int $wlClientId = null
    ): void {
        $endpoints   = config('bling.relay_endpoints', []);
        $relayUrl    = $endpoints[$tenant] ?? null;
        $relaySecret = (string) config('bling.relay_secret', '');

        if (! $relayUrl) {
            throw new \RuntimeException("[Bling Relay] endpoint nao configurado pra tenant '{$tenant}'");
        }
        if ($relaySecret === '') {
            throw new \RuntimeException('[Bling Relay] BLING_RELAY_HMAC_SECRET nao configurado');
        }

        $payload = [
            'tenant'        => $tenant,
            'client_id'     => $wlClientId ?? $client->id,
            'supplier_id'   => $supplierId,
            'account_type'  => $accountType, // NOV-153: 'supplier_erp' | null
            'access_token'  => (string) ($tokenData['access_token'] ?? ''),
            'refresh_token' => (string) ($tokenData['refresh_token'] ?? ''),
            'expires_in'    => (int) ($tokenData['expires_in'] ?? 21600),
            'scope'         => (string) ($tokenData['scope'] ?? ''),
            'account_name'  => $accountName,
            'user_email'    => $client->user?->email ?? '',
            'relayed_by'    => 'api.hubai.io',
        ];

        $body = json_encode($payload);
        $sig  = hash_hmac('sha256', $body, $relaySecret);

        Log::info('[Bling Relay] POST tokens pra WL', [
            'tenant'    => $tenant,
            'relay_url' => $relayUrl,
            'client_id' => $client->id,
        ]);

        $response = Http::timeout(15)
            ->withHeaders([
                'X-HubAI-Bridge-Sig' => $sig,
                'Content-Type'       => 'application/json',
            ])
            ->withBody($body, 'application/json')
            ->post($relayUrl);

        if ($response->failed()) {
            throw new \RuntimeException(
                "[Bling Relay] WL retornou {$response->status()}: " . substr($response->body(), 0, 300)
            );
        }

        Log::channel('marketplace')->info('[Bling Relay] Tokens enviados com sucesso', [
            'tenant'      => $tenant,
            'client_id'   => $client->id,
            'status'      => $response->status(),
            'wl_response' => $response->json(),
        ]);
    }

    /**
     * NOV-153: Salva conexão OAuth Bling do FORNECEDOR (ERP, não lojista) em erp_accounts.
     *
     * Diferente de MarketplaceAccount (que representa uma loja do vendedor cliente), o
     * ErpAccount representa o ERP do próprio fornecedor — usado pra importar produtos
     * e sincronizar estoque entre o Bling do fornecedor e a plataforma.
     *
     * O cast `encrypted` no ErpAccount cuida da criptografia automaticamente; NÃO chamar
     * encrypt() aqui (duplica o encrypt e quebra leitura).
     */
    private function handleBlingSupplierErp(
        int $supplierId,
        array $tokenData,
        array $extraFields,
        array $payload,
        string $returnUrl
    ): \Illuminate\Http\RedirectResponse {
        try {
            $expiresIn = (int) ($tokenData['expires_in'] ?? 21600);
            $blingSellerId = $extraFields['seller_id']
                ?? $extraFields['ml_user_id']
                ?? null;

            $account = \App\Models\ErpAccount::updateOrCreate(
                [
                    'supplier_id' => $supplierId,
                    'platform'    => 'bling',
                ],
                [
                    'access_token'      => (string) ($tokenData['access_token'] ?? ''),
                    'refresh_token'     => (string) ($tokenData['refresh_token'] ?? ''),
                    'token_expires_at'  => now()->addSeconds($expiresIn),
                    'status'            => 'active',
                    'account_name'      => $extraFields['account_name']
                        ?? $payload['account_name']
                        ?? 'Bling ERP',
                    'bling_seller_id'   => $blingSellerId ? (string) $blingSellerId : null,
                    'api_version'       => 'v3',
                ]
            );

            Log::info('[OAuth Bling supplier_erp] ErpAccount salvo', [
                'erp_account_id' => $account->id,
                'supplier_id'    => $supplierId,
            ]);

            return redirect("{$returnUrl}?connected=bling_erp&erp_account_id={$account->id}");
        } catch (\Throwable $e) {
            Log::error('[OAuth Bling supplier_erp] Falha ao salvar ErpAccount', [
                'supplier_id' => $supplierId,
                'error'       => $e->getMessage(),
            ]);
            return $this->redirectWithError(
                'Erro ao salvar conexão Bling ERP: ' . $e->getMessage(),
                $returnUrl
            );
        }
    }

}
