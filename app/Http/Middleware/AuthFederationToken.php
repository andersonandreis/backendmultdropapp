<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * NOV-171-B — Middleware de autenticação da Federation API.
 *
 * Valida o Bearer token enviado pelos WLs contra config('federation.tokens').
 * Injeta $request->federation_tenant (slug do WL) para os controllers usarem.
 *
 * Regra 9 do 00-INDEX: NUNCA ler o token cru — usar config() para comparar.
 */
class AuthFederationToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if (! $bearer) {
            return response()->json(['message' => 'Federation token ausente.'], 401);
        }

        $tokens = config('federation.tokens', []);
        $tenant = null;

        foreach ($tokens as $slug => $token) {
            if ($token && hash_equals($token, $bearer)) {
                $tenant = $slug;
                break;
            }
        }

        if (! $tenant) {
            return response()->json(['message' => 'Token de federação inválido.'], 401);
        }

        // Injeta o tenant identificado para uso nos controllers
        $request->attributes->set('federation_tenant', $tenant);

        return $next($request);
    }
}
