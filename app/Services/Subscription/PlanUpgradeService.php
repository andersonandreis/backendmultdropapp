<?php

namespace App\Services\Subscription;

use App\Models\Client;
use App\Models\Plan;
use App\Models\PlanUpgradeCharge;
use App\Models\Subscription;
use App\Services\AppLoggerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SEL-UPGRADE (09/08, agente fullstack-dev, a pedido do Ruan):
 *
 * "Botao de upgrade cobrando so a DIFERENCA" — cliente que ja pagou um plano
 * (ex video_pro R$149) faz upgrade pro plano de cima (ex video_ultra R$297)
 * pagando so R$148 (a diferenca), sem preencher NENHUM dado de novo (reusa
 * nome/email/documento/customer_id do Pagar.me que ja existem).
 *
 * REGRAS DURAS deste service:
 *   1. Pares de upgrade sao uma ALLOWLIST explicita (self::PAIRS), nunca
 *      "qualquer plano mais caro". Pedido literal do Ruan: "pre-defina os
 *      pares de upgrade". Evita cobranca esquisita tipo tiktok_free->drop_top.
 *   2. Diferenca e sempre calculada AO VIVO a partir de `plans.price_monthly`
 *      no banco (nunca hardcoded) — se o preco mudar, a diferenca acompanha.
 *   3. A cobranca da diferenca NUNCA reusa o `metadata.plan_slug` /
 *      fallback-por-valor que o PagarmeWebhookController::onChargePaid ja usa
 *      pro checkout normal — isso e MUITO perigoso aqui: a diferenca de
 *      R$148 (video_pro->video_ultra) cai dentro da faixa de tolerancia
 *      (+-10) do preco do proprio video_pro (R$149) e o webhook generico
 *      IRIA ATIVAR O PLANO ERRADO (o que o cliente ja tem, nao o alvo).
 *      Por isso: metadata.type = 'plan_upgrade' e um branch NOVO e isolado
 *      no webhook, checado ANTES do fluxo generico.
 *   4. So opera em cima da Subscription/Plan ja existentes (sistema que esta
 *      REALMENTE em producao: video_start/video_pro/video_ultra + start/
 *      scaling/pro). NAO mexe no UserVideoSubscription/VideoSubscriptionPlan
 *      (SEL-417) porque esse sistema ainda nao esta ligado no frontend
 *      (confirmado: grep em src/ nao acha nenhum uso de /v1/video-plans).
 */
class PlanUpgradeService
{
    private const CARD_MARKUP_PERCENT = 40; // mesmo markup anti-chargeback do CheckoutController

    /**
     * Pares de upgrade permitidos: from_slug => [to_slug, to_slug, ...]
     * Cada par deve ter to.price_monthly > from.price_monthly (validado em
     * runtime, nao so aqui) — a lista aqui e sobre QUAIS combinacoes fazem
     * sentido de produto, nao sobre o valor.
     */
    private const PAIRS = [
        'video_start' => ['video_pro', 'video_ultra'],
        'video_pro'   => ['video_ultra'],
        'start'       => ['scaling', 'pro'],
        'scaling'     => ['pro'],
    ];

    private string $apiKey;
    private string $baseUrl = 'https://api.pagar.me/core/v5';

    public function __construct()
    {
        $this->apiKey = config('payment.pagarme.api_key', '');
    }

    private function headers(): array
    {
        return [
            'Authorization' => 'Basic ' . base64_encode($this->apiKey . ':'),
            'Content-Type'  => 'application/json',
        ];
    }

    /**
     * Plano atual (assinatura ativa mais recente) de um Client.
     */
    public function currentSubscription(Client $client): ?Subscription
    {
        return $client->subscriptions()
            ->whereIn('status', ['active', 'trialing'])
            ->with('plan')
            ->latest()
            ->first();
    }

    /**
     * Lista de opcoes de upgrade disponiveis pro plano atual do cliente,
     * com a diferenca ja calculada. Usado pro botao mostrar "pague so +R$X".
     *
     * @return array{current_plan: ?array, options: array}
     */
    public function optionsFor(Client $client): array
    {
        $sub = $this->currentSubscription($client);
        if (!$sub || !$sub->plan) {
            return ['current_plan' => null, 'options' => []];
        }

        $fromSlug = $sub->plan->slug;
        $targetSlugs = self::PAIRS[$fromSlug] ?? [];
        if (empty($targetSlugs)) {
            return ['current_plan' => $this->formatPlan($sub->plan), 'options' => []];
        }

        $hasPaymentData = $this->clientHasPaymentData($client, $sub);

        $options = [];
        foreach ($targetSlugs as $slug) {
            $target = Plan::where('slug', $slug)->where('is_active', true)->first();
            if (!$target) {
                continue;
            }
            $diffCents = $this->diffCentsFor($sub->plan, $target);
            if ($diffCents === null) {
                continue; // preco do alvo nao e maior — nao oferece "upgrade" que na verdade e downgrade/igual
            }
            $options[] = [
                'plan'               => $this->formatPlan($target),
                'diff_amount'        => round($diffCents / 100, 2),
                'diff_amount_credit_card' => round(($diffCents * (1 + self::CARD_MARKUP_PERCENT / 100)) / 100, 2),
                'requires_document'  => !$hasPaymentData,
            ];
        }

        return ['current_plan' => $this->formatPlan($sub->plan), 'options' => $options];
    }

    private function formatPlan(Plan $p): array
    {
        return [
            'id'    => $p->id,
            'slug'  => $p->slug,
            'name'  => $p->name,
            'price' => (float) $p->price_monthly,
        ];
    }

    /**
     * Diferenca em centavos entre o plano atual e o alvo. Null se o alvo nao
     * for mais caro (protege contra "upgrade" que na pratica seria gratis ou
     * downgrade — nunca gera cobranca <= 0).
     */
    public function diffCentsFor(Plan $from, Plan $to): ?int
    {
        $fromCents = (int) round((float) $from->price_monthly * 100);
        $toCents   = (int) round((float) $to->price_monthly * 100);
        $diff      = $toCents - $fromCents;
        return $diff > 0 ? $diff : null;
    }

    /**
     * O par (from_slug -> to_slug) esta na allowlist?
     */
    public function isPairAllowed(string $fromSlug, string $toSlug): bool
    {
        return in_array($toSlug, self::PAIRS[$fromSlug] ?? [], true);
    }

    /**
     * O client ja tem o necessario pra cobrar sem pedir NADA de novo:
     * customer_id no Pagar.me (reusa direto) OU documento salvo (cria
     * customer novo silenciosamente). Sem isso, nao da pra cumprir "zero
     * informacao pedida" com seguranca (Pagar.me exige CPF/CNPJ valido).
     */
    public function clientHasPaymentData(Client $client, Subscription $sub): bool
    {
        return !empty($sub->pagarme_customer_id)
            || !empty($client->pagarme_customer_id)
            || !empty($client->document);
    }

    /**
     * Obtem (reusando) ou cria o customer no Pagar.me pro client, SEM pedir
     * nenhum dado novo — usa o que ja esta salvo.
     */
    private function getOrCreateCustomerId(Client $client, Subscription $sub): string
    {
        if (!empty($sub->pagarme_customer_id)) {
            return $sub->pagarme_customer_id;
        }
        if (!empty($client->pagarme_customer_id)) {
            return $client->pagarme_customer_id;
        }

        // Sem customer_id salvo — precisa de documento pra criar (Pagar.me exige).
        if (empty($client->document)) {
            throw new \RuntimeException('missing_document');
        }

        $docDigits = preg_replace('/\D/', '', $client->document);
        $user      = $client->user;

        $payload = [
            'name'     => $user->name ?? 'Cliente Seller Global',
            'email'    => $user->email,
            'type'     => strlen($docDigits) > 11 ? 'company' : 'individual',
            'document' => $docDigits,
        ];
        if (!empty($client->phone)) {
            $phoneDigits = preg_replace('/\D/', '', $client->phone);
            if (strlen($phoneDigits) >= 10) {
                $payload['phones'] = [
                    'mobile_phone' => [
                        'country_code' => '55',
                        'area_code'    => substr($phoneDigits, 0, 2),
                        'number'       => substr($phoneDigits, 2),
                    ],
                ];
            }
        }

        $resp = Http::withHeaders($this->headers())->post("{$this->baseUrl}/customers", $payload);
        if (!$resp->successful()) {
            Log::error('[PlanUpgrade] Falha ao criar customer Pagar.me', ['status' => $resp->status(), 'body' => $resp->body()]);
            AppLoggerService::error('payment', 'plan_upgrade.customer_failed', 'Pagar.me customer creation failed', ['http_status' => $resp->status()]);
            throw new \RuntimeException('gateway_customer_failed');
        }

        $customerId = $resp->json('id');
        // Grava pra reusar da proxima vez (nao so nesta subscription — no client tambem)
        $client->update(['pagarme_customer_id' => $customerId]);

        return $customerId;
    }

    /**
     * Cria a cobranca (PIX ou cartao) da DIFERENCA e grava o PlanUpgradeCharge
     * local (idempotencia + auditoria). NAO sobe o plano aqui — quem sobe o
     * plano e o cron `pagarme:reconcile-plan-upgrades` (ou o webhook, se um
     * dia passar a receber trafego real — ver markChargePaidAndUpgrade()),
     * so depois de confirmar o pagamento de verdade no Pagar.me.
     *
     * @param array $cardData Somente se payment_method=credit_card (numero,
     *                        nome impresso, validade, cvv — nao temos token
     *                        de cartao salvo no sistema hoje, entao cartao
     *                        NAO e 100% "zero formulario"; PIX e o unico
     *                        caminho verdadeiramente zero-form hoje).
     *
     * @throws \RuntimeException em qualquer falha (mensagem = codigo de erro)
     *
     * @return array{charge: PlanUpgradeCharge, pix_qr_code: ?string, pix_qr_code_url: ?string}
     *         Retorna array (nao so o Model) porque o QR code do PIX NAO e
     *         persistido/coluna real — e um dado transiente do gateway pra
     *         devolver na resposta HTTP. Guardar isso com $charge->setAttribute()
     *         no model quebrava o próximo `$charge->update()` (ex: no
     *         reconcile/webhook confirmando o pagamento) com SQL error
     *         "Unknown column '_pix_qr_code'" — Eloquent tenta salvar TODOS os
     *         atributos "dirty", inclusive os que nao existem na tabela. Achado
     *         pelo teste E2E, corrigido antes de qualquer ativacao.
     */
    public function createUpgradeCharge(
        Client $client,
        Subscription $sub,
        Plan $toPlan,
        string $paymentMethod,
        array $cardData = []
    ): array {
        $fromPlan = $sub->plan;
        if (!$fromPlan) {
            throw new \RuntimeException('no_current_plan');
        }
        if (!$this->isPairAllowed($fromPlan->slug, $toPlan->slug)) {
            throw new \RuntimeException('pair_not_allowed');
        }

        $diffCents = $this->diffCentsFor($fromPlan, $toPlan);
        if ($diffCents === null) {
            throw new \RuntimeException('target_not_more_expensive');
        }

        $customerId = $this->getOrCreateCustomerId($client, $sub);

        $amountCents = $paymentMethod === 'credit_card'
            ? (int) round($diffCents * (1 + self::CARD_MARKUP_PERCENT / 100))
            : $diffCents;

        // Metadata explicita e ISOLADA do fluxo generico — ver regra dura #3.
        $metadata = [
            'type'             => 'plan_upgrade',
            'client_id'        => (string) $client->id,
            'subscription_id'  => (string) $sub->id,
            'from_plan_id'     => (string) $fromPlan->id,
            'to_plan_id'       => (string) $toPlan->id,
            'from_plan_slug'   => $fromPlan->slug,
            'to_plan_slug'     => $toPlan->slug,
            'source'           => 'seller-global-plan-upgrade',
        ];

        if ($paymentMethod === 'credit_card') {
            $gatewayId = $this->chargeCreditCard($customerId, $amountCents, $toPlan, $metadata, $cardData);
            $gatewayResponse = $gatewayId['raw'];
            $orderId = $gatewayId['id'];
            $status  = $gatewayId['status'];
            $pixCode = null;
            $pixUrl  = null;
        } else {
            [$orderId, $status, $pixCode, $pixUrl, $gatewayResponse] = $this->chargePix($customerId, $amountCents, $toPlan, $metadata);
        }

        $charge = PlanUpgradeCharge::create([
            'client_id'           => $client->id,
            'subscription_id'     => $sub->id,
            'from_plan_id'        => $fromPlan->id,
            'to_plan_id'          => $toPlan->id,
            'diff_amount_cents'   => $amountCents,
            'payment_method'      => $paymentMethod,
            'gateway'             => 'pagarme',
            'gateway_customer_id' => $customerId,
            'gateway_order_id'    => $orderId,
            'status'              => $status,
            'paid_at'             => $status === 'paid' ? now() : null,
            'expires_at'          => $paymentMethod === 'pix' ? now()->addHour() : null,
            'gateway_response'    => $gatewayResponse,
        ]);

        return [
            'charge'          => $charge,
            'pix_qr_code'     => $pixCode,
            'pix_qr_code_url' => $pixUrl,
        ];
    }

    private function chargePix(string $customerId, int $amountCents, Plan $toPlan, array $metadata): array
    {
        $payload = [
            'customer_id' => $customerId,
            'items'       => [[
                'description' => "Upgrade para {$toPlan->name} - Seller Global (diferenca)",
                'amount'      => $amountCents,
                'quantity'    => 1,
            ]],
            'payments' => [[
                'payment_method' => 'pix',
                'pix'            => ['expires_in' => 3600],
            ]],
            'metadata' => $metadata,
        ];

        $resp = Http::withHeaders($this->headers())->post("{$this->baseUrl}/orders", $payload);
        if (!$resp->successful()) {
            Log::error('[PlanUpgrade] Falha ao criar order PIX', ['status' => $resp->status(), 'body' => $resp->body()]);
            AppLoggerService::error('payment', 'plan_upgrade.pix_failed', 'Pagar.me PIX order creation failed', ['http_status' => $resp->status()]);
            throw new \RuntimeException('gateway_pix_failed');
        }

        $order  = $resp->json();
        $status = ($order['status'] ?? 'pending') === 'paid' ? 'paid' : 'pending';
        $pixCode = $order['charges'][0]['last_transaction']['qr_code'] ?? null;
        $pixUrl  = $order['charges'][0]['last_transaction']['qr_code_url'] ?? null;

        return [$order['id'], $status, $pixCode, $pixUrl, $order];
    }

    private function chargeCreditCard(string $customerId, int $amountCents, Plan $toPlan, array $metadata, array $cardData): array
    {
        foreach (['card_number', 'card_holder_name', 'card_exp_month', 'card_exp_year', 'card_cvv'] as $f) {
            if (empty($cardData[$f])) {
                throw new \RuntimeException('missing_card_data');
            }
        }

        // Cobranca AVULSA (order), nao subscription recorrente — a diferenca e
        // paga uma vez so. A recorrencia mensal do plano novo e um passo
        // separado (fora de escopo desta v1 — ver relatorio, item "o que falta").
        $payload = [
            'customer_id' => $customerId,
            'items'       => [[
                'description' => "Upgrade para {$toPlan->name} - Seller Global (diferenca)",
                'amount'      => $amountCents,
                'quantity'    => 1,
            ]],
            'payments' => [[
                'payment_method' => 'credit_card',
                'credit_card'    => [
                    'installments' => 1,
                    'statement_descriptor' => 'SELLERGLOBAL',
                    'card' => [
                        'number'      => preg_replace('/\D/', '', $cardData['card_number']),
                        'holder_name' => strtoupper($cardData['card_holder_name']),
                        'exp_month'   => (int) $cardData['card_exp_month'],
                        'exp_year'    => (int) $cardData['card_exp_year'],
                        'cvv'         => $cardData['card_cvv'],
                        'billing_address' => [
                            'line_1'   => '123, Rua Principal, Centro',
                            'zip_code' => '01001000',
                            'city'     => 'Sao Paulo',
                            'state'    => 'SP',
                            'country'  => 'BR',
                        ],
                    ],
                ],
            ]],
            'metadata' => $metadata,
        ];

        $resp = Http::withHeaders($this->headers())->post("{$this->baseUrl}/orders", $payload);
        if (!$resp->successful()) {
            Log::error('[PlanUpgrade] Falha ao cobrar cartao', ['status' => $resp->status(), 'body' => $resp->body()]);
            AppLoggerService::error('payment', 'plan_upgrade.card_failed', 'Pagar.me card charge failed', ['http_status' => $resp->status()]);
            throw new \RuntimeException('gateway_card_failed');
        }

        $order  = $resp->json();
        $status = ($order['status'] ?? 'pending') === 'paid' ? 'paid' : 'failed';
        if ($status === 'failed') {
            throw new \RuntimeException('card_declined');
        }

        return ['id' => $order['id'], 'status' => $status, 'raw' => $order];
    }

    // -------------------------------------------------------------------------
    // Confirmacao de pagamento + upgrade do plano
    // -------------------------------------------------------------------------
    //
    // IMPORTANTE (achado na investigacao, 09/08): a conta Pagar.me deste
    // sistema e COMPARTILHADA com o HubAI, e o webhook configurado no painel
    // Pagar.me aponta pra edge function Supabase do HubAI — NAO pra este
    // backend Laravel. POST /api/webhooks/pagarme deste app NUNCA recebe
    // charge.paid de verdade (comprovado: zero linha "[PagarmeWebhook]" nos
    // logs recentes + existe um cron `pagarme:reconcile-pix` rodando a cada 5
    // min so PORQUE o webhook e orfao — ver
    // app/Console/Commands/ReconcilePagarmePixSubscriptions.php).
    //
    // Por isso a confirmacao do upgrade NAO pode depender so do webhook. Este
    // metodo e a fonte unica de verdade pra "confirmar pagamento + subir
    // plano", chamado de DOIS lugares:
    //   1) PagarmeWebhookController::onPlanUpgradePaid — cobre o dia em que o
    //      webhook for reconfigurado pra apontar pra ca (ou outro backend que
    //      repasse o evento).
    //   2) Console\Commands\ReconcilePlanUpgrades (novo, roda no cron a cada
    //      5min igual o pagarme:reconcile-pix) — o caminho que REALMENTE
    //      confirma em producao hoje.

    /**
     * Confirma o pagamento de um PlanUpgradeCharge e sobe o plano da
     * subscription associada. Idempotente — chamar de novo com o mesmo charge
     * ja pago e um no-op seguro.
     */
    public function markChargePaidAndUpgrade(PlanUpgradeCharge $charge, array $rawGatewayData = []): bool
    {
        if ($charge->status === 'paid') {
            return true; // idempotencia
        }

        $upgraded = false;
        DB::transaction(function () use ($charge, $rawGatewayData, &$upgraded) {
            $charge->update([
                'status'           => 'paid',
                'paid_at'          => now(),
                'gateway_response' => $rawGatewayData ?: $charge->gateway_response,
            ]);

            $sub = $charge->subscription;
            if (!$sub) {
                Log::warning('[PlanUpgrade] Subscription do charge nao existe mais', ['charge_id' => $charge->id]);
                return;
            }

            // So sobe o plano se ele ainda estiver no plano de ORIGEM esperado
            // — protege contra corrida (plano mudou entre criar a cobranca e
            // o pagamento confirmar).
            if ((int) $sub->plan_id === (int) $charge->from_plan_id) {
                $sub->update(['plan_id' => $charge->to_plan_id]);
                $upgraded = true;
                Log::info('[PlanUpgrade] Plano upgradeado', [
                    'subscription_id' => $sub->id,
                    'client_id'       => $charge->client_id,
                    'from_plan_id'    => $charge->from_plan_id,
                    'to_plan_id'      => $charge->to_plan_id,
                    'charge_id'       => $charge->id,
                ]);
            } else {
                Log::warning('[PlanUpgrade] plan_id divergente do esperado no momento do pagamento — NAO sobrescrito, revisar manualmente', [
                    'subscription_id' => $sub->id,
                    'expected_from'   => $charge->from_plan_id,
                    'actual_plan_id'  => $sub->plan_id,
                    'charge_id'       => $charge->id,
                ]);
            }
        });

        if ($upgraded) {
            try {
                \App\Services\SalePushNotifier::notifySale($charge->subscription_id);
            } catch (\Throwable $e) {
                Log::warning('[PlanUpgrade] notifySale falhou', ['error' => $e->getMessage()]);
            }
        }

        return $upgraded;
    }

    /**
     * Consulta o status atual de uma order no Pagar.me (pro reconcile cron).
     */
    public function fetchOrderStatus(string $gatewayOrderId): ?array
    {
        $resp = Http::withHeaders($this->headers())->get("{$this->baseUrl}/orders/{$gatewayOrderId}");
        if (!$resp->successful()) {
            return null;
        }
        return $resp->json();
    }
}
