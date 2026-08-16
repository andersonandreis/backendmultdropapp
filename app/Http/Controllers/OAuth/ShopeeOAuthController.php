<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ShopeeOAuthController
 *
 * Recebe o callback OAuth da Shopee Open Platform (rota /shopee/oauth-callback).
 * Esta rota e a unica registrada no console Shopee como "Live Redirect URL Domain".
 *
 * Fluxos suportados, detectados pelo parametro "state":
 *
 * 1. Fluxo LEGADO (state = "legado:USER_ID"):
 *    - Veio do goolhub.io (sistema antigo)
 *    - Troca o code por tokens via Shopee API
 *    - Salva tokens no legado via bridge endpoint
 *    - Redireciona para goolhub.io success
 *
 * 2. Fluxo NovoHubAI (state e base64 JSON com client_id):
 *    - Relay para /api/oauth/shopee/callback que faz o exchange completo
 *
 * 3. Fluxo AccountId (state e inteiro = MarketplaceAccount->id):
 *    - Gerado pelo ShopeeService::authenticate() via Filament
 *    - Salva os tokens direto no MarketplaceAccount correspondente
 *
 * 4. Sem state (Go-Live validation / link antigo sem state):
 *    - Loga IP, UA e referer para rastrear origem
 *    - Se shop_id ja existe em marketplace_accounts, tenta salvar tokens
 *    - Caso contrario, redireciona para app.hubai.io com hint para reconectar
 */
class ShopeeOAuthController extends Controller
{
    private const REDIRECT_SUCCESS       = 'https://hubai.io/painel-cliente/integracoes?shopee=connected';
    private const REDIRECT_ERROR         = 'https://hubai.io/painel-cliente/integracoes?shopee=error';
    private const REDIRECT_RECONNECT     = 'https://hubai.io/painel-cliente/integracoes?shopee=reconnect&error=link_expirado';
    // NOV-088: LEGADO_SUCCESS e LEGADO_ERROR removidas (goolhub.io hardcoded, code morto).
    // Redirect de sucesso/erro legado e resolvido dinamicamente por resolveLegadoRedirectUrl().
    private const LEGADO_BRIDGE_ENDPOINT = 'https://goolhub.io/api/bridge/shopee_save_tokens.php'; // bridge API legada (externo)

    /**
     * GOL-032: Inicia o fluxo OAuth Shopee vindo do legado com cookie pending.
     * A Shopee nao retorna o state no callback — armazena uid+ret no cache e seta cookie
     * para que handleCallback possa recuperar os dados do legado ao receber o callback.
     *
     * URL: GET /shopee/legado-start?uid=USER_ID&ret=BASE64_URL&ts=TIMESTAMP&sig=HMAC
     */
    public function legadoStart(Request $request): \Illuminate\Http\RedirectResponse
    {
        $uid = (int) $request->query('uid', 0);
        $ret = (string) $request->query('ret', '');
        $ts  = (int) $request->query('ts', 0);
        $sig = (string) $request->query('sig', '');

        $bridgeKey = config('services.goolhub.bridge_key', 'hb-bridge-2026-xK9mP3qR7vL2nW8');

        // Validar presenca e TTL de 5 minutos
        if (!$uid || !$ret || !$ts || !$sig || abs(time() - $ts) > 300) {
            Log::channel('marketplace')->warning('[Shopee legadoStart] Parametros invalidos ou expirados', [
                'uid' => $uid, 'has_ret' => !empty($ret), 'ts_delta' => $ts ? abs(time() - $ts) : 'missing',
            ]);
            return redirect('https://hubai.io/login?error=invalid_link');
        }

        // Validar HMAC
        $expected = hash_hmac('sha256', $uid . ':' . $ts, $bridgeKey);
        if (!hash_equals($expected, $sig)) {
            Log::channel('marketplace')->warning('[Shopee legadoStart] HMAC invalido', ['uid' => $uid]);
            return redirect('https://hubai.io/login?error=invalid_sig');
        }

        // Armazenar no cache por 10 min (UUID como chave para evitar colisoes)
        $uuid = \Illuminate\Support\Str::uuid()->toString();
        \Illuminate\Support\Facades\Cache::put(
            'shopee_legado_' . $uuid,
            ['uid' => $uid, 'ret' => $ret],
            now()->addMinutes(10)
        );

        // Gerar URL de auth Shopee
        $partnerId  = (int) config('services.shopee.partner_id');
        $partnerKey = config('services.shopee.partner_key');
        $timestamp  = time();
        $path       = '/api/v2/shop/auth_partner';
        $baseString = $partnerId . $path . $timestamp;
        $sign       = hash_hmac('sha256', $baseString, $partnerKey);

        // redirect_uri: callback padrao registrado no console Shopee
        $redirectUri = url('/shopee/oauth-callback');

        $shopeeUrl = 'https://partner.shopeemobile.com/api/v2/shop/auth_partner'
            . '?partner_id=' . $partnerId
            . '&sign='       . $sign
            . '&redirect='   . urlencode($redirectUri)
            . '&timestamp='  . $timestamp
            . '&state=legado_cookie';  // state minimo -- dados reais estao no cookie

        Log::channel('marketplace')->info('[Shopee legadoStart] Iniciando OAuth via cookie pending', [
            'uid'     => $uid,
            'uuid'    => substr($uuid, 0, 8) . '...',
            'ret_b64' => substr($ret, 0, 30) . '...',
        ]);

        // Redirecionar para Shopee e setar cookie shopee_pending com o UUID
        return redirect($shopeeUrl)->withCookie(
            \Cookie::make('shopee_pending', $uuid, 10, '/', null, true, false, false, 'lax')
        );
    }

    public function callback(Request $request): RedirectResponse
    {
        $code      = $request->query('code');
        $shopId    = $request->query('shop_id');
        $state     = $request->query('state', '');
        $userAgent = $request->userAgent() ?? '';
        $referer   = $request->header('referer', '');
        $ip        = $request->ip();

        // Origem explicita embebida na redirect_uri pelo legado PHP (base64 da URL de retorno
        // do whitelabel — ex: multdropbr.com). Usado quando a Shopee descarta o state na primeira
        // chamada e caimos em handleNoStateCallback — assim sabemos pra onde voltar sem depender
        // do lookup de empresa por shop_id.
        $retBase64 = $request->query('ret');
        $explicitReturnUrl = null;
        if (! empty($retBase64)) {
            $decodedRet = base64_decode($retBase64, true);
            if ($decodedRet && filter_var($decodedRet, FILTER_VALIDATE_URL)) {
                $explicitReturnUrl = $decodedRet;
            }
        }

        // Identificar fluxo para registro
        $flow = $this->detectFlow($state, $code, $shopId);

        // Logging protegido: falha de escrita no log NAO deve causar 500 no fluxo OAuth
        try {
            Log::channel('single')->info('[Shopee OAuth] Callback recebido', [
                'shop_id'    => $shopId,
                'has_code'   => ! empty($code),
                'state'      => substr($state, 0, 50),
                'flow'       => $flow,
                'ip'         => $ip,
                'user_agent' => substr($userAgent, 0, 100),
                'referer'    => substr($referer, 0, 100),
            ]);
        } catch (\Throwable $logEx) {
            // silencioso
        }

        // Salvar para auditoria sempre, incluindo state e diagnostico
        $callbackId = null;
        try {
            $callbackId = DB::table('shopee_oauth_callbacks')->insertGetId([
                'shop_id'     => ($shopId !== null && $shopId !== '') ? (string) $shopId : null,
                'code'        => $code,
                'state'       => $state !== '' ? $state : null,
                'received_at' => now(),
                'processed'   => false,
                'flow'        => $flow,
                'source_ip'   => $ip,
                'user_agent'  => substr($userAgent, 0, 512),
                'referer'     => substr($referer, 0, 512),
                'raw_params'  => json_encode($request->query->all()),
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        } catch (\Throwable $e) {
            Log::channel('single')->error('[Shopee OAuth] Falha ao salvar callback', ['error' => $e->getMessage()]);
        }

        if (empty($code) || empty($shopId)) {
            return redirect(self::REDIRECT_ERROR . '&reason=missing_params');
        }

        // --- GOL-032: Cookie-based legado return ---
        // A Shopee nao retorna o state no callback, entao o pendingCookie guarda uid e ret.
        $pendingCookieId = $request->cookie('shopee_pending');
        if ($pendingCookieId) {
            $pending = \Illuminate\Support\Facades\Cache::get('shopee_legado_' . $pendingCookieId);
            if ($pending && isset($pending['uid']) && isset($pending['ret'])) {
                \Illuminate\Support\Facades\Cache::forget('shopee_legado_' . $pendingCookieId);
                // Montar state sintetico para reusar handleLegadoCallback
                $syntheticState = 'legado:' . $pending['uid'] . ':ret:' . base64_encode(base64_decode($pending['ret']));
                $explicitRet    = base64_decode($pending['ret'], true);
                $explicitRet    = ($explicitRet && filter_var($explicitRet, FILTER_VALIDATE_URL)) ? $explicitRet : null;
                Log::channel('marketplace')->info('[Shopee OAuth] cookie-based legado redirect', [
                    'flow'       => 'cookie_legado',
                    'uid'        => $pending['uid'],
                    'shop_id'    => $shopId,
                    'cookie_id'  => substr($pendingCookieId, 0, 8) . '...',
                ]);
                $this->updateCallbackResolution($callbackId, 'cookie_legado:uid_' . $pending['uid']);
                return $this->handleLegadoCallback(
                    $code,
                    (int) $shopId,
                    $syntheticState,
                    $callbackId,
                    $explicitRet
                );
            }
        }

        // --- FLUXO NOVO: state_token opaco de 64 chars (bin2hex(random_bytes(32))) ---
        // Gerado por ShopeeOAuthInitController::init() — tem TTL de 10min e e consumido uma vez
        if ($state && strlen($state) === 64 && ctype_xdigit($state)) {
            $stateRow = \Illuminate\Support\Facades\DB::table('shopee_oauth_states')
                ->where('state_token', $state)
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now())
                ->first();

            if ($stateRow) {
                \Illuminate\Support\Facades\DB::table('shopee_oauth_states')
                    ->where('id', $stateRow->id)
                    ->update(['consumed_at' => now()]);
                return $this->handleServiceFlow($code, (int) $shopId, $stateRow, $callbackId);
            }
            // state_token invalido/expirado -- cai nos fluxos legados como fallback
        }

                // --- FLUXO 1: LEGADO (state = "legado:USER_ID") ---
        if (str_starts_with($state, 'legado:')) {
            return $this->handleLegadoCallback($code, (int) $shopId, $state, $callbackId, $explicitReturnUrl);
        }

        // --- FLUXO 2: NovoHubAI (state e base64 JSON com client_id) ---
        if (! empty($state)) {
            $payload = json_decode(base64_decode($state), true);
            if (is_array($payload) && isset($payload['client_id'])) {
                $originCallback = $payload['origin_callback'] ?? null;
                $appUrl         = config('app.url');

                // Bridge mode: origin_callback aponta para servidor externo (ex: api.fornecefy.io)
                if ($originCallback && ! str_starts_with($originCallback, $appUrl)) {
                    return $this->handleBridgeRelay($code, (int) $shopId, $payload, $callbackId);
                }

                // Local mode: relay interno para /api/oauth/shopee/callback
                $relayUrl = url('/api/oauth/shopee/callback') . '?' . http_build_query([
                    'code'    => $code,
                    'shop_id' => $shopId,
                    'state'   => $state,
                ]);
                return redirect($relayUrl);
            }
        }

        // --- FLUXO 3: ShopeeService::authenticate() --- state e um inteiro (account->id) ---
        if (! empty($state) && ctype_digit($state)) {
            return $this->handleAccountIdCallback($code, (int) $shopId, (int) $state, $callbackId);
        }

        // --- FLUXO 4: Sem state (Go-Live validation / link sem state / deeplink antigo) ---
        return $this->handleNoStateCallback($code, (int) $shopId, $ip, $userAgent, $referer, $callbackId, $explicitReturnUrl);
    }

    /**
     * Detecta o fluxo com base no state recebido.
     */
    private function detectFlow(string $state, ?string $code, ?string $shopId): string
    {
        if (empty($state)) {
            return 'no_state';
        }
        if (str_starts_with($state, 'legado:')) {
            return 'legado';
        }
        $payload = json_decode(base64_decode($state), true);
        if (is_array($payload) && isset($payload['client_id'])) {
            $originCallback = $payload['origin_callback'] ?? null;
            if ($originCallback && ! str_starts_with($originCallback, config('app.url'))) {
                return 'bridge';
            }
            return 'novohubai';
        }
        if (ctype_digit($state)) {
            return 'account_id';
        }
        return 'unknown';
    }

    /**
     * Fluxo 4: Callback sem state.
     * Rastreia a origem (IP, UA, referer) e tenta salvar tokens se shop_id ja e conhecido.
     * Casos possiveis:
     * - Shopee Go-Live validation batendo no endpoint (testa callbacks automaticamente)
     * - Usuario com link antigo salvo nos bookmarks (sem parametro state)
     * - Whitelabel ou sistema externo gerando URL OAuth sem state
     */
    private function handleNoStateCallback(
        string $code,
        int $shopId,
        string $ip,
        string $userAgent,
        string $referer,
        ?int $callbackId,
        ?string $explicitReturnUrl = null
    ): RedirectResponse {

        // Log detalhado para rastrear origem
        try {
            Log::channel('marketplace')->warning('[Shopee OAuth] Callback sem state recebido', [
                'flow'        => 'no_state',
                'shop_id'     => $shopId,
                'code'        => substr($code, 0, 10) . '...',
                'ip'          => $ip,
                'user_agent'  => substr($userAgent, 0, 200),
                'referer'     => $referer,
                'callback_id' => $callbackId,
                'timestamp'   => now()->toISOString(),
            ]);
        } catch (\Throwable $e) {
            // silencioso
        }

        // Verificar se e validacao automatica da Shopee (Go-Live):
        // UA da Shopee normalmente nao tem referer e pode ter UA especifico
        $isShopeeValidation = (empty($referer) && (
            str_contains(strtolower($userAgent), 'shopee') ||
            str_contains(strtolower($userAgent), 'python') ||
            str_contains(strtolower($userAgent), 'curl') ||
            str_contains(strtolower($userAgent), 'go-http-client')
        ));

        if ($isShopeeValidation) {
            try {
                Log::channel('marketplace')->info('[Shopee OAuth] Provavel validacao Go-Live da Shopee', [
                    'shop_id'    => $shopId,
                    'ip'         => $ip,
                    'user_agent' => $userAgent,
                ]);
                $this->updateCallbackResolution($callbackId, 'shopee_golive_validation');
            } catch (\Throwable $e) {
                // silencioso
            }
            return redirect(self::REDIRECT_SUCCESS . '&shop_id=' . urlencode((string) $shopId) . '&origin=golive');
        }

        // Tentar identificar se shop_id ja existe em marketplace_accounts
        // NOV-046-H: filtrar por service='hubai' para garantir isolamento de tenant.
        // Sem este filtro, um shop_id de WL A poderia ser atribuido a WL B (vazamento cross-tenant).
        try {
            // Verifica se o shop_id existe em QUALQUER tenant para log de seguranca
            $anyExisting = \App\Models\MarketplaceAccount::where('shop_id', (string) $shopId)
                ->where('platform', 'shopee')
                ->first();

            // Filtra apenas contas do proprio tenant HubAI (service=hubai)
            $existing = \App\Models\MarketplaceAccount::where('shop_id', (string) $shopId)
                ->where('platform', 'shopee')
                ->where('service', 'hubai')
                ->first();

            // NOV-046-H: detectar e bloquear acesso cross-tenant
            if ($anyExisting && !$existing) {
                Log::channel('marketplace')->warning('[Shopee OAuth] SECURITY: cross-tenant no_state rejeitado', [
                    'flow'             => 'no_state_cross_tenant_rejected',
                    'shop_id'          => $shopId,
                    'found_service'    => $anyExisting->service,
                    'found_account_id' => $anyExisting->id,
                    'ip'               => $ip,
                    'user_agent'       => substr($userAgent, 0, 200),
                    'referer'          => $referer,
                    'callback_id'      => $callbackId,
                ]);
                $this->updateCallbackResolution($callbackId, 'cross_tenant_rejected:service_' . ($anyExisting->service ?? 'null') . ':account_' . $anyExisting->id);
                return redirect(self::REDIRECT_ERROR . '&reason=cross_tenant_rejected&shop_id=' . urlencode((string) $shopId));
            }

            if ($existing) {
                // shop_id conhecido e pertence ao tenant HubAI — tentar fazer exchange e atualizar tokens
                Log::channel('marketplace')->info('[Shopee OAuth] shop_id conhecido sem state, tentando exchange', [
                    'shop_id'    => $shopId,
                    'account_id' => $existing->id,
                    'ip'         => $ip,
                ]);

                $exchangeResult = $this->exchangeToken($code, $shopId);
                if ($exchangeResult['success']) {
                    $accessToken  = $exchangeResult['access_token'];
                    $refreshToken = $exchangeResult['refresh_token'];
                    $expireIn     = $exchangeResult['expire_in'];

                    $shopName = $this->fetchShopName($shopId, $accessToken) ?? $existing->seller_nickname ?? 'Loja Shopee #' . $shopId;

                    $existing->update([
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
                    ]);

                    Log::channel('marketplace')->info('[Shopee OAuth] Tokens salvos via fluxo no_state (shop_id conhecido)', [
                        'flow'       => 'no_state_recovered',
                        'shop_id'    => $shopId,
                        'account_id' => $existing->id,
                        'shop_name'  => $shopName,
                        'timestamp'  => now()->toISOString(),
                    ]);

                    $this->updateCallbackResolution($callbackId, 'recovered_existing_account:' . $existing->id, true);
                    try { \App\Jobs\ImportMarketplaceAccountDataJob::dispatch($existing->id)->onQueue('default'); } catch (\Throwable $e) {}

                    // GOL-032: Se veio de WL legado (ret= na URL), salvar tokens no legado + bridge HMAC
                    if ($explicitReturnUrl && !str_contains($explicitReturnUrl, 'hubai.io') && !str_contains($explicitReturnUrl, 'fornecefy.io')) {
                        try {
                            $wlEmpresaId = $this->resolveWlEmpresaId($explicitReturnUrl);
                            $bridgeQuery = DB::connection('legacy')
                                ->table('integracao')
                                ->where('usuario', (string) $shopId)
                                ->whereIn('id_canal', [3, 5])
                                ->where('id_app_shopee', 2)
                                ->where('removida', 0);
                            if ($wlEmpresaId) {
                                $bridgeQuery->where('id_empresa', $wlEmpresaId);
                            }
                            $legadoBridge = $bridgeQuery->orderByDesc('id')->first(['id', 'id_login']);
                            if (!$legadoBridge && $wlEmpresaId) {
                                // GOL-034: cross-platform - shop esta em outra empresa, migrar para WL de origem
                                $crossRow = DB::connection('legacy')
                                    ->table('integracao')
                                    ->where('usuario', (string) $shopId)
                                    ->whereIn('id_canal', [3, 5])
                                    ->where('id_app_shopee', 2)
                                    ->where('removida', 0)
                                    ->orderByDesc('id')
                                    ->first(['id', 'id_login', 'id_empresa']);
                                if ($crossRow) {
                                    DB::connection('legacy')->table('integracao')
                                        ->where('id', $crossRow->id)
                                        ->update(['id_empresa' => $wlEmpresaId]);
                                    Log::channel('marketplace')->info('[Shopee OAuth] Conexao migrada cross-empresa (recovered)', [
                                        'flow'           => 'no_state_cross_empresa_migration',
                                        'shop_id'        => $shopId,
                                        'empresa_antiga' => $crossRow->id_empresa,
                                        'empresa_nova'   => $wlEmpresaId,
                                        'integracao_id'  => $crossRow->id,
                                        'ret'            => $explicitReturnUrl,
                                    ]);
                                    $legadoBridge = $crossRow;
                                }
                            }
                            if ($legadoBridge) {
                                // Salvar tokens no legado via bridge (igual no_state_legado_fallback)
                                $bridgeKeyLeg = config('services.goolhub.bridge_key', 'hb-bridge-2026-xK9mP3qR7vL2nW8');
                                $sigLeg       = hash_hmac('sha256', "shopee:{$legadoBridge->id_login}:{$shopId}:{$accessToken}", $bridgeKeyLeg);
                                Http::timeout(10)->asForm()->post(self::LEGADO_BRIDGE_ENDPOINT, [
                                    'user_id'       => (int) $legadoBridge->id_login,
                                    'shop_id'       => $shopId,
                                    'access_token'  => $accessToken,
                                    'refresh_token' => $refreshToken,
                                    'expire_in'     => $expireIn,
                                    'sig'           => $sigLeg,
                                ]);
                                $bridgeUrl = $this->resolveLegadoRedirectUrl((int) $legadoBridge->id_login, true, $shopId, $explicitReturnUrl);
                                Log::channel('marketplace')->info('[Shopee OAuth] no_state_recovered bridge legado', [
                                    'flow'        => 'no_state_recovered_legado_bridge',
                                    'shop_id'     => $shopId,
                                    'account_id'  => $existing->id,
                                    'legado_user' => (int) $legadoBridge->id_login,
                                    'ret'         => $explicitReturnUrl,
                                ]);
                                return redirect($bridgeUrl);
                            }
                        } catch (\Throwable $bridgeEx) {
                            Log::channel('single')->warning('[Shopee no_state_recovered] Bridge lookup falhou', ['error' => $bridgeEx->getMessage()]);
                        }
                    }
                    // HUB-183: cliente que VIVE no legado reautorizando direto da Shopee
                    // (sem ret=) caia aqui e era mandado pro painel-cliente NOVO — Termos de
                    // Uso indevidos + "erro ao processar token". Se a loja e bridge_managed no
                    // legado E o client da conta aponta pro mesmo id_login, espelha o token no
                    // bridge e devolve pro painel legado.
                    try {
                        $legadoRowRec = DB::connection('legacy')->table('integracao')
                            ->where('usuario', (string) $shopId)
                            ->whereIn('id_canal', [3, 5])
                            ->where('id_app_shopee', 2)
                            ->where('removida', 0)
                            ->where('bridge_managed', 1)
                            ->orderByDesc('id')
                            ->first(['id', 'id_login']);
                        $clientLegacyLogin = $existing->client_id
                            ? DB::table('clients')->where('id', $existing->client_id)->value('legacy_id_login')
                            : null;
                        if ($legadoRowRec && $clientLegacyLogin && (int) $clientLegacyLogin === (int) $legadoRowRec->id_login) {
                            $bridgeKeyRec = config('services.goolhub.bridge_key', 'hb-bridge-2026-xK9mP3qR7vL2nW8');
                            $sigRec       = hash_hmac('sha256', "shopee:{$legadoRowRec->id_login}:{$shopId}:{$accessToken}", $bridgeKeyRec);
                            Http::timeout(10)->asForm()->post(self::LEGADO_BRIDGE_ENDPOINT, [
                                'user_id'       => (int) $legadoRowRec->id_login,
                                'shop_id'       => $shopId,
                                'access_token'  => $accessToken,
                                'refresh_token' => $refreshToken,
                                'expire_in'     => $expireIn,
                                'sig'           => $sigRec,
                            ]);
                            $this->updateCallbackResolution($callbackId, 'recovered_legado_no_ret:' . $legadoRowRec->id_login, true);
                            Log::channel('marketplace')->info('[Shopee OAuth] no_state recovered de cliente do LEGADO (HUB-183)', [
                                'flow'        => 'no_state_recovered_legado_no_ret',
                                'shop_id'     => $shopId,
                                'account_id'  => $existing->id,
                                'legado_user' => (int) $legadoRowRec->id_login,
                            ]);
                            return redirect($this->resolveLegadoRedirectUrl((int) $legadoRowRec->id_login, true, $shopId, null));
                        }
                    } catch (\Throwable $legEx) {
                        Log::channel('single')->warning('[Shopee no_state_recovered] Legado check falhou (HUB-183)', ['error' => $legEx->getMessage()]);
                    }
                    return redirect(self::REDIRECT_SUCCESS . '&shop_id=' . urlencode((string) $shopId) . '&recovered=1');
                } else {
                    Log::channel('marketplace')->warning('[Shopee OAuth] Exchange falhou para shop_id conhecido sem state (code expirado)', [
                        'shop_id' => $shopId,
                        'error'   => $exchangeResult['error'] ?? 'unknown',
                        'hint'    => 'Usuario clicou em link salvo — redirecionar para reconectar',
                    ]);
                    $this->updateCallbackResolution($callbackId, 'exchange_failed_known_shop:' . ($exchangeResult['error'] ?? 'unknown'));
                    return redirect(self::REDIRECT_RECONNECT . '&shop_id=' . urlencode((string) $shopId));
                }
                        } else {
                // shop_id desconhecido no HubAI — tentar nos servicos externos (Legado, MultDrop, Fornecefy)
                //
                // ORDEM DOS LOOKUPS (correcao 2026-06-19):
                //  1) LEGADO (legacy.integracao)  — fonte de verdade por WL (id_empresa -> empresas.url).
                //                                   Tem que vir PRIMEIRO porque um mesmo shop_id pode existir
                //                                   tambem em fornecefy.marketplace_accounts (relay residual),
                //                                   o que antes "hijack"ava o redirect pra fornecefy.io.
                //  2) MultDrop (multdrop.marketplace_accounts)
                //  3) Fornecefy (fornecefy.marketplace_accounts)
                $externalFallbackDone = false;

                // --- LOOKUP 1: Legado (integracao por shop_id) — PRIORIDADE ALTA ---
                try {
                    $wlEmpresaId = $this->resolveWlEmpresaId($explicitReturnUrl);
                    $legadoQuery = DB::connection('legacy')
                        ->table('integracao')
                        ->where('usuario', (string) $shopId)
                        ->whereIn('id_canal', [3, 5])
                        ->where('id_app_shopee', 2)
                        ->where('removida', 0);
                    if ($wlEmpresaId) {
                        $legadoQuery->where('id_empresa', $wlEmpresaId);
                    }
                    $legadoRow = $legadoQuery->orderByDesc('id')->first(['id', 'id_login', 'usuario']);
                    if (!$legadoRow && $wlEmpresaId) {
                        // GOL-034: cross-platform - shop esta em outra empresa, migrar para WL de origem
                        $crossLegado = DB::connection('legacy')
                            ->table('integracao')
                            ->where('usuario', (string) $shopId)
                            ->whereIn('id_canal', [3, 5])
                            ->where('id_app_shopee', 2)
                            ->where('removida', 0)
                            ->orderByDesc('id')
                            ->first(['id', 'id_login', 'usuario', 'id_empresa']);
                        if ($crossLegado) {
                            DB::connection('legacy')->table('integracao')
                                ->where('id', $crossLegado->id)
                                ->update(['id_empresa' => $wlEmpresaId]);
                            Log::channel('marketplace')->info('[Shopee OAuth] Conexao migrada cross-empresa (fallback)', [
                                'flow'           => 'no_state_cross_empresa_migration_fallback',
                                'shop_id'        => $shopId,
                                'empresa_antiga' => $crossLegado->id_empresa,
                                'empresa_nova'   => $wlEmpresaId,
                                'integracao_id'  => $crossLegado->id,
                                'ret'            => $explicitReturnUrl,
                            ]);
                            $legadoRow = $crossLegado;
                        }
                    }

                    if ($legadoRow) {
                        // shop_id encontrado no legado - trocar code por tokens e salvar via bridge
                        $exchangeResultLegado = $this->exchangeToken($code, $shopId);
                        if ($exchangeResultLegado['success']) {
                            $legadoUserId  = (int) $legadoRow->id_login;
                            $atLegado      = $exchangeResultLegado['access_token'];
                            $rtLegado      = $exchangeResultLegado['refresh_token'];
                            $eiLegado      = $exchangeResultLegado['expire_in'];
                            $bridgeKey     = config('services.goolhub.bridge_key', 'hb-bridge-2026-xK9mP3qR7vL2nW8');
                            $sigLegado     = hash_hmac('sha256', "shopee:{$legadoUserId}:{$shopId}:{$atLegado}", $bridgeKey);

                            $bridgeRespLegado = Http::timeout(10)->asForm()->post(self::LEGADO_BRIDGE_ENDPOINT, [
                                'user_id'       => $legadoUserId,
                                'shop_id'       => $shopId,
                                'access_token'  => $atLegado,
                                'refresh_token' => $rtLegado,
                                'expire_in'     => $eiLegado,
                                'sig'           => $sigLegado,
                            ]);

                            Log::channel('marketplace')->info('[Shopee OAuth] Tokens salvos via fallback legado (no_state)', [
                                'flow'              => 'no_state_legado_fallback',
                                'shop_id'           => $shopId,
                                'legado_id'         => $legadoRow->id,
                                'user_id'           => $legadoUserId,
                                'bridge_status'     => $bridgeRespLegado->status(),
                                'bridge_body'       => substr($bridgeRespLegado->body(), 0, 200),
                                'explicit_return'   => $explicitReturnUrl,
                            ]);

                            $this->updateCallbackResolution($callbackId, 'no_state_legado_fallback:' . $legadoRow->id . ':bridge_' . $bridgeRespLegado->status(), true);
                            $externalFallbackDone = true;

                            // Redirecionar para o painel legado com sucesso.
                            // Se ?ret= foi passado na redirect_uri, usar essa URL direto (ex: multdropbr.com).
                            $returnUrlLegado = $this->resolveLegadoRedirectUrl($legadoUserId, true, $shopId, $explicitReturnUrl);
                            return redirect($returnUrlLegado);
                        } else {
                            Log::channel('marketplace')->warning('[Shopee OAuth] Exchange falhou no fallback legado', [
                                'shop_id' => $shopId,
                                'error'   => $exchangeResultLegado['error'] ?? 'unknown',
                            ]);
                        }
                    }
                } catch (\Throwable $legEx) {
                    Log::channel('single')->warning('[Shopee OAuth no_state] Falha no fallback legado', ['error' => $legEx->getMessage()]);
                }

                // --- LOOKUP 2: MultDrop (marketplace_accounts com shop_id preenchido) ---
                if (!$externalFallbackDone) {
                    try {
                        $multdropRow = DB::connection('multdrop')
                            ->table('marketplace_accounts')
                            ->where('shop_id', (string) $shopId)
                            ->where('platform', 'shopee')
                            ->whereIn('status', ['active', 'needs_reauth', 'needs_reconnect', 'pending'])
                            ->orderByDesc('id')
                            ->first(['id', 'client_id', 'supplier_id', 'account_name']);

                        if ($multdropRow) {
                            $exchangeResultMd = $this->exchangeToken($code, $shopId);
                            if ($exchangeResultMd['success']) {
                                $serviceConfig = config('shopee_oauth_services.multdrop');
                                $stateObj = (object) [
                                    'service'      => 'multdrop',
                                    'user_id'      => $multdropRow->client_id,
                                    'supplier_id'  => $multdropRow->supplier_id ?? 1,
                                    'account_name' => $multdropRow->account_name ?? null,
                                ];
                                $this->relayToService($serviceConfig['relay_url'], $exchangeResultMd, $shopId, $stateObj);
                                $this->updateCallbackResolution($callbackId, 'no_state_multdrop_fallback:account_' . $multdropRow->id . ':client_' . $multdropRow->client_id, true);
                                Log::channel('marketplace')->info('[Shopee OAuth] Tokens relayed para MultDrop via fallback no_state', [
                                    'flow'       => 'no_state_multdrop_fallback',
                                    'shop_id'    => $shopId,
                                    'account_id' => $multdropRow->id,
                                    'client_id'  => $multdropRow->client_id,
                                ]);
                                $externalFallbackDone = true;
                                $multdropRedirect = $this->buildServiceRedirectUrl('multdrop', (int) $shopId, true);
                                return redirect()->away($multdropRedirect);
                            } else {
                                Log::channel('marketplace')->warning('[Shopee OAuth no_state] Exchange falhou no fallback MultDrop', [
                                    'shop_id' => $shopId,
                                    'error'   => $exchangeResultMd['error'] ?? 'unknown',
                                ]);
                            }
                        }
                    } catch (\Throwable $mdEx) {
                        Log::channel('single')->warning('[Shopee OAuth no_state] Falha no fallback MultDrop', ['error' => $mdEx->getMessage()]);
                    }
                }

                // --- LOOKUP 3: Fornecefy (marketplace_accounts com shop_id preenchido) ---
                if (!$externalFallbackDone) {
                    try {
                        $fornecefyRow = DB::connection('fornecefy')
                            ->table('marketplace_accounts')
                            ->where('shop_id', (string) $shopId)
                            ->where('platform', 'shopee')
                            ->whereIn('status', ['active', 'needs_reauth', 'needs_reconnect', 'pending'])
                            ->orderByDesc('id')
                            ->first(['id', 'client_id', 'supplier_id', 'account_name']);

                        if ($fornecefyRow) {
                            $exchangeResultFn = $this->exchangeToken($code, $shopId);
                            if ($exchangeResultFn['success']) {
                                $serviceConfig = config('shopee_oauth_services.fornecefy');
                                $stateObj = (object) [
                                    'service'      => 'fornecefy',
                                    'user_id'      => $fornecefyRow->client_id,
                                    'supplier_id'  => $fornecefyRow->supplier_id ?? 1,
                                    'account_name' => $fornecefyRow->account_name ?? null,
                                ];
                                $this->relayToService($serviceConfig['relay_url'], $exchangeResultFn, $shopId, $stateObj);
                                $this->updateCallbackResolution($callbackId, 'no_state_fornecefy_fallback:account_' . $fornecefyRow->id . ':client_' . $fornecefyRow->client_id, true);
                                Log::channel('marketplace')->info('[Shopee OAuth] Tokens relayed para Fornecefy via fallback no_state', [
                                    'flow'       => 'no_state_fornecefy_fallback',
                                    'shop_id'    => $shopId,
                                    'account_id' => $fornecefyRow->id,
                                    'client_id'  => $fornecefyRow->client_id,
                                ]);
                                $externalFallbackDone = true;
                                $fornecefyRedirect = $this->buildServiceRedirectUrl('fornecefy', (int) $shopId, true);
                                return redirect()->away($fornecefyRedirect);
                            } else {
                                Log::channel('marketplace')->warning('[Shopee OAuth no_state] Exchange falhou no fallback Fornecefy', [
                                    'shop_id' => $shopId,
                                    'error'   => $exchangeResultFn['error'] ?? 'unknown',
                                ]);
                            }
                        }
                    } catch (\Throwable $fnEx) {
                        Log::channel('single')->warning('[Shopee OAuth no_state] Falha no fallback Fornecefy', ['error' => $fnEx->getMessage()]);
                    }
                }

                if (!$externalFallbackDone) {
                    Log::channel('marketplace')->warning('[Shopee OAuth] shop_id desconhecido sem state', [
                        'shop_id'    => $shopId,
                        'ip'         => $ip,
                        'user_agent' => substr($userAgent, 0, 200),
                        'referer'    => $referer,
                        'hint'       => 'Possivelmente Go-Live validation da Shopee ou link antigo sem state',
                    ]);
                    $this->updateCallbackResolution($callbackId, 'unknown_shop_id_no_state');
                }
            }
        } catch (\Throwable $e) {
            Log::channel('single')->error('[Shopee OAuth no_state] Excecao', ['error' => $e->getMessage()]);
        }

        // Redirecionar para o app com hint para reconectar
        // Fallback final: sem state, sem shop_id conhecido, exchange falhou ou legado inacessivel.
        // Se o legado embebeu ?ret= na redirect_uri, respeitar essa origem (devolve cliente
        // pro painel do WL que ele veio) em vez de jogar todo mundo no hubai.io.
        if ($explicitReturnUrl) {
            $sep = str_contains($explicitReturnUrl, '?') ? '&' : '?';
            return redirect($explicitReturnUrl . $sep . 'shopee=reconnect&error=link_expirado&shop_id=' . urlencode((string) $shopId));
        }
        return redirect(self::REDIRECT_RECONNECT . '&shop_id=' . urlencode((string) $shopId));
    }

    /**
     * Processa callback via state_token opaco gerado pelo ShopeeOAuthInitController.
     * Faz exchange dos tokens e relay para o servico de destino.
     */
    private function handleServiceFlow(string $code, int $shopId, object $stateRow, ?int $callbackId): \Illuminate\Http\RedirectResponse
    {
        $returnUrl = $stateRow->return_url;
        $service   = $stateRow->service;

        // Exchange code por tokens
        $exchangeResult = $this->exchangeToken($code, $shopId);
        if (!$exchangeResult['success']) {
            \Illuminate\Support\Facades\Log::error('[ShopeeOAuth handleServiceFlow] exchange falhou', [
                'service' => $service, 'shop_id' => $shopId, 'error' => $exchangeResult['error'] ?? 'unknown',
            ]);
            $this->updateCallbackResolution($callbackId, 'service_flow_exchange_failed:' . ($exchangeResult['error'] ?? 'unknown'));
            return redirect()->away($returnUrl . '?shopee=error&reason=exchange_failed&shop_id=' . $shopId);
        }

        $services = config('shopee_oauth_services');
        $serviceConfig = $services[$service] ?? null;

        if (!$serviceConfig) {
            return redirect()->away($returnUrl . '?shopee=error&reason=unknown_service');
        }

        // Relay para o servico de destino
        $relayOk = true;
        try {
            if (isset($serviceConfig['mode']) && $serviceConfig['mode'] === 'legacy_bridge') {
                // Legado: usa bridge endpoint existente (mesmo formato do handleLegadoCallback)
                $legadoUserId = (int) $stateRow->user_id;
                $bridgeKey = config('services.goolhub.bridge_key', 'hb-bridge-2026-xK9mP3qR7vL2nW8');
                $sig = hash_hmac('sha256', "shopee:{$legadoUserId}:{$shopId}:" . $exchangeResult['access_token'], $bridgeKey);
                \Illuminate\Support\Facades\Http::timeout(10)->asForm()->post(self::LEGADO_BRIDGE_ENDPOINT, [
                    'user_id'       => $legadoUserId,
                    'shop_id'       => $shopId,
                    'access_token'  => $exchangeResult['access_token'],
                    'refresh_token' => $exchangeResult['refresh_token'],
                    'expire_in'     => $exchangeResult['expire_in'],
                    'sig'           => $sig,
                ]);
            } else {
                // Relay HMAC para servicos externos (fornecefy, multdrop) ou relay interno (hubai)
                $relayOk = $this->relayToService($serviceConfig['relay_url'], $exchangeResult, $shopId, $stateRow);
            }
            if ($relayOk) {
                $this->updateCallbackResolution($callbackId, 'service_flow_ok:' . $service, true);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[ShopeeOAuth] relay falhou — tokens trocados, retentavel', [
                'service' => $service, 'shop_id' => $shopId, 'error' => $e->getMessage(),
            ]);
            $this->updateCallbackResolution($callbackId, 'service_flow_relay_failed:' . $e->getMessage());
            // Nao bloquear redirect: tokens foram trocados, relay pode ser retentado
        }

        // NOV-190: relay abortou sem resolver o dono da conta — nao mentir "connected"
        if (!$relayOk) {
            $this->updateCallbackResolution($callbackId, 'service_flow_relay_unresolved:' . $service);
            return redirect()->away($returnUrl . '?shopee=error&reason=account_not_resolved&shop_id=' . $shopId);
        }

        return redirect()->away($returnUrl . '?shopee=connected&shop_id=' . $shopId);
    }

    /**
     * Relay HMAC dos tokens para servico externo (fornecefy, multdrop) ou relay interno.
     */
    private function relayToService(string $relayUrl, array $tokens, int $shopId, object $stateRow): bool
    {
        // Resolver client_id real do servico externo:
        // O state guarda user_id (Laravel users.id do whitelabel, vindo do JWT do frontend).
        // O relay/marketplace_accounts no whitelabel usa clients.id (entidade negocio).
        // Para fornecefy/multdrop: lookup clients WHERE user_id = state.user_id => clients.id real.
        // Se nao achar, fallback para state.user_id (backwards compat com flows que ja mandam client_id).
        $service    = $stateRow->service;
        $rawUserId  = (int) $stateRow->user_id;
        $resolvedClientId = $rawUserId;
        try {
            if ($service === 'hubai' && $rawUserId > 0) {
                // Para hubai: o user_id no state e o legacy_id_login do goolhub (legado users.id).
                // Buscar clients WHERE legacy_id_login = rawUserId para obter clients.id real.
                $clientRow = \Illuminate\Support\Facades\DB::table('clients')
                    ->where('legacy_id_login', $rawUserId)
                    ->orderBy('id')
                    ->first(['id']);

                // NOV-190: cliente nascido direto no NovoHubAI (sem legado) — o painel
                // manda clients.id ou users.id no state. So tenta esses ids quando NAO
                // existe login legado com esse id (senao mantem fluxo HUB-079 intacto).
                if (!$clientRow) {
                    $legadoTemId = true;
                    try {
                        $legadoTemId = \Illuminate\Support\Facades\DB::connection('legacy')
                            ->table('login')
                            ->where('id', $rawUserId)
                            ->exists();
                    } catch (\Throwable $legEx) {
                        // legado inacessivel: preserva comportamento HUB-079 original
                    }
                    if (!$legadoTemId) {
                        $porId   = \Illuminate\Support\Facades\DB::table('clients')->where('id', $rawUserId)->first(['id']);
                        $porUser = \Illuminate\Support\Facades\DB::table('clients')->where('user_id', $rawUserId)->orderBy('id')->first(['id']);
                        $candidatos = collect([$porId->id ?? null, $porUser->id ?? null])->filter()->unique()->values();
                        if ($candidatos->count() === 1) {
                            $clientRow = (object) ['id' => (int) $candidatos->first()];
                            \Illuminate\Support\Facades\Log::channel('marketplace')->info('[ShopeeOAuth relay] hubai: client resolvido por id do NovoHubAI (NOV-190)', [
                                'raw_user_id' => $rawUserId,
                                'client_id'   => (int) $candidatos->first(),
                            ]);
                        } elseif ($candidatos->count() > 1) {
                            \Illuminate\Support\Facades\Log::channel('marketplace')->warning('[ShopeeOAuth relay] hubai: id ambiguo entre clients.id e clients.user_id, abortando (NOV-190)', [
                                'raw_user_id' => $rawUserId,
                                'candidatos'  => $candidatos->all(),
                            ]);
                            return false;
                        }
                        // 0 candidatos: segue HUB-079, que loga e aborta
                    }
                }

                if ($clientRow && !empty($clientRow->id)) {
                    $resolvedClientId = (int) $clientRow->id;
                    \Illuminate\Support\Facades\Log::channel('marketplace')->info('[ShopeeOAuth relay] hubai: client_id resolvido via legacy_id_login', [
                        'service'     => $service,
                        'legacy_user' => $rawUserId,
                        'client_id'   => $resolvedClientId,
                    ]);
                } else {
                    // HUB-079: usuario legado sem client no NovoHubAI.
                    // Criar User + Client automaticamente para nao bloquear a conexao Shopee.
                    try {
                        $legadoUser = \Illuminate\Support\Facades\DB::connection('legacy')
                            ->table('login')
                            ->where('id', $rawUserId)
                            ->first(['id', 'email', 'nome_completo', 'empresa']);

                        if (!$legadoUser || empty($legadoUser->email)) {
                            \Illuminate\Support\Facades\Log::channel('marketplace')->warning('[ShopeeOAuth relay] hubai: sem email no legado, abortando', [
                                'legacy_user_id' => $rawUserId,
                            ]);
                            return false;
                        }

                        // Verificar se o user ja existe no NovoHubAI por email
                        $existingUser = \App\Models\User::where('email', $legadoUser->email)->first();
                        if ($existingUser) {
                            // MUL-269 fase 2: company_name removido de clients — nome vem do user (accessor).
                            $newClient = \App\Models\Client::firstOrCreate(
                                ['user_id' => $existingUser->id],
                                [
                                    'document'         => '00000000000000',
                                    'is_active'        => true,
                                    'legacy_id_login'  => $rawUserId,
                                ]
                            );
                        } else {
                            // Criar User novo
                            $existingUser = \App\Models\User::create([
                                'name'     => $legadoUser->nome_completo ?: $legadoUser->email,
                                'email'    => $legadoUser->email,
                                'password' => bcrypt('123456'),
                                'role'     => 'client',
                            ]);
                            // MUL-269 fase 2: company_name removido de clients — nome vem do user (accessor).
                            $newClient = \App\Models\Client::updateOrCreate(
                                ['user_id' => $existingUser->id],
                                [
                                    'document'        => '00000000000000',
                                    'is_active'       => true,
                                    'legacy_id_login' => $rawUserId,
                                ]
                            );
                        }

                        // Garantir legacy_id_login no client
                        if (!$newClient->legacy_id_login) {
                            $newClient->update(['legacy_id_login' => $rawUserId]);
                        }

                        $resolvedClientId = (int) $newClient->id;
                        \Illuminate\Support\Facades\Log::channel('marketplace')->info('[ShopeeOAuth relay] hubai: client criado automaticamente para legado', [
                            'legacy_user_id' => $rawUserId,
                            'client_id'      => $resolvedClientId,
                            'email'          => $legadoUser->email,
                        ]);
                    } catch (\Throwable $createEx) {
                        \Illuminate\Support\Facades\Log::channel('marketplace')->error('[ShopeeOAuth relay] hubai: falha ao criar client automaticamente', [
                            'legacy_user_id' => $rawUserId,
                            'error'          => $createEx->getMessage(),
                        ]);
                        return false;
                    }
                }
            } elseif (in_array($service, ['fornecefy', 'multdrop'], true) && $rawUserId > 0) {
                $clientRow = \Illuminate\Support\Facades\DB::connection($service)
                    ->table('clients')
                    ->where('user_id', $rawUserId)
                    ->orderBy('id')
                    ->first(['id']);
                if ($clientRow && !empty($clientRow->id)) {
                    $resolvedClientId = (int) $clientRow->id;
                    \Illuminate\Support\Facades\Log::channel('marketplace')->info('[ShopeeOAuth relay] client_id resolvido via lookup', [
                        'service'   => $service,
                        'user_id'   => $rawUserId,
                        'client_id' => $resolvedClientId,
                    ]);
                } else {
                    \Illuminate\Support\Facades\Log::channel('marketplace')->warning('[ShopeeOAuth relay] clients.user_id nao encontrado, usando user_id como fallback', [
                        'service' => $service,
                        'user_id' => $rawUserId,
                    ]);
                }
            }
        } catch (\Throwable $lookupEx) {
            \Illuminate\Support\Facades\Log::channel('marketplace')->error('[ShopeeOAuth relay] lookup client_id falhou, usando user_id como fallback', [
                'service' => $service,
                'user_id' => $rawUserId,
                'error'   => $lookupEx->getMessage(),
            ]);
        }

        $payload = array_merge($tokens, [
            'shop_id'      => $shopId,
            'user_id'      => $stateRow->user_id,
            'client_id'    => $resolvedClientId,
            'supplier_id'  => $stateRow->supplier_id,
            'account_name' => $stateRow->account_name,
            'service'      => $stateRow->service,
            'relayed_by'   => 'api.hubai.io',
        ]);
        $payloadJson = json_encode($payload);
        $sig = hash_hmac('sha256', $payloadJson, config('services.shopee.bridge_secret', ''));

        \Illuminate\Support\Facades\Http::timeout(10)
            ->withHeaders(['X-HubAI-Bridge-Sig' => $sig, 'Content-Type' => 'application/json'])
            ->withBody($payloadJson, 'application/json')
            ->post($relayUrl)
            ->throw();

        return true;
    }

    /**
     * Troca um code Shopee por access_token e refresh_token.
     */
    private function exchangeToken(string $code, int $shopId): array
    {
        $partnerId  = (int) config('services.shopee.partner_id');
        $partnerKey = config('services.shopee.partner_key');
        $timestamp  = time();
        $path       = '/api/v2/auth/token/get';
        $sign       = hash_hmac('sha256', $partnerId . $path . $timestamp, $partnerKey);

        try {
            // A Shopee API v2 exige partner_id, timestamp e sign na query string
            // e apenas code + shop_id no body JSON
            $queryString = http_build_query([
                'partner_id' => $partnerId,
                'timestamp'  => $timestamp,
                'sign'       => $sign,
            ]);
            $response = Http::timeout(15)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->withBody(json_encode(['code' => $code, 'shop_id' => $shopId]), 'application/json')
                ->post('https://partner.shopeemobile.com/api/v2/auth/token/get?' . $queryString);

            $data = $response->json();

            if ($response->failed() || ! empty($data['error'])) {
                return ['success' => false, 'error' => $data['error'] ?? 'http_' . $response->status()];
            }

            return [
                'success'       => true,
                'access_token'  => $data['access_token']  ?? '',
                'refresh_token' => $data['refresh_token']  ?? '',
                'expire_in'     => (int) ($data['expire_in'] ?? 14400),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'exception:' . $e->getMessage()];
        }
    }

    /**
     * Busca o nome da loja via get_shop_info.
     */
    private function fetchShopName(int $shopId, string $accessToken): ?string
    {
        $partnerId  = (int) config('services.shopee.partner_id');
        $partnerKey = config('services.shopee.partner_key');
        try {
            $path = '/api/v2/shop/get_shop_info';
            $ts   = time();
            $base = $partnerId . $path . $ts . $accessToken . $shopId;
            $sign = hash_hmac('sha256', $base, $partnerKey);
            $resp = Http::timeout(10)->get(
                'https://partner.shopeemobile.com/api/v2/shop/get_shop_info',
                [
                    'partner_id'   => $partnerId,
                    'timestamp'    => $ts,
                    'access_token' => $accessToken,
                    'shop_id'      => $shopId,
                    'sign'         => $sign,
                ]
            );
            if ($resp->ok()) {
                return $resp->json('response.shop_name');
            }
        } catch (\Throwable $e) {
            Log::warning('[Shopee OAuth] get_shop_info falhou: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * Bridge relay: troca o code por tokens aqui (usando credenciais HubAI) e faz POST HMAC
     * para o sistema externo (ex: api.fornecefy.io) que recebe os tokens prontos.
     */
    private function handleBridgeRelay(string $code, int $shopId, array $payload, ?int $callbackId): RedirectResponse
    {
        $returnUrl     = $payload['return_url'] ?? self::REDIRECT_ERROR;
        $returnSep     = str_contains($returnUrl, '?') ? '&' : '?';
        $errorRedirect = $returnUrl . $returnSep . 'shopee=error';

        $exchangeResult = $this->exchangeToken($code, $shopId);
        if (! $exchangeResult['success']) {
            Log::error('[Shopee Bridge Relay] Token exchange falhou', [
                'shop_id' => $shopId,
                'error'   => $exchangeResult['error'],
            ]);
            $this->updateCallbackResolution($callbackId, 'bridge_exchange_failed:' . $exchangeResult['error']);
            return redirect($errorRedirect . '&reason=bridge_token_exchange');
        }

        $accessToken  = $exchangeResult['access_token'];
        $refreshToken = $exchangeResult['refresh_token'];
        $expireIn     = $exchangeResult['expire_in'];

        $originCallback = $payload['origin_callback'];
        $bridgeSecret   = config('services.shopee.bridge_secret', '');

        $relayPayload = [
            'shop_id'       => $shopId,
            'client_id'     => $payload['client_id'] ?? null,
            'supplier_id'   => $payload['supplier_id'] ?? null,
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'expire_in'     => $expireIn,
            'relayed_by'    => 'api.hubai.io',
        ];

        $relayJson = json_encode($relayPayload);
        $signature = hash_hmac('sha256', $relayJson, $bridgeSecret);

        try {
            $relayResp = Http::timeout(10)
                ->withHeaders([
                    'X-HubAI-Bridge-Sig' => $signature,
                    'Content-Type'       => 'application/json',
                ])
                ->withBody($relayJson, 'application/json')
                ->post($originCallback);

            Log::channel('marketplace')->info('[Shopee OAuth] Tokens relayed via bridge', [
                'flow'            => 'bridge_relay',
                'shop_id'         => $shopId,
                'origin_callback' => $originCallback,
                'relay_status'    => $relayResp->status(),
                'client_id'       => $payload['client_id'] ?? null,
                'timestamp'       => now()->toISOString(),
            ]);

            if ($relayResp->failed()) {
                Log::error('[Shopee Bridge Relay] Destino retornou erro', [
                    'status' => $relayResp->status(),
                    'body'   => substr($relayResp->body(), 0, 500),
                ]);
                $this->updateCallbackResolution($callbackId, 'bridge_relay_dest_error:' . $relayResp->status());
            } else {
                $this->updateCallbackResolution($callbackId, 'bridge_relay_ok:' . $relayResp->status(), true);
            }
        } catch (\Throwable $e) {
            Log::error('[Shopee Bridge Relay] Excecao no relay', ['error' => $e->getMessage()]);
            $this->updateCallbackResolution($callbackId, 'bridge_relay_exception');
            return redirect($errorRedirect . '&reason=bridge_exception');
        }

        $redirectAfter = $payload['redirect_after'] ?? $payload['return_url'] ?? self::REDIRECT_SUCCESS;
        $separator = str_contains($redirectAfter, '?') ? '&' : '?';

        return redirect($redirectAfter . $separator . 'shop_id=' . urlencode((string) $shopId));
    }

    /**
     * Processa callback OAuth quando o state e o ID de um MarketplaceAccount.
     */
    private function handleAccountIdCallback(string $code, int $shopId, int $accountId, ?int $callbackId): RedirectResponse
    {
        $exchangeResult = $this->exchangeToken($code, $shopId);
        if (! $exchangeResult['success']) {
            Log::error('[Shopee OAuth AccountId] Token exchange falhou', [
                'account_id' => $accountId,
                'shop_id'    => $shopId,
                'error'      => $exchangeResult['error'],
            ]);
            $this->updateCallbackResolution($callbackId, 'account_id_exchange_failed:' . $exchangeResult['error']);
            return redirect(self::REDIRECT_ERROR . '&reason=token_exchange');
        }

        $accessToken  = $exchangeResult['access_token'];
        $refreshToken = $exchangeResult['refresh_token'];
        $expireIn     = $exchangeResult['expire_in'];

        $shopName = $this->fetchShopName($shopId, $accessToken) ?? 'Loja Shopee #' . $shopId;

        try {
            $account = \App\Models\MarketplaceAccount::find($accountId);
            if ($account) {
                $account->update([
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
                ]);
                Log::channel('marketplace')->info('[Shopee OAuth] Conexao OAuth concluida', [
                    'flow'       => 'account_id',
                    'account_id' => $accountId,
                    'shop_id'    => $shopId,
                    'shop_name'  => $shopName,
                    'timestamp'  => now()->toISOString(),
                ]);
                $this->updateCallbackResolution($callbackId, 'account_id_saved:' . $accountId, true);
                if ($account) { try { \App\Jobs\ImportMarketplaceAccountDataJob::dispatch($account->id)->onQueue('default'); } catch (\Throwable $e) {} }
            } else {
                Log::warning('[Shopee OAuth AccountId] MarketplaceAccount nao encontrado', ['account_id' => $accountId]);
                $this->updateCallbackResolution($callbackId, 'account_id_not_found:' . $accountId);
            }
        } catch (\Throwable $e) {
            Log::error('[Shopee OAuth AccountId] Excecao', ['account_id' => $accountId, 'error' => $e->getMessage()]);
            $this->updateCallbackResolution($callbackId, 'account_id_exception');
            return redirect(self::REDIRECT_ERROR . '&reason=exception');
        }

        return redirect(self::REDIRECT_SUCCESS . '&shop_id=' . urlencode((string) $shopId));
    }

    /**
     * Processa callback OAuth vindo do legado (goolhub.io).
     */
    private function handleLegadoCallback(string $code, int $shopId, string $state, ?int $callbackId, ?string $explicitReturnUrl = null): RedirectResponse
    {
        // State: legado:USER_ID[:dep:DEP_ID][:ret:BASE64_RETURN_URL]
        $stateParts   = explode(':', str_replace('legado:', '', $state));
        $legadoUserId = (int) $stateParts[0];

        // Extrai return_url embutida no state (formato novo — dinâmico por WL)
        $stateReturnUrl = null;
        $retIdx = array_search('ret', $stateParts);
        if ($retIdx !== false && isset($stateParts[$retIdx + 1])) {
            $decoded = base64_decode($stateParts[$retIdx + 1], true);
            if ($decoded && filter_var($decoded, FILTER_VALIDATE_URL)) {
                $stateReturnUrl = $decoded;
            }
        }

        // GOL-030: $explicitReturnUrl (do ?ret= na redirect_uri) tem prioridade sobre o :ret: do state.
        // A Shopee pode descartar o state em alguns flows — o ?ret= na redirect_uri e mais confiavel.
        if ($explicitReturnUrl) {
            $stateReturnUrl = $explicitReturnUrl;
        }

        $exchangeResult = $this->exchangeToken($code, $shopId);
        if (! $exchangeResult['success']) {
            Log::error('[Shopee OAuth Legado] Token exchange falhou', [
                'user_id' => $legadoUserId,
                'shop_id' => $shopId,
                'error'   => $exchangeResult['error'],
            ]);
            $this->updateCallbackResolution($callbackId, 'legado_exchange_failed:' . $exchangeResult['error']);
            return redirect($this->resolveLegadoRedirectUrl($legadoUserId, false, $shopId, $stateReturnUrl) . '&reason=token_exchange');
        }

        $accessToken  = $exchangeResult['access_token'];
        $refreshToken = $exchangeResult['refresh_token'];
        $expireIn     = $exchangeResult['expire_in'];

        try {
            $bridgeKey  = config('services.goolhub.bridge_key', 'hb-bridge-2026-xK9mP3qR7vL2nW8');
            $sig        = hash_hmac('sha256', "shopee:{$legadoUserId}:{$shopId}:{$accessToken}", $bridgeKey);

            $bridgeResp = Http::timeout(10)->asForm()->post(self::LEGADO_BRIDGE_ENDPOINT, [
                'user_id'       => $legadoUserId,
                'shop_id'       => $shopId,
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
                'expire_in'     => $expireIn,
                'sig'           => $sig,
            ]);

            Log::info('[Shopee OAuth Legado] Tokens salvos via bridge', [
                'user_id'       => $legadoUserId,
                'shop_id'       => $shopId,
                'bridge_status' => $bridgeResp->status(),
            ]);

            $this->updateCallbackResolution($callbackId, 'legado_bridge_ok:' . $bridgeResp->status(), true);
        } catch (\Throwable $e) {
            Log::error('[Shopee OAuth Legado] Excecao', ['user_id' => $legadoUserId, 'error' => $e->getMessage()]);
            $this->updateCallbackResolution($callbackId, 'legado_exception');
            return redirect($this->resolveLegadoRedirectUrl($legadoUserId, false, $shopId, $stateReturnUrl) . '&reason=exception');
        }



        // MIGRACAO LAZY: enfileira Job async — nao bloqueia o redirect do OAuth (GOL-033)
        try {
            \App\Jobs\MigrateShopeeUserToNovoHubAIJob::dispatch(
                $legadoUserId, $shopId, $accessToken, $refreshToken, $expireIn
            )->onQueue('default');
        } catch (\Throwable $e) {
            Log::warning('[ShopeeOAuth] Falha ao enfileirar MigrateShopeeUserToNovoHubAIJob', [
                'legacy_user_id' => $legadoUserId,
                'error' => $e->getMessage(),
            ]);
        }
        return redirect($this->resolveLegadoRedirectUrl($legadoUserId, true, $shopId, $stateReturnUrl));
    }

    /**
     * Atualiza resolution e opcionalmente processed no registro de callback.
     */

    /** GOL-047: Mapa host -> id_empresa para filtrar integracao por WL de origem */
    private static array $WL_EMPRESA_MAP = [
        'app.multdropbr.com'         => 24,
        'app.mestoredrop.com.br'     => 20,
        'app.pluglar.com.br'         => 15,
        'app.soudrop.com.br'         => 7,
        'app.gauchododrope.com.br'   => 4,
        'app.envionacional.com.br'   => 5,
        'app.atravessadorpro.com.br' => 13,
        'app.infinitydropbr.com'     => 14,
        'app.plusdropoficial.com.br' => 16,
        'app.jtdrop.com.br'          => 17,
        'app.updrop.com.br'          => 18,
        'app.dropmaxi.com.br'        => 19,
        'app.dropksr.com.br'         => 21,
        'app.weedrop.io'             => 22,
        'app.drop2you.com.br'        => 23,
    ];

    /**
     * GOL-047: Resolve o id_empresa esperado a partir do host da URL de retorno WL.
     * Retorna null para URLs nao-mapeadas (goolhub.io, hubai.io, fornecefy.io etc).
     */
    private function resolveWlEmpresaId(?string $url): ?int
    {
        if (!$url) return null;
        $host = parse_url($url, PHP_URL_HOST);
        return $host ? (self::$WL_EMPRESA_MAP[$host] ?? null) : null;
    }

    /**
     * Resolve o URL de retorno correto para o fluxo legado com base na empresa do usuario.
     * Cada whitelabel tem seu proprio dominio - nunca redirecionar para goolhub.io genericamente.
     * Mapa atualizado em 2026-06-17 a partir da tabela empresas do legado.
     */
    private function resolveLegadoRedirectUrl(int $legadoUserId, bool $success, int $shopId, ?string $stateReturnUrl = null): string
    {
        $returnBase = config('services.goolhub.root_url', 'https://goolhub.io'); // NOV-088: fallback dominio legado via config (nunca hardcoded)

        if ($stateReturnUrl) {
            // Prioridade: URL dinamica embutida no state pelo proprio WL de origem
            $returnBase = $stateReturnUrl;
            Log::channel('marketplace')->info('[Shopee OAuth Legado] Usando return_url do state (dinamico)', [
                'user_id'    => $legadoUserId,
                'return_url' => $returnBase,
            ]);
        } else {
            // Fallback: busca URL na tabela empresas do legado (compatibilidade com estados antigos)
            try {
                $empresaUrl = DB::connection('legacy')
                    ->table('login as l')
                    ->join('empresas as e', 'e.id', '=', 'l.id_empresa')
                    ->where('l.id', $legadoUserId)
                    ->value('e.url');

                if ($empresaUrl && filter_var($empresaUrl, FILTER_VALIDATE_URL)) {
                    $returnBase = rtrim($empresaUrl, '/');
                }

                Log::channel('marketplace')->info('[Shopee OAuth Legado] URL resolvida via DB (fallback state antigo)', [
                    'user_id'  => $legadoUserId,
                    'base_url' => $returnBase,
                ]);
            } catch (\Throwable $e) {
                Log::warning('[Shopee OAuth Legado] Falha ao resolver empresa via DB — usando base_url do bridge como fallback (NOV-088)', [
                    'user_id' => $legadoUserId,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        // GOL-031: Se stateReturnUrl fornecida, redirecionar para o site de origem.
        // Quando stateReturnUrl e so o dominio (sem path, ex: http://app.multdropbr.com),
        // forcar https:// e usar pagina intermediaria com path legado para garantir que
        // o PHP legado receba shopee_connected=1 na URL correta.
        if ($stateReturnUrl) {
            // Normalizar: forcar https:// e remover trailing slash
            $normalizedReturn = rtrim(preg_replace('#^http://#', 'https://', $stateReturnUrl), '/');
            $parsedPath = parse_url($normalizedReturn, PHP_URL_PATH);
            $hasPath = $parsedPath && $parsedPath !== '/';

            if ($success) {
                if ($hasPath) {
                    // GOL-032 FIX: URL com path pode ser tanto hubai.io (NovoHubAI interno)
                    // quanto WL externo (ex: app.multdropbr.com/v3_integracao...). 
                    // Para WL externos: sempre usar v3_oauth-return.php com HMAC para
                    // reconstruir sessao PHP — redirect direto quebra sessao.
                    $parsedHost = parse_url($normalizedReturn, PHP_URL_HOST);
                    $isExternalWl = $parsedHost && ! str_ends_with($parsedHost, 'hubai.io');
                    if ($isExternalWl) {
                        // Extrair dominio-base para construir URL do bridge
                        $parsedScheme = parse_url($normalizedReturn, PHP_URL_SCHEME) ?: 'https';
                        $wlBaseUrl    = $parsedScheme . '://' . $parsedHost;
                        $ts        = time();
                        $bridgeKey = config('services.goolhub.bridge_key', 'hb-bridge-2026-xK9mP3qR7vL2nW8');
                        $sig       = hash_hmac('sha256', $legadoUserId . ':' . $ts, $bridgeKey);
                        // $dest e a URL completa (pode ja ter path + shopee_connected)
                        // Se nao tiver shopee_connected, adicionar
                        $dest = $normalizedReturn;
                        if (! str_contains($dest, 'shopee_connected')) {
                            $sep = str_contains($dest, '?') ? '&' : '?';
                            $dest .= $sep . 'shopee_connected=1&shop_id=' . urlencode((string) $shopId);
                        }
                        return $wlBaseUrl . '/v3_oauth-return.php'
                            . '?uid=' . urlencode((string) $legadoUserId)
                            . '&ts='   . $ts
                            . '&sig=' . urlencode($sig)
                            . '&ret=' . urlencode(base64_encode($dest));
                    }
                    // hubai.io ou outro dominio proprio: redirecionar direto
                    $sep = str_contains($normalizedReturn, '?') ? '&' : '?';
                    return $normalizedReturn . $sep . 'connected=shopee&shop_id=' . urlencode((string) $shopId);
                } else {
                    // GOL-032: Apenas dominio WL sem path (ex: http://app.multdropbr.com).
                    // Usar v3_oauth-return.php que reconstroi sessao PHP via HMAC seguro
                    // e redireciona para a pagina de integracao com shopee_connected=1.
                    $ts        = time();
                    $bridgeKey = config('services.goolhub.bridge_key', 'hb-bridge-2026-xK9mP3qR7vL2nW8');
                    $sig       = hash_hmac('sha256', $legadoUserId . ':' . $ts, $bridgeKey);
                    $dest      = $normalizedReturn . '/v3_integracao-add-shopee-v2.php?shopee_connected=1&shop_id=' . urlencode((string) $shopId);
                    return $normalizedReturn . '/v3_oauth-return.php'
                        . '?uid=' . urlencode((string) $legadoUserId)
                        . '&ts='  . $ts
                        . '&sig=' . urlencode($sig)
                        . '&ret=' . urlencode(base64_encode($dest));
                }
            }
            $sep = str_contains($normalizedReturn, '?') ? '&' : '?';
            return $normalizedReturn . $sep . 'error=shopee_failed';
        }
        // Sem return_url no state: o legado WL redireciona para login quando recebe
        // ?shopee_connected=1 porque a sessao PHP nao sobrevive ao redirect cross-domain
        // (api.hubai.io -> WL). Solucao: usar pagina de sucesso intermediaria no NovoHubAI
        // que nao requer sessao, exibe confirmacao e botao de retorno ao painel WL.
        if ($success) {
            return $returnBase . '/v3_integracao-add-shopee-v2.php?shopee_connected=1&shop_id=' . urlencode((string) $shopId) . '&logado=' . urlencode((string) $legadoUserId);
        }
        // Erro: redirecionar ao WL normalmente (nao tem problema pedir login no fluxo de erro)
        return $returnBase . '/v3_integracao-add-shopee-v2.php?shopee_error=1';
    }

    /**
     * Monta a URL de redirect para um servico externo (multdrop, fornecefy, hubai) usando
     * o success_path definido em config/shopee_oauth_services.php. Antes essa URL estava
     * hardcoded ("https://multdrop.app/integracoes"), o que dificultava trocar painel.
     */
    private function buildServiceRedirectUrl(string $service, int $shopId, bool $success): string
    {
        $config = config('shopee_oauth_services.' . $service);
        $domain = null;
        if (is_array($config) && !empty($config['allowed_return_domains'])) {
            foreach ($config['allowed_return_domains'] as $candidate) {
                if ($candidate !== '*') {
                    $domain = $candidate;
                    break;
                }
            }
        }
        $successPath = $config['success_path'] ?? '/integracoes';

        // Fallback hardcoded caso config esteja vazio (mantem comportamento anterior).
        if (!$domain) {
            $domain = match ($service) {
                'multdrop'    => 'multdrop.app',
                'fornecefy'   => 'fornecefy.io',
                'mestoredrop' => 'app.mestoredrop.com.br',
                'hubai'       => 'app.hubai.io',
                default       => 'app.hubai.io',
            };
        }

        $base = 'https://' . ltrim($domain, '/') . $successPath;
        $sep  = str_contains($base, '?') ? '&' : '?';
        $query = ($success ? 'shopee=connected' : 'shopee=error') . '&shop_id=' . urlencode((string) $shopId) . '&recovered=1';
        return $base . $sep . $query;
    }

    /**
     * @deprecated Use MigrateShopeeUserToNovoHubAIJob (GOL-033) — metodo mantido para referencia.
     * Migra ou atualiza client do legado no NovoHubAI apos reconexao Shopee.
     * Nunca bloqueia o fluxo OAuth principal — excecoes sao capturadas internamente.
     */
    private function migrateOrUpdateLegadoClient(
        int $legadoUserId,
        int $shopId,
        string $accessToken,
        string $refreshToken,
        int $expireIn
    ): void {
        try {
            // 1. Verifica se client ja existe no NovoHubAI
            $client = \App\Models\Client::where('legacy_id_login', $legadoUserId)->first();

            // 2. Se nao existe, busca dados no legado via bridge e cria
            if (!$client) {
                $userInfo = $this->fetchLegadoUserInfo($legadoUserId);
                if (!$userInfo) {
                    Log::warning('[ShopeeOAuth migracao] Nao foi possivel buscar dados do legado', [
                        'legacy_user_id' => $legadoUserId,
                    ]);
                    return;
                }

                $email = $userInfo['email'] ?? "legado_{$legadoUserId}@hubai.io";
                $user = \App\Models\User::firstOrCreate(
                    ['email' => $email],
                    [
                        'name'     => $userInfo['name'] ?? "Cliente {$legadoUserId}",
                        'password' => bcrypt(\Illuminate\Support\Str::random(32)),
                    ]
                );

                // MUL-269 fase 2: company_name removido de clients — nome vem do user (accessor).
                $client = \App\Models\Client::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'legacy_id_login' => $legadoUserId,
                        'phone'           => $userInfo['phone'] ?? null,
                        'document'        => $userInfo['document'] ?? null,
                        'is_active'       => true,
                    ]
                );

                Log::info('[ShopeeOAuth migracao] Client criado no NovoHubAI', [
                    'legacy_user_id' => $legadoUserId,
                    'client_id'      => $client->id,
                    'user_id'        => $user->id,
                ]);
            }

            // 3. Cria ou atualiza marketplace_account com tokens encriptados
            \App\Models\MarketplaceAccount::updateOrCreate(
                [
                    'client_id' => $client->id,
                    'platform'  => 'shopee',
                    'shop_id'   => (string) $shopId,
                ],
                [
                    'access_token'             => encrypt($accessToken),
                    'refresh_token'            => encrypt($refreshToken),
                    'token_expires_at'         => now()->addSeconds($expireIn),
                    'refresh_token_expires_at' => now()->addDays(30),
                    'service'                  => 'hubai',
                    'status'                   => 'active',
                    'last_token_refresh_at'    => now(),
                    'seller_id'                => (string) $shopId,
                ]
            );

            Log::info('[ShopeeOAuth migracao] marketplace_account sincronizado', [
                'legacy_user_id' => $legadoUserId,
                'client_id'      => $client->id,
                'shop_id'        => $shopId,
            ]);

        } catch (\Throwable $e) {
            // Nunca deixar falha na migracao quebrar o fluxo principal do OAuth
            Log::error('[ShopeeOAuth migracao] Erro na migracao lazy', [
                'legacy_user_id' => $legadoUserId,
                'error'          => $e->getMessage(),
            ]);
        }
    }

    /**
     * Busca dados do usuario no legado via endpoint bridge HTTP (HMAC-assinado).
     * Retorna array com email, name, company_name, phone, document ou null em falha.
     * O endpoint eh criado pelo agente de bridge em goolhub.io.
     */
    private function fetchLegadoUserInfo(int $legadoUserId): ?array
    {
        try {
            $bridgeKey = config('services.goolhub.bridge_key', 'hb-bridge-2026-xK9mP3qR7vL2nW8');
            $sig = hash_hmac('sha256', "getuser:{$legadoUserId}", $bridgeKey);

            $response = \Illuminate\Support\Facades\Http::timeout(5)
                ->get('https://goolhub.io/api/bridge/shopee_get_user_info.php', [
                    'legacy_id' => $legadoUserId,
                    'sig'       => $sig,
                ]);

            if ($response->successful() && $response->json('success')) {
                return $response->json();
            }

            Log::warning('[ShopeeOAuth migracao] Bridge retornou erro ao buscar user info', [
                'legacy_user_id' => $legadoUserId,
                'status'         => $response->status(),
                'body'           => substr($response->body(), 0, 300),
            ]);
            return null;

        } catch (\Throwable $e) {
            Log::error('[ShopeeOAuth migracao] Falha ao chamar bridge shopee_get_user_info', [
                'legacy_user_id' => $legadoUserId,
                'error'          => $e->getMessage(),
            ]);
            return null;
        }
    }


    private function updateCallbackResolution(?int $callbackId, string $resolution, bool $processed = false): void
    {
        if (! $callbackId) {
            return;
        }
        try {
            DB::table('shopee_oauth_callbacks')
                ->where('id', $callbackId)
                ->update([
                    'resolution' => $resolution,
                    'processed'  => $processed,
                    'updated_at' => now(),
                ]);
        } catch (\Throwable $e) {
            // silencioso
        }
    }
}
