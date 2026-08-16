<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Subscription\PlanUpgradeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * SEL-UPGRADE (09/08, agente fullstack-dev): endpoints do botao "Fazer
 * upgrade" — cliente pagante troca pra um plano superior pagando so a
 * diferenca, sem preencher formulario (reusa nome/email/documento/customer_id
 * ja salvos).
 *
 * ATRAS DE FEATURE FLAG: PLAN_UPGRADE_ENABLED no .env. Comeca DESLIGADA
 * (config default false) — so liga depois que a sessao principal validar o
 * fluxo. Ver relatorio SEL-UPGRADE pro que falta antes de ligar.
 *
 * GET  /api/v1/plan-upgrade/options  -> plano atual + opcoes de upgrade com
 *                                        a diferenca ja calculada (pra
 *                                        montar o botao "Upgrade por +R$X")
 * POST /api/v1/plan-upgrade/checkout -> { target_plan_slug, payment_method,
 *                                         [card_*] } gera a cobranca da
 *                                        diferenca (PIX ou cartao) e devolve
 *                                        QR/status. O plano so SOBE quando o
 *                                        webhook confirmar o pagamento.
 */
class PlanUpgradeController extends Controller
{
    public function __construct(private PlanUpgradeService $service)
    {
    }

    private function featureEnabled(): bool
    {
        return filter_var(config('services.plan_upgrade.enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    private function clientOrFail(Request $request)
    {
        $client = $request->user()->client;
        if (!$client) {
            abort(403, 'Usuario nao possui perfil de lojista.');
        }
        return $client;
    }

    public function options(Request $request): JsonResponse
    {
        if (!$this->featureEnabled()) {
            return response()->json(['error' => 'Feature indisponivel no momento.'], 503);
        }

        $client = $this->clientOrFail($request);
        $data   = $this->service->optionsFor($client);

        return response()->json(['data' => $data]);
    }

    public function checkout(Request $request): JsonResponse
    {
        if (!$this->featureEnabled()) {
            return response()->json(['error' => 'Feature indisponivel no momento.'], 503);
        }

        $validated = $request->validate([
            'target_plan_slug' => 'required|string|exists:plans,slug',
            'payment_method'   => 'required|in:pix,credit_card',
            'card_number'      => 'required_if:payment_method,credit_card|nullable|string',
            'card_holder_name' => 'required_if:payment_method,credit_card|nullable|string',
            'card_exp_month'   => 'required_if:payment_method,credit_card|nullable|string',
            'card_exp_year'    => 'required_if:payment_method,credit_card|nullable|string',
            'card_cvv'         => 'required_if:payment_method,credit_card|nullable|string',
        ]);

        $client = $this->clientOrFail($request);
        $sub    = $this->service->currentSubscription($client);

        if (!$sub || !$sub->plan) {
            return response()->json(['error' => 'Voce nao tem uma assinatura ativa pra fazer upgrade.'], 422);
        }

        $toPlan = \App\Models\Plan::where('slug', $validated['target_plan_slug'])->where('is_active', true)->first();
        if (!$toPlan) {
            return response()->json(['error' => 'Plano alvo invalido.'], 422);
        }

        if (!$this->service->isPairAllowed($sub->plan->slug, $toPlan->slug)) {
            return response()->json(['error' => 'Upgrade nao disponivel a partir do seu plano atual.'], 422);
        }

        try {
            $result = $this->service->createUpgradeCharge(
                $client,
                $sub,
                $toPlan,
                $validated['payment_method'],
                $validated
            );
        } catch (\RuntimeException $e) {
            $map = [
                'missing_document'          => ['Precisamos do seu CPF/CNPJ pra gerar a cobranca. Atualize seu cadastro.', 422],
                'gateway_customer_failed'   => ['Erro ao preparar seu cadastro no gateway de pagamento.', 502],
                'pair_not_allowed'          => ['Upgrade nao disponivel a partir do seu plano atual.', 422],
                'target_not_more_expensive' => ['O plano escolhido nao e um upgrade.', 422],
                'missing_card_data'         => ['Dados do cartao incompletos.', 422],
                'gateway_card_failed'       => ['Erro ao processar o cartao.', 502],
                'card_declined'             => ['Cartao recusado. Confira os dados ou tente outro cartao.', 422],
                'gateway_pix_failed'        => ['Erro ao gerar o PIX. Tente novamente.', 502],
                'no_current_plan'           => ['Voce nao tem uma assinatura ativa pra fazer upgrade.', 422],
            ];
            [$msg, $status] = $map[$e->getMessage()] ?? ['Erro ao processar upgrade.', 500];
            if (!isset($map[$e->getMessage()])) {
                Log::error('[PlanUpgrade] Erro inesperado no checkout', ['error' => $e->getMessage()]);
            }
            return response()->json(['error' => $msg], $status);
        }

        $charge = $result['charge'];

        $response = [
            'order_id' => $charge->gateway_order_id,
            'status'   => $charge->status,
            'amount'   => round($charge->diff_amount_cents / 100, 2),
        ];

        if ($charge->payment_method === 'pix') {
            $response['pix_qr_code']     = $result['pix_qr_code'];
            $response['pix_qr_code_url'] = $result['pix_qr_code_url'];
        }

        return response()->json($response, $charge->status === 'paid' ? 201 : 200);
    }
}
