<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SyncClientProductStockJob;
use App\Models\ClientProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\RateLimiter;

/**
 * MUL-118 -- Sincronizacao de estoque de produtos do lojista.
 */
class ClientProductSyncController extends Controller
{
    /**
     * POST /api/v1/client-products/{id}/sync-stock
     *
     * Dispara pull de estoque de UM produto especifico do lojista.
     * Retorna 202 Accepted + job_id.
     */
    public function syncStoch(Request $request, int $id): JsonResponse
    {
        $client = $request->user()->client;
        if (! $client) {
            return response()->json(["error" => "Usuario nao possui perfil de lojista."], 403);
        }

        $clientProduct = ClientProduct::where("id", $id)
            ->where("client_id", $client->id)
            ->first();

        if (! $clientProduct) {
            return response()->json(["error" => "Produto nao encontrado."], 404);
        }

        // Rate limit: max 30 dispatches/min por client para nao estourar API do marketplace
        $key = "sync-stock:" . $client->id;
        if (RateLimiter::tooManyAttempts($key, 30)) {
            $seconds = RateLimiter::availableIn($key);
            return response()->json([
                "error"       => "Muitas solicitacoes. Tente novamente em {}s.",
                "retry_after" => $seconds,
            ], 429);
        }
        RateLimiter::hit($key, 60);

        $job = new SyncClientProductStockJob($clientProduct->id);
        $jobId = Bus::dispatch($job);

        return response()->json([
            "status"  => "queued",
            "message" => "Sincronizacao de estoque enfileirada.",
            "job_id"  => (string) $jobId,
        ], 202);
    }

    /**
     * POST /api/v1/client-products/sync-stock-all
     *
     * Dispara pull de estoque para TODOS os produtos ativos do lojista.
     * Max 30/min por throttle (evita estourar rate limit do marketplace).
     */
    public function syncStockAll(Request $request): JsonResponse
    {
        $client = $request->user()->client;
        if (! $client) {
            return response()->json(["error" => "Usuario nao possui perfil de lojista."], 403);
        }

        $key = "sync-stock-all:" . $client->id;
        if (RateLimiter::tooManyAttempts($key, 1)) {
            $seconds = RateLimiter::availableIn($key);
            return response()->json([
                "error"       => "Sincronizacao em massa ja em andamento. Aguarde {}s.",
                "retry_after" => $seconds,
            ], 429);
        }
        RateLimiter::hit($key, 120); // bloqueia por 2min

        $products = ClientProduct::where("client_id", $client->id)
            ->where("is_active", true)
            ->whereNotNull("marketplace_account_id")
            ->get(["id"]);

        $total     = $products->count();
        $dispatched = 0;
        $rateLimitKey = "sync-stock:" . $client->id;

        foreach ($products as $cp) {
            if (RateLimiter::tooManyAttempts($rateLimitKey, 30)) {
                // Para o batch aqui -- os demais sao ignorados neste ciclo
                break;
            }
            RateLimiter::hit($rateLimitKey, 60);
            dispatch(new SyncClientProductStockJob($cp->id));
            $dispatched++;
        }

        return response()->json([
            "status"     => "queued",
            "message"    => "Sincronizacao enfileirada para {} de {} produtos.",
            "total"      => $total,
            "dispatched" => $dispatched,
        ], 202);
    }
}
