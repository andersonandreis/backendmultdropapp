<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Supplier Core / Fase 3 / M4 — Idempotency-Key middleware.
 *
 * Em POST/PATCH/DELETE no Tenant API, exige header Idempotency-Key.
 * Se a chave ja foi vista, devolve a resposta cacheada (sem reexecutar a acao).
 * Cache TTL 7 dias.
 *
 * Tem que rodar DEPOIS do TenantApiAuth (precisa do tenant_id no request).
 */
class TenantApiIdempotency
{
    public function handle(Request $request, Closure $next)
    {
        if (!in_array($request->method(), ['POST', 'PATCH', 'PUT', 'DELETE'], true)) {
            return $next($request);
        }

        $key = $request->header('Idempotency-Key');
        if (!$key) {
            return response()->json(['error' => 'missing_idempotency_key'], 400);
        }
        if (strlen($key) > 128) {
            return response()->json(['error' => 'idempotency_key_too_long'], 400);
        }

        $tenantId = $request->attributes->get('tenant_id');
        if (!$tenantId) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        // Limpar expirados eventualmente (fire-and-forget cheap)
        if (rand(1, 100) === 1) {
            DB::table('idempotency_keys')->where('expires_at', '<', now())->delete();
        }

        $cached = DB::table('idempotency_keys')
            ->where('tenant_id', $tenantId)
            ->where('key', $key)
            ->where('expires_at', '>', now())
            ->first();

        if ($cached) {
            return response($cached->response_body, $cached->response_status)
                ->header('Content-Type', 'application/json')
                ->header('Idempotency-Replayed', 'true');
        }

        /** @var \Illuminate\Http\Response $response */
        $response = $next($request);

        // Salvar resposta no cache (apenas 2xx — erros nao bloqueiam reenvio)
        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            try {
                DB::table('idempotency_keys')->insert([
                    'tenant_id'        => $tenantId,
                    'key'              => $key,
                    'endpoint'         => $request->method() . ' ' . $request->path(),
                    'response_status'  => $response->getStatusCode(),
                    'response_body'    => (string) $response->getContent(),
                    'expires_at'       => Carbon::now()->addDays(7),
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
            } catch (\Throwable $e) {
                // unique constraint = race condition: outro pedido com a mesma key chegou primeiro. ignora.
            }
        }

        return $response;
    }
}
