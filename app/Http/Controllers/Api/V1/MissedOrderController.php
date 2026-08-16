<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ReconcileMarketplaceOrdersJob;
use App\Models\MissedOrderAlert;
use App\Services\Marketplace\Reconciliation\MissedOrderDetectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Endpoints de gerenciamento de alertas de pedidos perdidos.
 *
 * Todos os endpoints requerem auth:sanctum (definido no grupo de rotas).
 * Tenant isolation garantido: filtragem sempre por client_id do usuario autenticado.
 *
 * Throttle por plano no endpoint refresh:
 *  - Start  : bloqueado (403)
 *  - Pro    : 3 chamadas/dia via cache key
 *  - Scale  : sem limite
 */
class MissedOrderController extends Controller
{
    /**
     * GET /api/v1/missed-orders
     *
     * Lista alertas pending do cliente autenticado.
     */
    public function index(Request $request): JsonResponse
    {
        $client = $request->user();

        $alerts = MissedOrderAlert::forClient($client->id)
            ->pending()
            ->latest('detected_at')
            ->get();

        return response()->json([
            'data'  => $alerts,
            'total' => $alerts->count(),
        ]);
    }

    /**
     * POST /api/v1/missed-orders/refresh
     *
     * Dispara reconciliacao sincrona para o cliente autenticado.
     * Throttle por plano; retorna lista atualizada de alertas pending.
     */
    public function refresh(Request $request, MissedOrderDetectionService $service): JsonResponse
    {
        $client = $request->user();

        // Plano Start: recurso bloqueado
        if ($client->isStartPlan()) {
            return response()->json([
                'message' => 'Disponível no plano Pro',
            ], 403);
        }

        // Plano Pro: throttle 3 chamadas/dia via cache
        $isPro = ! $client->isStartPlan()
            && (optional($client->subscriptions()->whereIn('status', ['active', 'trialing'])->with('plan')->first())->plan?->max_skus <= 300);

        if ($isPro) {
            $cacheKey = sprintf('missed-refresh-%d-%s', $client->id, now()->toDateString());
            $count    = (int) Cache::get($cacheKey, 0);

            if ($count >= 3) {
                return response()->json([
                    'message' => 'Limite de 3 atualizações por dia atingido. Disponível novamente amanhã.',
                ], 429);
            }

            Cache::put($cacheKey, $count + 1, now()->endOfDay());
        }

        // Executar reconciliacao sincronamente (sem dispatch para fila)
        $job = new ReconcileMarketplaceOrdersJob($client->id);
        $job->handle($service);

        // Retornar alertas pending atualizados
        $alerts = MissedOrderAlert::forClient($client->id)
            ->pending()
            ->latest('detected_at')
            ->get();

        return response()->json([
            'message' => 'Reconciliação concluída.',
            'data'    => $alerts,
            'total'   => $alerts->count(),
        ]);
    }

    /**
     * POST /api/v1/missed-orders/{id}/dismiss
     *
     * Descarta um alerta com motivo informado.
     * Body: { reason: 'not_mine'|'already_registered'|'cancelled_at_marketplace'|'other' }
     */
    public function dismiss(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'reason' => ['required', 'string', 'in:not_mine,already_registered,cancelled_at_marketplace,other'],
        ]);

        $client = $request->user();

        // Tenant isolation: garantir que o alerta pertence ao cliente autenticado
        $alert = MissedOrderAlert::forClient($client->id)->where('id', $id)->first();

        if (! $alert) {
            return response()->json(['message' => 'Alerta não encontrado.'], 404);
        }

        if ($alert->dismissed_at !== null) {
            return response()->json(['message' => 'Alerta já descartado.'], 422);
        }

        $alert->update([
            'dismissed_at'   => now(),
            'dismiss_reason' => $request->input('reason'),
        ]);

        return response()->json([
            'message' => 'Alerta descartado.',
            'data'    => $alert->fresh(),
        ]);
    }
}
