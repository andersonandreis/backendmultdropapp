<?php

namespace App\Http\Middleware;

use App\Models\SupplierApiToken;
use Closure;
use Illuminate\Http\Request;

/** NOV-137 — Autenticação por API token pessoal do supplier. */
class SupplierApiTokenMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $bearer = $request->bearerToken();
        if (!$bearer || !str_starts_with($bearer, 'hub_sk_')) {
            return response()->json(['error' => 'missing_or_invalid_token'], 401);
        }

        $token = SupplierApiToken::findByPlain($bearer);
        if (!$token) {
            return response()->json(['error' => 'token_invalid_or_expired'], 401);
        }

        // Disponibiliza supplier_id pra controller via request attributes
        $request->attributes->set('supplier_id', $token->supplier_id);
        $request->attributes->set('api_token_id', $token->id);
        $request->attributes->set('api_token_abilities', $token->abilities ?? ['*']);

        return $next($request);
    }
}
