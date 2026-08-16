<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SEL-326 — emite JWT curto (300s) pra autenticar init OAuth Shopee no hub central.
 *
 * Fluxo: front autenticado (Sanctum) chama POST /api/auth/oauth-init-token → recebe JWT.
 * Passa o JWT como Authorization: Bearer no GET api.hubai.io/api/shopee/oauth/init.
 * Hub valida com o mesmo secret compartilhado (SHOPEE_INIT_JWT_SECRET no .env) e
 * extrai user_id do claim "sub" em vez de aceitar user_id cru do query.
 *
 * Fecha vulnerabilidade onde qualquer request GET /init?user_id=X iniciava OAuth
 * em nome de outro cliente (bug estrutural pré-SEL-326, sem middleware auth).
 */
class OAuthInitTokenController extends Controller
{
    public function issue(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }
        // MUL-314: a emissao vive em ShopeeInitTokenService -- o backend do WL tambem
        // precisa emitir (OAuthController::redirect), e duas copias da mesma regra ja
        // custaram caro neste sistema. O service tambem resolve o claim "sub" por
        // service, que nao significa a mesma coisa no hubai e nos WLs.
        $token = \App\Services\Shopee\ShopeeInitTokenService::emitir((int) $user->id);
        if (! $token) {
            return response()->json(['error' => 'not_configured'], 500);
        }

        return response()->json([
            'token'      => $token,
            'expires_in' => 300,
            'svc'        => \App\Services\Shopee\ShopeeInitTokenService::service(),
        ]);
    }

    /** Mantido so como referencia do formato antigo; nao e mais chamado. */
    private function issueLegado(Request $request): JsonResponse
    {
        $user = $request->user();
        $secret = (string) config('services.shopee_init_jwt.secret', '');
        if ($secret === '') {
            return response()->json(['error' => 'not_configured'], 500);
        }

        // Mapping tenant -> nome do service no config/shopee_oauth_services do hub central.
        // APP_TENANT nos .env dos WLs usa slug interno (sem hifen); config Shopee usa nome canonico.
        $SVC_MAP = [
            'sellerglobal' => 'seller-global',
            'hubai'        => 'hubai',
            'fornecefy'    => 'fornecefy',
            'multdrop'     => 'multdrop',
            'mestoredrop'  => 'mestoredrop',
            'jtdrop'       => 'jtdrop',
            'dropksr'      => 'dropksr',
        ];
        $tenant = (string) config('federation.tenant', 'hubai');
        $svc = $SVC_MAP[$tenant] ?? $tenant;

        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $payload = [
            'sub' => (string) $user->id,
            'svc' => $svc,
            'iat' => time(),
            'exp' => time() + 300,
            'iss' => $svc,
            'jti' => bin2hex(random_bytes(16)),
        ];

        $b64 = fn($x) => rtrim(strtr(base64_encode(json_encode($x)), '+/', '-_'), '=');
        $signInput = $b64($header) . '.' . $b64($payload);
        $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', $signInput, $secret, true)), '+/', '-_'), '=');

        return response()->json([
            'token'      => $signInput . '.' . $sig,
            'expires_in' => 300,
            'svc'        => $svc,
        ]);
    }
}
