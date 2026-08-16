<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware de autenticacao para o endpoint /api/v1/client/status (NOV-032).
 * Usa Authorization: Bearer {TOKEN} onde TOKEN = GABRIEL_API_KEY no .env.
 *
 * INF-067: env() direto aqui retorna vazio com config cacheada (Laravel nao
 * relê o .env quando bootstrap/cache/config.php existe — so config() funciona).
 * GABRIEL_API_KEY estava setado no .env mas nunca foi lido em producao desde
 * que o config:cache passou a rodar, gerando "Service misconfigured" (500)
 * pra qualquer chamada, mesmo com a chave certa no .env. Fix: ler via
 * config('services.gabriel.api_key'), que forca leitura em config/services.php
 * (mesmo padrao do MUL-212/SEL-183 documentados em config/services.php).
 */
class GabrielApiKeyMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config("services.gabriel.api_key", "");

        if (empty($secret)) {
            Log::channel("gabriel")->error("gabriel.api_key_missing", [
                "ip"   => $request->ip(),
                "path" => $request->path(),
            ]);
            return response()->json(["error" => "Service misconfigured"], 500);
        }

        $bearer = $request->bearerToken();

        if (empty($bearer) || ! hash_equals($secret, $bearer)) {
            Log::channel("gabriel")->warning("gabriel.api_key_auth_fail", [
                "ip"   => $request->ip(),
                "path" => $request->path(),
            ]);
            return response()->json(["error" => "Unauthorized"], 401);
        }

        return $next($request);
    }
}
