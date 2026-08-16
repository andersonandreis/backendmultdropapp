<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * NOV-061: Middleware de autenticacao para endpoints internos consumidos
 * pelo sistema legado (goolhub.io). Valida header X-Internal-Key contra
 * INTERNAL_BRIDGE_KEY no .env. Sem dependencia de sessao/Sanctum.
 */
class InternalKeyMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $expected = config('app.internal_bridge_key');
        $received = $request->header('X-Internal-Key');

        if (!$expected) {
            return response()->json([
                'error' => 'misconfigured',
                'message' => 'INTERNAL_BRIDGE_KEY nao definido no servidor',
            ], 500);
        }

        if (!$received || !hash_equals($expected, $received)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return $next($request);
    }
}
