<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * SEL-360 Fase 2 — SanctumFromQuery
 *
 * EventSource (SSE) nao aceita headers customizados.
 * Este middleware aceita o token via query string ?token=xxx como fallback.
 * Aplica APENAS na rota de progress SSE — nao em rotas gerais.
 *
 * Fluxo:
 *  1. Se a request ja tem Bearer token (header), nao faz nada.
 *  2. Se tem ?token=xxx, injeta Authorization: Bearer {token} no header.
 *  3. Deixa auth:sanctum resolver normalmente.
 */
class SanctumFromQuery
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->bearerToken() && $token = $request->query('token')) {
            $request->headers->set('Authorization', 'Bearer ' . $token);
        }

        return $next($request);
    }
}
