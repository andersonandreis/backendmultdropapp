<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Services\Integrations\Erps\Bling\BlingApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MUL-096 — Controller para endpoints de informacao da conta Bling do lojista.
 * GET /api/v1/integrations/bling/{id}/plan → plano atual, vencimento, limite pedidos.
 */
class IntegrationBlingController extends Controller
{
    public function __construct(protected BlingApiClient $blingClient) {}

    /**
     * MUL-096: retorna dados do plano Bling da conta do lojista.
     *
     * Resposta:
     *   plan_name  string|null  Nome do plano Bling (ex: "Profissional")
     *   expires_at string|null  Data de expiracao ("YYYY-MM-DD")
     *   store_id   int|null     ID da loja Bling
     *   store_name string|null  Nome da loja Bling
     *   user_name  string|null  Nome do usuario Bling
     *   fetched_at string       ISO8601 da ultima busca (cache 6h)
     *
     * Frontend TODO: BlingHealthPanel.tsx card "Plano Atual: X | Vence em Y"
     */
    public function plan(Request $request, int $id): JsonResponse
    {
        $account = MarketplaceAccount::where('id', $id)
            ->where('platform', 'bling')
            ->where('client_id', $request->user()->client?->id)
            ->first();

        if (! $account) {
            return response()->json(['error' => 'Conta Bling nao encontrada'], 404);
        }

        if ($account->status !== 'active') {
            return response()->json([
                'error'  => 'Conta Bling nao esta ativa. Reconecte a integracao.',
                'status' => $account->status,
            ], 422);
        }

        $plan = $this->blingClient->getAccountPlan($account);

        return response()->json([
            'account_id'   => $account->id,
            'account_name' => $account->account_name,
            'plan'         => $plan,
        ]);
    }
}
