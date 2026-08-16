<?php

namespace App\Http\Controllers\Internal;

use App\Models\Client;
use App\Models\ClientProduct;
use App\Models\MarketplaceAccount;
use App\Models\ProductListingJob;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * NOV-072 - Robo de Cadastro v2
 *
 * Endpoints internos para controle da fila product_listing_jobs.
 * Autenticacao: X-Internal-Key (InternalKeyMiddleware).
 *
 * GET  /api/internal/listing-queue-stats
 * POST /api/internal/listing-queue/enqueue
 * POST /api/internal/listing-queue/pause/{client_id}
 * POST /api/internal/listing-queue/resume/{client_id}
 * POST /api/internal/listing-queue/clear-failed/{client_id}
 */
class ListingQueueController extends Controller
{
    /**
     * GET /api/internal/listing-queue-stats
     *
     * Retorna agregados por cliente: pending, processing, done, failed, skipped, total.
     * Usado pelo Centro de Comando (NOV-071) e pelo painel Filament.
     */
    public function stats(Request $request): JsonResponse
    {
        $clientId = $request->query('client_id');

        $query = DB::table('product_listing_jobs')
            ->select(
                'client_id',
                DB::raw('SUM(status = "pending")    AS pending'),
                DB::raw('SUM(status = "processing") AS processing'),
                DB::raw('SUM(status = "done")       AS done'),
                DB::raw('SUM(status = "failed")     AS failed'),
                DB::raw('SUM(status = "skipped")    AS skipped'),
                DB::raw('COUNT(*)                   AS total')
            )
            ->groupBy('client_id');

        if ($clientId) {
            $query->where('client_id', $clientId);
        }

        $rows = $query->get();

        return response()->json([
            'data'   => $rows,
            'global' => [
                'pending'    => $rows->sum('pending'),
                'processing' => $rows->sum('processing'),
                'done'       => $rows->sum('done'),
                'failed'     => $rows->sum('failed'),
                'skipped'    => $rows->sum('skipped'),
                'total'      => $rows->sum('total'),
            ],
        ]);
    }

    /**
     * POST /api/internal/listing-queue/enqueue
     *
     * Body JSON:
     * {
     *   "client_id": 42,
     *   "marketplace_accounts": [1, 2],
     *   "speed": "normal",
     *   "generate_image": 0,
     *   "limit": 100
     * }
     */
    public function enqueue(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id'              => 'required|integer|exists:clients,id',
            'marketplace_accounts'   => 'nullable|array',
            'marketplace_accounts.*' => 'integer|exists:marketplace_accounts,id',
            'speed'                  => ['nullable', Rule::in(['slow', 'normal', 'fast'])],
            'generate_image'         => 'nullable|boolean',
            'limit'                  => 'nullable|integer|min:1|max:1000',
        ]);

        $clientId      = $validated['client_id'];
        $accountIds    = $validated['marketplace_accounts'] ?? null;
        $speed         = $validated['speed'] ?? 'normal';
        $generateImage = (int) ($validated['generate_image'] ?? 0);
        $limit         = (int) ($validated['limit'] ?? 100);

        $accountQuery = MarketplaceAccount::where('client_id', $clientId)
            ->whereIn('status', ['active', 'connected']);

        if ($accountIds) {
            $accountQuery->whereIn('id', $accountIds);
        }

        $accounts = $accountQuery->get();

        if ($accounts->isEmpty()) {
            return response()->json(['message' => 'Nenhuma conta de marketplace elegivel encontrada.'], 422);
        }

        $totalEnqueued = 0;

        foreach ($accounts as $account) {
            $alreadyQueued = ProductListingJob::where('marketplace_account_id', $account->id)
                ->whereIn('status', ['pending', 'processing', 'done'])
                ->pluck('client_product_id')
                ->toArray();

            $eligible = ClientProduct::where('marketplace_account_id', $account->id)
                ->whereNull('external_listing_id')
                ->whereIn('sync_status', ['draft', 'ready', 'pending'])
                ->where('is_active', true)
                ->whereNotIn('id', $alreadyQueued)
                ->limit($limit)
                ->get();

            if ($eligible->isEmpty()) {
                continue;
            }

            $now  = now();
            $rows = $eligible->map(fn ($cp) => [
                'client_id'              => $clientId,
                'marketplace_account_id' => $account->id,
                'client_product_id'      => $cp->id,
                'status'                 => 'pending',
                'attempt'                => 0,
                'generate_image'         => $generateImage,
                'speed'                  => $speed,
                'created_at'             => $now,
                'updated_at'             => $now,
            ])->toArray();

            DB::table('product_listing_jobs')->insert($rows);
            $totalEnqueued += count($rows);
        }

        return response()->json([
            'message'  => 'Enfileiramento concluido.',
            'enqueued' => $totalEnqueued,
        ]);
    }

    /**
     * POST /api/internal/listing-queue/pause/{client_id}
     *
     * Marca todos os jobs pending do cliente como skipped.
     */
    public function pause(int $clientId): JsonResponse
    {
        $updated = ProductListingJob::where('client_id', $clientId)
            ->where('status', 'pending')
            ->update([
                'status'        => 'skipped',
                'error_message' => 'Pausado manualmente.',
                'updated_at'    => now(),
            ]);

        return response()->json(['message' => 'Fila pausada.', 'paused' => $updated]);
    }

    /**
     * POST /api/internal/listing-queue/resume/{client_id}
     *
     * Reativa jobs skipped (pausados manualmente) de volta para pending.
     */
    public function resume(int $clientId): JsonResponse
    {
        $updated = ProductListingJob::where('client_id', $clientId)
            ->where('status', 'skipped')
            ->where('error_message', 'Pausado manualmente.')
            ->update([
                'status'        => 'pending',
                'error_message' => null,
                'updated_at'    => now(),
            ]);

        return response()->json(['message' => 'Fila retomada.', 'resumed' => $updated]);
    }

    /**
     * POST /api/internal/listing-queue/clear-failed/{client_id}
     *
     * Remove registros com status=failed do cliente.
     */
    public function clearFailed(int $clientId): JsonResponse
    {
        $deleted = ProductListingJob::where('client_id', $clientId)
            ->where('status', 'failed')
            ->delete();

        return response()->json(['message' => 'Jobs com falha removidos.', 'deleted' => $deleted]);
    }
}
