<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class ShopeeOAuthInitController extends Controller
{
    public function init(Request $request): RedirectResponse
    {
        // SEL-326 FASE D (23/07 00:xx): rota GET publica com user_id cru no query era
        // vulneravel a spoof (init OAuth em nome de qualquer cliente). Fronts migraram
        // pra POST autenticado por JWT (Fase C). GET fica bloqueado com 410 Gone.
        // Se algum consumer antigo cair aqui, quebra visivelmente e o dono migra.
        abort(410, 'GET /api/shopee/oauth/init foi descontinuado (SEL-326 Fase D). Use POST autenticado por JWT: obter JWT via POST /api/auth/oauth-init-token no backend do WL, depois POST /api/shopee/oauth/init com Authorization Bearer.');

        $service      = $request->query('service', 'hubai');
        $userId       = $request->query('user_id', '');
        $supplierId   = $request->query('supplier_id');
        $accountName  = $request->query('account_name');
        $sourceSystem = $request->query('source_system'); // identifica o sistema de origem (ex: fornecefy, multdrop)

        // SEL-326 (22/07): validar JWT curto (Bearer) emitido pelo backend do WL.
        // Se presente, sobrescreve user_id/service do query string — evita spoof onde
        // atacante monta URL /init?user_id=X pra iniciar OAuth em nome de outro cliente.
        // Bug histórico: init era rota pública sem middleware auth (rota linha 940).
        // Retrocompat: sem JWT, comportamento antigo preservado durante migração dos fronts.
        $jwtSecret = (string) config('services.shopee_init_jwt.secret', '');
        $authHeader = (string) $request->header('Authorization', '');
        if ($jwtSecret !== '' && str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
            $parts = explode('.', $token);
            if (count($parts) === 3) {
                [$b64h, $b64p, $b64s] = $parts;
                $expectedSig = rtrim(strtr(base64_encode(hash_hmac('sha256', $b64h . '.' . $b64p, $jwtSecret, true)), '+/', '-_'), '=');
                if (hash_equals($expectedSig, $b64s)) {
                    $decoded = json_decode(base64_decode(strtr($b64p, '-_', '+/')), true);
                    if (is_array($decoded) && isset($decoded['exp']) && $decoded['exp'] > time()) {
                        $jwtUserId  = isset($decoded['sub']) ? (string) $decoded['sub'] : '';
                        $jwtService = isset($decoded['svc']) ? (string) $decoded['svc'] : '';
                        if ($jwtUserId !== '') {
                            if ($userId !== '' && $userId !== $jwtUserId) {
                                \Log::channel('marketplace')->warning('[Shopee Init SEL-326] user_id do query divergiu do JWT — usando JWT', [
                                    'query_user_id' => $userId,
                                    'jwt_user_id'   => $jwtUserId,
                                    'ip'            => $request->ip(),
                                ]);
                            }
                            $userId = $jwtUserId;
                        }
                        if ($jwtService !== '' && ($service === 'hubai' || $service === '')) {
                            $service = $jwtService;
                        }
                    } else {
                        \Log::channel('marketplace')->warning('[Shopee Init SEL-326] JWT expirado ou payload inválido', ['ip' => $request->ip()]);
                    }
                } else {
                    \Log::channel('marketplace')->warning('[Shopee Init SEL-326] JWT com assinatura inválida', ['ip' => $request->ip()]);
                }
            }
        }

        // redirect_after e o nome canonico novo; return_url e alias legado — ambos aceitos
        // Prioridade: redirect_after > return_url
        $returnUrl = $request->query('redirect_after')
            ?? $request->query('return_url', '');

        $services = config('shopee_oauth_services');

        if (!isset($services[$service])) {
            return redirect()->away('https://hubai.io/painel-cliente/integracoes?shopee=error&reason=invalid_service');
        }

        // Validar return_url contra dominios permitidos do service
        if ($returnUrl && !empty($services[$service]['allowed_return_domains'])) {
            $allowed = $services[$service]['allowed_return_domains'];
            if (!in_array('*', $allowed)) {
                $host  = parse_url($returnUrl, PHP_URL_HOST);
                $valid = false;
                foreach ($allowed as $domain) {
                    if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                        $valid = true;
                        break;
                    }
                }
                if (!$valid) {
                    return redirect()->away('https://hubai.io/painel-cliente/integracoes?shopee=error&reason=invalid_return_url');
                }
            }
        }

        if (empty($returnUrl)) {
            $returnUrl = 'https://hubai.io' . ($services[$service]['success_path'] ?? '/painel-cliente/integracoes');
        }

        // Gerar state opaco
        $stateToken = bin2hex(random_bytes(32));

        // extra: metadados adicionais (source_system ja tem coluna propria, mas guardamos em extra tb para rastreio completo)
        $extra = [];
        if ($sourceSystem) {
            $extra['source_system'] = $sourceSystem;
        }

        DB::table('shopee_oauth_states')->insert([
            'state_token'   => $stateToken,
            'service'       => $service,
            'user_id'       => $userId,
            'return_url'    => $returnUrl,
            'supplier_id'   => $supplierId ?: null,
            'account_name'  => $accountName ?: null,
            'source_system' => $sourceSystem ?: null,
            'extra'         => !empty($extra) ? json_encode($extra) : null,
            'expires_at'    => now()->addMinutes(10),
            'ip'            => $request->ip(),
            'user_agent'    => substr($request->userAgent() ?? '', 0, 512),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        // Gerar URL OAuth Shopee
        // IMPORTANTE: A Shopee NAO devolve o parametro "state" no callback.
        // Solucao: embutir o state_token dentro da redirect URI como query param.
        // Quando a Shopee redireciona, preserva os params existentes na redirect URI
        // e acrescenta code e shop_id — assim o state chega junto no callback.
        $partnerId  = config('services.shopee.partner_id');
        $partnerKey = config('services.shopee.partner_key');
        $baseRedirectUri = config('services.shopee.redirect_uri', 'https://api.hubai.io/shopee/oauth-callback');

        // Embutir state na redirect URI
        $redirectUri = $baseRedirectUri . (str_contains($baseRedirectUri, '?') ? '&' : '?') . 'state=' . $stateToken;

        $ts      = time();
        $path    = '/api/v2/shop/auth_partner';
        $baseStr = $partnerId . $path . $ts;
        $sign    = hash_hmac('sha256', $baseStr, $partnerKey);

        $shopeeUrl = 'https://partner.shopeemobile.com' . $path . '?' . http_build_query([
            'partner_id' => $partnerId,
            'timestamp'  => $ts,
            'sign'       => $sign,
            'redirect'   => $redirectUri,
        ]);

        return redirect()->away($shopeeUrl);
    }

    /**
     * SEL-326 Fase C: variante POST autenticada por JWT (Bearer obrigatório).
     * Front chama esta rota via AJAX depois de obter o JWT curto do próprio WL,
     * recebe JSON { shopee_url, state_token, expires_at } e faz window.location.href.
     * Elimina definitivamente o spoof do user_id via query string.
     */
    public function initPost(Request $request): \Illuminate\Http\JsonResponse
    {
        $jwtSecret = (string) config('services.shopee_init_jwt.secret', '');
        $authHeader = (string) $request->header('Authorization', '');
        if ($jwtSecret === '' || ! str_starts_with($authHeader, 'Bearer ')) {
            return response()->json(['error' => 'jwt_required'], 401);
        }
        $token = substr($authHeader, 7);
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return response()->json(['error' => 'jwt_malformed'], 401);
        }
        [$b64h, $b64p, $b64s] = $parts;
        $expectedSig = rtrim(strtr(base64_encode(hash_hmac('sha256', $b64h . '.' . $b64p, $jwtSecret, true)), '+/', '-_'), '=');
        if (! hash_equals($expectedSig, $b64s)) {
            \Log::channel('marketplace')->warning('[Shopee InitPost SEL-326] JWT com assinatura inválida', ['ip' => $request->ip()]);
            return response()->json(['error' => 'jwt_invalid_signature'], 401);
        }
        $decoded = json_decode(base64_decode(strtr($b64p, '-_', '+/')), true);
        if (! is_array($decoded) || ! isset($decoded['exp']) || $decoded['exp'] <= time()) {
            return response()->json(['error' => 'jwt_expired'], 401);
        }
        $userId  = isset($decoded['sub']) ? (string) $decoded['sub'] : '';
        $service = isset($decoded['svc']) ? (string) $decoded['svc'] : '';
        if ($userId === '' || $service === '') {
            return response()->json(['error' => 'jwt_missing_claims'], 401);
        }

        $supplierId   = $request->input('supplier_id');
        $accountName  = $request->input('account_name');
        $sourceSystem = $request->input('source_system');
        $returnUrl    = $request->input('redirect_after') ?? $request->input('return_url', '');

        $services = config('shopee_oauth_services');
        if (! isset($services[$service])) {
            return response()->json(['error' => 'invalid_service'], 422);
        }

        // Validar return_url contra dominios permitidos do service
        if ($returnUrl && ! empty($services[$service]['allowed_return_domains'])) {
            $allowed = $services[$service]['allowed_return_domains'];
            if (! in_array('*', $allowed)) {
                $host  = parse_url($returnUrl, PHP_URL_HOST);
                $valid = false;
                foreach ($allowed as $domain) {
                    if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                        $valid = true;
                        break;
                    }
                }
                if (! $valid) {
                    return response()->json(['error' => 'invalid_return_url'], 422);
                }
            }
        }
        if (empty($returnUrl)) {
            $returnUrl = 'https://hubai.io' . ($services[$service]['success_path'] ?? '/painel-cliente/integracoes');
        }

        $stateToken = bin2hex(random_bytes(32));
        $extra = [];
        if ($sourceSystem) {
            $extra['source_system'] = $sourceSystem;
        }

        DB::table('shopee_oauth_states')->insert([
            'state_token'   => $stateToken,
            'service'       => $service,
            'user_id'       => $userId,
            'return_url'    => $returnUrl,
            'supplier_id'   => $supplierId ?: null,
            'account_name'  => $accountName ?: null,
            'source_system' => $sourceSystem ?: null,
            'extra'         => ! empty($extra) ? json_encode($extra) : null,
            'expires_at'    => now()->addMinutes(10),
            'ip'            => $request->ip(),
            'user_agent'    => substr($request->userAgent() ?? '', 0, 512),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $partnerId  = config('services.shopee.partner_id');
        $partnerKey = config('services.shopee.partner_key');
        $baseRedirectUri = config('services.shopee.redirect_uri', 'https://api.hubai.io/shopee/oauth-callback');
        $redirectUri = $baseRedirectUri . (str_contains($baseRedirectUri, '?') ? '&' : '?') . 'state=' . $stateToken;

        $ts      = time();
        $path    = '/api/v2/shop/auth_partner';
        $sign    = hash_hmac('sha256', $partnerId . $path . $ts, $partnerKey);
        $shopeeUrl = 'https://partner.shopeemobile.com' . $path . '?' . http_build_query([
            'partner_id' => $partnerId,
            'timestamp'  => $ts,
            'sign'       => $sign,
            'redirect'   => $redirectUri,
        ]);

        return response()->json([
            'shopee_url'  => $shopeeUrl,
            'state_token' => $stateToken,
            'expires_at'  => now()->addMinutes(10)->toIso8601String(),
        ]);
    }
}
