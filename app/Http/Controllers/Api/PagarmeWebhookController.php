<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Plan;
use App\Models\PlanUpgradeCharge;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Webhook dedicado para eventos do Pagar.me V5.
 *
 * Rota: POST /api/webhooks/pagarme
 *
 * Configurar no painel Pagar.me (Configuracoes -> Webhooks):
 *   URL: https://api.hubai.io/api/webhooks/pagarme
 *   Secret: valor de PAGARME_WEBHOOK_SECRET no .env (para HMAC opcional)
 *
 * Eventos processados:
 *   - charge.paid           -> ativa subscription do cliente
 *   - order.paid            -> ativa subscription do cliente
 *   - payment.received      -> alias Asaas/Pagar.me (NOV-099)
 *   - subscription.canceled -> cancela subscription local
 *   - subscription.updated  -> sincroniza status
 *
 * Autenticacao: HMAC-SHA256 via header X-Hub-Signature (opcional).
 * Se PAGARME_WEBHOOK_SECRET nao estiver configurado no .env, aceita sem verificacao.
 */
class PagarmeWebhookController extends Controller
{
    /**
     * Recebe e processa eventos do Pagar.me V5.
     * Sempre retorna 200 para evitar reentregas desnecessarias.
     */
    public function handle(Request $request): JsonResponse
    {
        $rawPayload = $request->getContent();

        // SEL-WEBHOOKLOG (12/08): o registro de chegada era Log::info e o .env esta
        // com LOG_LEVEL=error — ou seja, NUNCA aparecia. Passamos horas concluindo
        // "o webhook nunca foi chamado" lendo um log incapaz de mostrar isso.
        // Agora toda batida cai num arquivo proprio, independente do nivel de log.
        try {
            $p = json_decode($rawPayload, true) ?: [];
            @file_put_contents(
                storage_path('logs/pagarme-webhook.log'),
                date('c') . ' | ' . $request->ip() . ' | ' . ($p['type'] ?? $p['event'] ?? '?')
                    . ' | ' . ($p['data']['id'] ?? '-') . ' | ' . mb_substr($rawPayload, 0, 400) . PHP_EOL,
                FILE_APPEND
            );
        } catch (\Throwable $e) { /* registro nunca pode derrubar o webhook */ }

        // 1. Verificar assinatura HMAC (opcional)
        $secret = config('services.pagarme.webhook_secret', env('PAGARME_WEBHOOK_SECRET', ''));
        if ($secret) {
            $signature = $request->header('X-Hub-Signature', '');
            if (!$this->verifyHmac($rawPayload, $signature, $secret)) {
                Log::warning('[PagarmeWebhook] Assinatura HMAC invalida', [
                    'ip'        => $request->ip(),
                    'signature' => substr($signature, 0, 30),
                ]);
                return response()->json(['ok' => false, 'reason' => 'invalid_signature'], 200);
            }
        }

        $payload = $request->json()->all();
        $event   = $payload['type'] ?? $payload['event'] ?? 'unknown';
        $data    = $payload['data'] ?? $payload;

        Log::info('[PagarmeWebhook] Evento recebido', [
            'event' => $event,
            'id'    => $data['id'] ?? null,
        ]);

        // SEL-UPGRADE (09/08): rota ISOLADA pra cobranca de DIFERENCA do fluxo de
        // upgrade de plano. Tem que ser checada ANTES do onChargePaid() generico:
        // o fallback por VALOR do onChargePaid (Plan::price_monthly +-10) pode
        // casar a diferenca com o proprio plano ATUAL do cliente (ex: diferenca
        // video_pro->video_ultra = R$148, cai na faixa +-10 do preco do
        // video_pro=R$149) e reativaria o plano ERRADO. Roteamento por
        // metadata.type=plan_upgrade OU por ja existir um PlanUpgradeCharge com
        // esse order_id (idempotente e nao depende do gateway sempre ecoar
        // metadata em todo formato de payload).
        $upgradeCandidateIds = array_values(array_filter([$data['id'] ?? null, $data['order_id'] ?? null]));
        $isPlanUpgrade = ($data['metadata']['type'] ?? null) === 'plan_upgrade';
        if (!$isPlanUpgrade && !empty($upgradeCandidateIds)) {
            $isPlanUpgrade = PlanUpgradeCharge::whereIn('gateway_order_id', $upgradeCandidateIds)->exists();
        }
        if ($isPlanUpgrade && in_array($event, ['charge.paid', 'order.paid', 'payment.received'])) {
            try {
                $this->onPlanUpgradePaid($data, $upgradeCandidateIds);
            } catch (\Throwable $e) {
                Log::error('[PagarmeWebhook][PlanUpgrade] Erro ao processar upgrade', [
                    'error' => $e->getMessage(),
                    'candidates' => $upgradeCandidateIds,
                ]);
            }
            return response()->json(['ok' => true]);
        }

        // SEL-CARTEIRA-WEBHOOK (14/08) — deposito na carteira do afiliado.
        // Vem ANTES do fluxo de assinatura de proposito: esse PIX nao e assinatura de
        // ninguem, e credito na carteira. Se cair no caminho de baixo, o codigo tenta
        // achar um cliente/assinatura que nao existe e loga erro a toa.
        // A confirmacao e IDEMPOTENTE (o Pagar.me repete webhook) — creditar duas
        // vezes o mesmo PIX seria dinheiro inventado.
        if (in_array($event, ['charge.paid', 'order.paid', 'payment.received'], true)) {
            $idsCarteira = array_values(array_filter([
                $data['id'] ?? null,
                $data['order_id'] ?? null,
                $data['charges'][0]['id'] ?? null,
            ]));
            $ehCarteira = ($data['metadata']['tipo'] ?? null) === 'carteira_afiliado';
            $deposito   = null;
            foreach ($idsCarteira as $cand) {
                $deposito = app(\App\Services\Affiliate\AffiliateWalletService::class)->acharDepositoPorCharge((string) $cand);
                if ($deposito) { break; }
            }
            if ($deposito || $ehCarteira) {
                try {
                    if ($deposito) {
                        app(\App\Services\Affiliate\AffiliateWalletService::class)
                            ->confirmarDeposito((int) $deposito->id, null, 'Webhook Pagar.me: ' . $event);
                        Log::error('[SEL-CARTEIRA-WEBHOOK] deposito confirmado pelo gateway', [
                            'deposito_id' => $deposito->id, 'event' => $event,
                        ]);
                    } else {
                        Log::error('[SEL-CARTEIRA-WEBHOOK] pagamento de carteira sem deposito casado', [
                            'ids' => $idsCarteira, 'event' => $event,
                        ]);
                    }
                } catch (\Throwable $e) {
                    Log::error('[SEL-CARTEIRA-WEBHOOK] falhou ao creditar', [
                        'ids' => $idsCarteira, 'erro' => $e->getMessage(),
                    ]);
                }

                return response()->json(['ok' => true, 'carteira' => true]);
            }
        }

        try {
            // SEL-ESTORNO (12/08, ordem do Ruan: "bloqueia na hora"). Antes o codigo
            // so ouvia pagamento; estorno e chargeback caiam no "evento ignorado" e
            // o cliente continuava usando a plataforma depois de pegar o dinheiro
            // de volta. Agora: dinheiro volta -> acesso fecha na hora.
            if (in_array($event, [
                'charge.refunded', 'charge.chargedback', 'charge.partial_canceled',
                'chargeback.received', 'order.canceled',
            ], true)) {
                $this->onDinheiroDevolvido($data, $event);
            } elseif (in_array($event, ['charge.paid', 'order.paid', 'payment.received'])) {
                $this->onChargePaid($data, $event);
            } elseif ($event === 'subscription.canceled') {
                $this->onSubscriptionCanceled($data);
            } elseif ($event === 'subscription.updated') {
                $this->onSubscriptionUpdated($data);
            } else {
                Log::debug("[PagarmeWebhook] Evento ignorado: {$event}");
            }
        } catch (\Throwable $e) {
            Log::error('[PagarmeWebhook] Erro ao processar evento', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * SEL-ESTORNO — dinheiro devolvido (reembolso, chargeback ou cancelamento):
     * fecha o acesso IMEDIATAMENTE e deixa rastro de por que fechou.
     *
     * Acha o cliente por qualquer caminho que o payload oferecer: id do pedido/
     * cobranca gravado na assinatura, id de cliente do gateway, ou e-mail. O
     * primeiro que casar vale — payload de estorno vem em formatos diferentes
     * dependendo de quem originou (cliente, banco ou o proprio painel).
     */
    protected function onDinheiroDevolvido(array $data, string $event): void
    {
        $ids = array_values(array_filter([
            $data['id'] ?? null,
            $data['order_id'] ?? null,
            $data['order']['id'] ?? null,
            $data['charge']['id'] ?? null,
            $data['charge']['order_id'] ?? null,
        ]));
        $customerId = $data['customer']['id'] ?? ($data['charge']['customer']['id'] ?? null);
        $email      = $data['customer']['email'] ?? ($data['charge']['customer']['email'] ?? null);

        $q = Subscription::query();
        $achou = false;
        if ($ids) {
            $q->where(function ($w) use ($ids) {
                $w->whereIn('pagarme_subscription_id', $ids)
                  ->orWhereIn('external_payment_id', $ids);
            });
            $achou = true;
        } elseif ($customerId) {
            $q->where('pagarme_customer_id', $customerId);
            $achou = true;
        } elseif ($email) {
            $user = User::where('email', $email)->first();
            $cli  = $user ? Client::where('user_id', $user->id)->first() : null;
            if ($cli) { $q->where('client_id', $cli->id); $achou = true; }
        }

        if (! $achou) {
            Log::error('[SEL-ESTORNO] estorno recebido e NAO consegui identificar o cliente', [
                'event' => $event, 'payload_keys' => array_keys($data),
            ]);
            $this->registrarEstorno($event, null, 'nao identificado', $ids, $email);
            return;
        }

        $assinaturas = $q->whereIn('status', ['active', 'trialing', 'pending_release'])->get();
        if ($assinaturas->isEmpty()) {
            Log::warning('[SEL-ESTORNO] estorno sem assinatura ativa correspondente', [
                'event' => $event, 'ids' => $ids, 'email' => $email,
            ]);
            $this->registrarEstorno($event, null, 'sem assinatura ativa', $ids, $email);
            return;
        }

        foreach ($assinaturas as $sub) {
            $antes = $sub->status;
            $sub->status        = 'blocked';
            $sub->pagarme_status = 'refunded';
            $sub->cancelled_at  = now();
            $sub->save();

            Log::error('[SEL-ESTORNO] ACESSO BLOQUEADO por devolucao de dinheiro', [
                'event'        => $event,
                'subscription' => $sub->id,
                'client'       => $sub->client_id,
                'status_antes' => $antes,
                'ids'          => $ids,
            ]);
            $this->registrarEstorno($event, $sub->id, "bloqueado (antes: {$antes})", $ids, $email);
        }
    }

    /** Rastro em arquivo proprio: estorno e coisa de dinheiro, nao pode depender do LOG_LEVEL. */
    protected function registrarEstorno(string $event, ?int $subId, string $resultado, array $ids, ?string $email): void
    {
        @file_put_contents(
            storage_path('logs/estornos.log'),
            date('c') . " | {$event} | sub=" . ($subId ?: '-') . " | {$resultado} | ids=" . implode(',', $ids)
                . ' | ' . ($email ?: '-') . PHP_EOL,
            FILE_APPEND
        );
    }

    /**
     * Processa pagamento confirmado (charge.paid ou order.paid).
     */
    protected function onChargePaid(array $data, string $event): void
    {
        $chargeId = $data['id'] ?? null;
        if (empty($chargeId)) {
            Log::warning('[PagarmeWebhook] onChargePaid recebeu payload sem charge_id - abortando para evitar registro fantasma', [
                'payload_keys' => array_keys($data ?? []),
            ]);
            return;
        }

        // SEL-CICLO (12/08): o Pagar.me manda charge.paid E order.paid pra MESMA
        // compra, com ids diferentes (ch_... e or_...) e no MESMO segundo (visto
        // no pagarme-webhook.log). Estes sao os ids que identificam a TRANSACAO
        // (nao a assinatura do gateway — sub_... fica de fora de proposito, senao
        // renovacao de cartao seria confundida com evento repetido).
        $txIds = $this->idsDaTransacao($data);

        // SEL-CICLO: trava atomica por COMPRA (mesma chave pros dois eventos).
        // So serializa; quem decide se ja foi processado e a checagem de banco
        // logo abaixo — mas sem a trava as duas entregas simultaneas passavam
        // juntas pela checagem e geravam comissao de afiliado/CAPI em dobro.
        $lockKey = 'sel_pagarme_compra:' . ($data['order']['id'] ?? $data['order_id'] ?? $chargeId);
        $lock    = \Illuminate\Support\Facades\Cache::lock($lockKey, 60);
        if (! $lock->get()) {
            Log::info('[PagarmeWebhook][SEL-CICLO] entrega concorrente da mesma compra, ignorando', [
                'event' => $event, 'charge_id' => $chargeId, 'lock' => $lockKey,
            ]);
            return;
        }

        try {
            $this->ativarAcessoPago($data, $event, $chargeId, $txIds);
        } finally {
            $lock->release();
        }
    }

    /**
     * SEL-CICLO: ids que identificam a TRANSACAO paga, em todos os formatos que
     * o Pagar.me usa. order.paid traz data.id=or_ + data.charges[].id=ch_;
     * charge.paid traz data.id=ch_ + data.order.id=or_ (confirmado no payload
     * real da conta ON LINE RJ). Com os dois lados preenchidos, qualquer um dos
     * dois eventos reconhece o trabalho ja feito pelo outro.
     */
    protected function idsDaTransacao(array $data): array
    {
        $ids = [
            $data['id']            ?? null,
            $data['order_id']      ?? null,
            $data['order']['id']   ?? null,
            $data['charge']['id']  ?? null,
        ];
        foreach ((array) ($data['charges'] ?? []) as $ch) {
            $ids[] = $ch['id'] ?? null;
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * SEL-CICLO: corpo do "pagou -> tem acesso", ja dentro da trava por compra.
     */
    protected function ativarAcessoPago(array $data, string $event, string $chargeId, array $txIds): void
    {
        // HUB-153: guard Tokfy - a MESMA conta Pagar.me e usada por HubAI/Seller
        // Global e Tokfy. Sem esse guard, uma cobranca 'Tokfy - Acesso Anual'
        // (R$ 297, mesmo valor do Pro) chegava aqui, pulava para User+Client+
        // Subscription e concedia acesso Pro indevidamente. Vide HUB-143.
        //
        // SEL-CICLO (12/08): o guard so funcionava no evento order.paid. O
        // payload de charge.paid NAO tem 'items' nem 'order.items' (conferido
        // na API v5: a charge traz apenas order.id/code/amount) — ou seja, uma
        // compra Tokfy paga passava batido pelo charge.paid e ganhava acesso ao
        // Seller Global mesmo assim. Agora, quando os itens nao vem no payload,
        // buscamos a order no gateway pra decidir com informacao de verdade.
        if ($this->ehCompraTokfy($data)) {
            Log::info('[PagarmeWebhook][HUB-153] Skipping Tokfy charge (nao e Seller Global)', [
                'charge_id' => $chargeId,
                'event'     => $event,
                'email'     => $data['customer']['email'] ?? null,
            ]);

            // ══ SEL-CADA-UM-FALA-DE-SI (16/08) ═══════════════════════════════════
            //
            // Regra do Ruan: "tokfy so fala de tokfy, cada empresa so fala de si".
            //
            // Aqui existia um repasse: o seller mandava a compra da Tokfy pro backend
            // dela. Fazia sentido enquanto a conta Pagar.me tinha UM webhook so, apontado
            // pra ca — sem o repasse o cliente pagava e nao recebia nada.
            //
            // Nao precisa mais: as 17:34 de 16/08 foi criado no painel um webhook DIRETO
            // pra api.tokfy.io (hookset_1XGBROecgqTWmwya, charge.paid + order.paid,
            // testado de fora: 200). A Tokfy recebe sozinha; o seller volta a nao saber
            // que ela existe.
            //
            // Fica so o rastro: se um dia o caminho direto parar, este log mostra que a
            // cobranca passou por aqui — sem agir, sem repassar, sem decidir nada.
            Log::info('[SEL-CADA-UM-FALA-DE-SI] cobranca da Tokfy vista e ignorada (ela recebe direto)', [
                'charge_id' => $chargeId,
                'email'     => $data['customer']['email'] ?? null,
            ]);

            return;
        }

        // Idempotencia: qualquer id desta transacao ja gravado numa assinatura
        // ATIVA significa que o outro evento (ou uma reentrega do gateway) ja
        // fez o trabalho. Nao duplica assinatura, nao estende prazo, nao gera
        // comissao/CAPI de novo.
        $jaProcessado = Subscription::where('status', 'active')
            ->whereIn('external_payment_id', $txIds)
            ->first();
        if ($jaProcessado) {
            Log::info('[PagarmeWebhook][SEL-CICLO] transacao ja processada, ignorando', [
                'event' => $event, 'charge_id' => $chargeId,
                'subscription_id' => $jaProcessado->id, 'ids' => $txIds,
            ]);
            $this->registrarCiclo($event, $chargeId, 'repetido — ignorado', $jaProcessado->id, null);
            return;
        }

        $email    = $data['customer']['email'] ?? $data['metadata']['email'] ?? null;
        if (!$email) {
            Log::warning('[PagarmeWebhook] onChargePaid sem email', ['charge_id' => $chargeId]);
            return;
        }

        $pagarmeSubId = $data['invoice']['subscriptionId'] ?? $data['subscription_id'] ?? null;
        $planSlug     = $data['customer']['metadata']['plan_slug'] ?? $data['metadata']['plan_slug'] ?? null;
        $amount       = ($data['amount'] ?? 0) / 100;

        // SEL-CICLO: descobre o plano ANTES de mexer no banco (ver resolverPlano).
        [$planId, $ehAnual, $comoAchou] = $this->resolverPlano($data, $amount, $planSlug, $txIds);
        $fimDoPeriodo  = $ehAnual ? now()->addYear() : now()->addMonth();
        $metodo        = $this->metodoDePagamento($data);

        Log::info('[PagarmeWebhook] Ativando conta', [
            'email'     => $email,
            'charge_id' => $chargeId,
            'amount'    => $amount,
            'plan_id'   => $planId,
            'plano_via' => $comoAchou,
            'anual'     => $ehAnual,
        ]);

        $notifySubId = null;
        $activatedClient = null;      // SEL-110: pra disparar email + auto-invite fora da transacao
        $activatedUser = null;
        $activatedPlan = null;
        $isNewUserActivation = false;
        DB::transaction(function () use ($email, $chargeId, $pagarmeSubId, $planId, $fimDoPeriodo, $metodo, $amount, $data, &$notifySubId, &$activatedClient, &$activatedUser, &$activatedPlan, &$isNewUserActivation) {
            $user = User::where('email', $email)->first();

            if (!$user) {
                $name = $data['customer']['name'] ?? explode('@', $email)[0];
                // SEL-CICLO: cliente que NUNCA teve conta (o caso mais comum de
                // "paguei e nao recebi") nasce aqui: senha padrao 123456, e-mail
                // ja verificado e conta ativa — ou seja, ele consegue logar
                // assim que o webhook termina, sem intervencao manual.
                $user = User::create([
                    'name'              => $name,
                    'email'             => $email,
                    'password'          => bcrypt('123456'),
                    'role'              => 'client',
                    'is_active'         => true,
                    'email_verified_at' => now(),
                ]);
                $isNewUserActivation = true; // SEL-110: usar pra mandar senha 123456 no email
                Log::info('[PagarmeWebhook] Usuario criado', ['user_id' => $user->id, 'email' => $email]);
            } else {
                $user->update(['is_active' => true]);
            }

            $client = Client::where('user_id', $user->id)->first();
            if (!$client) {
                // Extrair document do payload se disponivel (CPF/CNPJ)
                $document = $data['customer']['document'] ?? null;
                $document = $document ? (preg_replace('/\D/', '', $document) ?: null) : null;

                // MUL-269 fase 2: company_name removido de clients — nome vem do user (accessor).
                $client = Client::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'document'     => $document,
                        'is_active'    => true,
                    ]
                );
            }

            // SEL-CICLO: plano e prazo ja resolvidos por resolverPlano() antes da
            // transacao (metadata -> assinatura local do checkout -> descricao do
            // item -> valor), com prazo anual quando o valor casa com price_yearly.
            $subData = [
                'status'                  => 'active',
                'pagarme_status'          => 'paid',
                'pagarme_subscription_id' => $pagarmeSubId ?? $chargeId,
                'external_payment_id'     => $chargeId,
                'current_period_start'    => now(),
                'current_period_end'      => $fimDoPeriodo,
                'trial_ends_at'           => null,
                'cancelled_at'            => null,
            ];
            if ($planId) {
                $subData['plan_id'] = $planId;
            }
            if ($customerId = ($data['customer']['id'] ?? null)) {
                $subData['pagarme_customer_id'] = $customerId;
            }

            $sub = Subscription::where('client_id', $client->id)->first();
            if ($sub) {
                $sub->update($subData);
            } else {
                $subData['client_id'] = $client->id;
                // SEL-CICLO: era 'credit_card' fixo — PIX/boleto ficavam gravados
                // como cartao e sujavam relatorio e reaproveitamento de PIX.
                $subData['payment_method'] = $metodo;
                $sub = Subscription::create($subData);
            }
            $notifySubId = $sub->id;

            // SEL-110: guardar refs pra disparar email + auto-invite fora da tx
            $activatedClient = $client;
            $activatedUser = $user;
            $activatedPlan = $planId ? Plan::find($planId) : null;

            Log::info('[PagarmeWebhook] Conta ativada', [
                'client_id' => $client->id,
                'email'     => $email,
                'plan_id'   => $planId,
                'charge_id' => $chargeId,
            ]);
        });

        if ($notifySubId) {
            \App\Services\SalePushNotifier::notifySale($notifySubId);
        }

        // SEL-110/SEL-113 fix: enviar SellerWelcomeMail + tentar auto-invite tambem
        // no fluxo webhook (PIX/boleto), nao so no checkout sincrono card. Antes so
        // saia email quando pagava com cartao — PIX ficava sem CTA de acesso e sem grupo.
        if ($activatedClient && $activatedUser && $activatedPlan) {
            $whatsappGroupUrl = null;
            try {
                DB::transaction(function () use ($activatedClient, &$whatsappGroupUrl) {
                    $cfg = DB::table('whatsapp_group_configs')->where('id', 1)->lockForUpdate()->first();
                    if ($cfg && $cfg->auto_invite_enabled && $cfg->group_url && (int)$cfg->auto_invite_used < (int)$cfg->auto_invite_limit) {
                        // So marca whatsapp_invited_at se ainda nao foi marcado pra
                        // nao decrementar contador em cobrancas recorrentes do mesmo cliente
                        $already = DB::table('clients')->where('id', $activatedClient->id)->value('whatsapp_invited_at');
                        if (!$already) {
                            DB::table('clients')->where('id', $activatedClient->id)->update(['whatsapp_invited_at' => now()]);
                            DB::table('whatsapp_group_configs')->where('id', 1)->increment('auto_invite_used');
                            $whatsappGroupUrl = $cfg->group_url;
                        } else {
                            // Ja foi convidado antes (ex renovacao): manda a URL mas sem decrementar
                            $whatsappGroupUrl = $cfg->group_url;
                        }
                    }
                });
            } catch (\Throwable $e) {
                Log::warning('[PagarmeWebhook] SEL-113 auto-invite falhou', ['client_id' => $activatedClient->id, 'err' => $e->getMessage()]);
            }

            // SEL-CICLO (12/08, ordem do dono): o e-mail de boas-vindas fica
            // PRONTO mas DESARMADO — so sai quando a empresa estiver 100%.
            // Liga com PAGARME_WELCOME_MAIL_ENABLED=true no .env (ver
            // config/payment.php) + php artisan optimize:clear. Enquanto
            // desligado, o que o cliente receberia fica registrado no
            // ciclo-acesso.log, entao da pra auditar sem mandar nada.
            if (config('payment.welcome_mail_enabled')) {
                try {
                    \Illuminate\Support\Facades\Mail::to($activatedUser->email)->queue(new \App\Mail\SellerWelcomeMail(
                        $activatedUser, $activatedClient, $activatedPlan, $isNewUserActivation ? '123456' : null, $whatsappGroupUrl
                    ));
                } catch (\Throwable $e) {
                    Log::warning('[PagarmeWebhook] SEL-110 SellerWelcomeMail dispatch failed', ['error' => $e->getMessage(), 'client_id' => $activatedClient->id]);
                }
            } else {
                Log::info('[PagarmeWebhook][SEL-CICLO] e-mail de boas-vindas DESARMADO (payment.welcome_mail_enabled=false)', [
                    'client_id' => $activatedClient->id,
                    'email'     => $activatedUser->email,
                ]);
            }
        }

        // SEL-CICLO: rastro proprio do ciclo "pagou -> tem acesso" (o .env esta em
        // LOG_LEVEL=error, entao Log::info nao aparece em lugar nenhum).
        $this->registrarCiclo(
            $event,
            $chargeId,
            'ativado' . ($isNewUserActivation ? ' (usuario NOVO, senha 123456)' : ' (usuario ja existia)')
                . ' plano=' . ($activatedPlan?->slug ??'-') . ' via=' . $comoAchou
                . ' ate=' . $fimDoPeriodo->toDateString() . ' metodo=' . $metodo,
            $notifySubId,
            $email
        );

        // SEL-345: Criar AffiliateCommission se cliente veio via link de afiliado
        if ($activatedClient) {
            $this->createAffiliateCommission($activatedClient, $amount, $activatedPlan?->slug ??$planSlug);
        }

        // SEL-010: disparar Purchase CAPI apos transacao confirmada (fora do DB::transaction pra nao bloquear)
        $this->dispatchCapiPurchase($chargeId, $email, $amount, $data);
        // SEL-TIKTOKAPI (13/08): mesmo gatilho, agora tambem pro TikTok.
        $this->dispatchTiktokPurchase($chargeId, $email, $amount, $data);
    }

    /** SEL-CICLO: rastro em arquivo proprio, independente do LOG_LEVEL. */
    protected function registrarCiclo(string $event, string $chargeId, string $resultado, ?int $subId, ?string $email): void
    {
        @file_put_contents(
            storage_path('logs/ciclo-acesso.log'),
            date('c') . " | {$event} | {$chargeId} | sub=" . ($subId ?: '-') . " | " . ($email ?: '-')
                . " | {$resultado}" . PHP_EOL,
            FILE_APPEND
        );
    }

    /**
     * HUB-153 reforcado (SEL-CICLO): a compra e da Tokfy?
     *
     * order.paid traz os itens no payload. charge.paid NAO traz (a charge so
     * tem order.id/code/amount) — por isso, quando nao ha item nenhum pra
     * inspecionar mas existe um id de order, buscamos a order no gateway.
     * Falha de rede aqui devolve false (segue o fluxo normal): a compra Tokfy
     * ainda seria barrada pelo evento order.paid, que tem os itens.
     */
    protected function ehCompraTokfy(array $data): bool
    {
        $items = $data['items'] ?? ($data['order']['items'] ?? []);

        if (empty($items)) {
            $orderId = $data['order']['id'] ?? $data['order_id'] ?? null;
            if ($orderId && str_starts_with((string) $orderId, 'or_')) {
                $order = $this->buscarOrderNoGateway($orderId);
                $items = $order['items'] ?? [];
            }
        }

        foreach ((array) $items as $it) {
            $texto = strtolower((string) ($it['code'] ?? '') . ' ' . (string) ($it['description'] ?? ''));
            if (str_contains($texto, 'tokfy') || str_contains($texto, 'tockfy')) {
                return true;
            }
        }

        return false;
    }

    /** SEL-CICLO: le a order no Pagar.me (cache curto: os dois eventos da mesma compra chegam juntos). */
    protected function buscarOrderNoGateway(string $orderId): array
    {
        return \Illuminate\Support\Facades\Cache::remember(
            'sel_pagarme_order:' . $orderId,
            300,
            function () use ($orderId) {
                try {
                    $key  = config('payment.pagarme.api_key', env('PAGARME_API_KEY', ''));
                    if (empty($key)) { return []; }
                    $resp = \Illuminate\Support\Facades\Http::withBasicAuth($key, '')
                        ->timeout(10)
                        ->get("https://api.pagar.me/core/v5/orders/{$orderId}");

                    return $resp->successful() ? (array) $resp->json() : [];
                } catch (\Throwable $e) {
                    Log::warning('[PagarmeWebhook][SEL-CICLO] falha ao ler order no gateway', [
                        'order_id' => $orderId, 'error' => $e->getMessage(),
                    ]);
                    return [];
                }
            }
        );
    }

    /**
     * SEL-CICLO: descobre QUAL plano foi comprado, do sinal mais confiavel pro
     * menos confiavel. Antes existia so o ultimo degrau (chutar pelo valor), e
     * ele erra feio:
     *
     *   - o checkout manda metadata.plan_id (cartao) mas o codigo so lia
     *     'plan_slug' — ou seja, o dado certo chegava e era jogado fora;
     *   - no PIX a order vai pro gateway SEM metadata nenhuma;
     *   - a faixa de +-10 no price_monthly casava qualquer cobranca de ate
     *     R$ 10 com planos de price_monthly=0 que estao ativos (tt_shop_annual,
     *     promo_live_297, live_only) — R$ 1 virava plano ANUAL;
     *   - compra ANUAL de R$ 297 (promo_live_297/tt_shop_annual, que tem
     *     price_monthly=0 e price_yearly=297) casava com o 'pro' MENSAL de
     *     R$ 297 e ainda ganhava prazo de 1 mes.
     *
     * @return array{0:?int,1:bool,2:string} [plan_id, ehAnual, comoAchou]
     */
    protected function resolverPlano(array $data, float $amount, ?string $planSlug, array $txIds): array
    {
        $plan = null;
        $via  = 'nao identificado';

        // 1) metadata explicita do checkout (plan_id e o que o CheckoutController manda)
        $meta = array_merge(
            (array) ($data['metadata'] ?? []),
            (array) ($data['order']['metadata'] ?? []),
            (array) ($data['customer']['metadata'] ?? [])
        );
        if (!empty($meta['plan_id']) && is_numeric($meta['plan_id'])) {
            $plan = Plan::find((int) $meta['plan_id']);
            $via  = 'metadata.plan_id';
        }
        $slug = $planSlug ?: ($meta['plan_slug'] ?? null);
        if (!$plan && $slug) {
            $plan = Plan::where('slug', $slug)->first();
            $via  = 'metadata.plan_slug';
        }

        // 2) assinatura local criada pelo proprio checkout (fonte da verdade no
        //    PIX: finalizeCheckout grava pagarme_subscription_id/external_payment_id
        //    = id da order JUNTO com o plan_id correto, antes do pagamento).
        if (!$plan) {
            $ids = $txIds;
            foreach ([$data['subscription_id'] ?? null, $data['invoice']['subscriptionId'] ?? null] as $extra) {
                if ($extra) { $ids[] = $extra; }
            }
            $local = Subscription::whereNotNull('plan_id')
                ->where(function ($w) use ($ids) {
                    $w->whereIn('pagarme_subscription_id', $ids)
                      ->orWhereIn('external_payment_id', $ids);
                })
                ->orderByDesc('id')
                ->first();
            if ($local && $local->plan_id) {
                $plan = Plan::find($local->plan_id);
                $via  = 'assinatura local do checkout';
            }
        }

        // 3) descricao do item ("Assinatura {nome do plano} - Seller Global")
        if (!$plan) {
            $items = $data['items'] ?? ($data['order']['items'] ?? []);
            if (empty($items)) {
                $orderId = $data['order']['id'] ?? $data['order_id'] ?? null;
                if ($orderId && str_starts_with((string) $orderId, 'or_')) {
                    $items = $this->buscarOrderNoGateway($orderId)['items'] ?? [];
                }
            }
            // ATENCAO: casar nome de plano dentro da descricao com stripos() e
            // ARMADILHA — o plano "Pro" casa dentro de "Promocao" e uma compra
            // ANUAL de R$ 297 ("Assinatura Anual Completo — Promoção Live")
            // virava plano 'pro' MENSAL (peguei isso no teste de 12/08).
            // Por isso: (a) planos de nome mais LONGO sao testados primeiro e
            // (b) o nome tem que aparecer como palavra inteira.
            $candidatos = Plan::where('is_active', true)->get()
                ->filter(fn ($p) => filled($p->name))
                ->sortByDesc(fn ($p) => mb_strlen($p->name));

            foreach ((array) $items as $it) {
                $desc = trim((string) ($it['description'] ?? ''));
                if ($desc === '') { continue; }

                // 1) formato exato que o nosso checkout gera
                foreach ($candidatos as $candidato) {
                    if (strcasecmp($desc, "Assinatura {$candidato->name} - Seller Global") === 0) {
                        $plan = $candidato;
                        $via  = 'descricao do item (formato do checkout)';
                        break 2;
                    }
                }

                // 2) nome do plano como palavra inteira dentro da descricao
                foreach ($candidatos as $candidato) {
                    $re = '/(?<![\p{L}\p{N}])' . preg_quote($candidato->name, '/') . '(?![\p{L}\p{N}])/ui';
                    if (preg_match($re, $desc)) {
                        $plan = $candidato;
                        $via  = 'descricao do item';
                        break 2;
                    }
                }
            }
        }

        // 4) ultimo recurso: valor pago. Mensal primeiro, depois anual, SEMPRE
        //    ignorando plano de preco zero (senao cobranca pequena virava plano anual).
        if (!$plan && $amount > 0) {
            $plan = Plan::where('is_active', true)
                ->where('price_monthly', '>', 0)
                ->whereBetween('price_monthly', [$amount - 10, $amount + 10])
                ->orderBy('price_monthly')
                ->first();
            if ($plan) {
                $via = 'valor (mensal)';
            } else {
                $plan = Plan::where('is_active', true)
                    ->where('price_yearly', '>', 0)
                    ->whereBetween('price_yearly', [$amount - 10, $amount + 10])
                    ->orderBy('price_yearly')
                    ->first();
                if ($plan) { $via = 'valor (anual)'; }
            }
        }

        if (!$plan) {
            return [null, false, $via];
        }

        // Anual quando o plano so tem preco anual, ou quando o valor pago bate
        // com o preco anual em vez do mensal.
        $mensal = (float) $plan->price_monthly;
        $anual  = (float) $plan->price_yearly;
        $ehAnual = $anual > 0 && ($mensal <= 0 || abs($amount - $anual) <= 10);

        return [$plan->id, $ehAnual, $via];
    }

    /** SEL-CICLO: metodo real do pagamento (era 'credit_card' fixo). */
    protected function metodoDePagamento(array $data): string
    {
        $pm = $data['payment_method']
            ?? ($data['charges'][0]['payment_method'] ?? null)
            ?? ($data['last_transaction']['payment_method'] ?? null)
            ?? ($data['charges'][0]['last_transaction']['payment_method'] ?? null);

        return in_array($pm, ['pix', 'boleto', 'credit_card', 'debit_card'], true) ? $pm : 'credit_card';
    }

    /**
     * SEL-UPGRADE (09/08): confirma pagamento da DIFERENCA e sobe o plano da
     * subscription.
     *
     * DESATUALIZADO desde 12/08 — deixado aqui porque ja custou horas: dizia
     * que "POST /api/webhooks/pagarme nao recebe trafego real". Isso valia pra
     * conta ANTIGA. Depois da troca pra conta NOVA (ON LINE RJ,
     * acc_ZJbEvzptzauydwrz) o webhook hookset_M4p0YQw3SySkrGXZ aponta pra
     * https://api.seller.global/api/webhooks/pagarme e as batidas reais estao
     * registradas em storage/logs/pagarme-webhook.log. O cron
     * `pagarme:reconcile-plan-upgrades` continua valendo como REDE DE SEGURANCA
     * (webhook perdido/fora do ar), nao como caminho principal. Quem tambem
     * confirma o upgrade e o cron
     * `pagarme:reconcile-plan-upgrades` (Console\Commands\ReconcilePlanUpgrades),
     * que chama o MESMO PlanUpgradeService::markChargePaidAndUpgrade() usado
     * aqui — logica unica, dois gatilhos.
     */
    protected function onPlanUpgradePaid(array $data, array $candidateIds): void
    {
        if (empty($candidateIds)) {
            return;
        }

        $charge = PlanUpgradeCharge::whereIn('gateway_order_id', $candidateIds)->first();
        if (!$charge) {
            Log::warning('[PagarmeWebhook][PlanUpgrade] Charge nao encontrado local', ['candidates' => $candidateIds]);
            return;
        }

        app(\App\Services\Subscription\PlanUpgradeService::class)->markChargePaidAndUpgrade($charge, $data);
    }

    protected function onSubscriptionCanceled(array $data): void
    {
        $pagarmeSubId = $data['id'] ?? null;
        if (!$pagarmeSubId) return;

        $affected = Subscription::where('pagarme_subscription_id', $pagarmeSubId)
            ->update([
                'status'         => 'canceled',
                'pagarme_status' => 'canceled',
                'cancelled_at'   => now(),
            ]);

        // Fallback: buscar por external_payment_id quando pagarme_subscription_id nao bate
        if ($affected === 0) {
            $affected = Subscription::where('external_payment_id', $pagarmeSubId)
                ->whereIn('status', ['active', 'trialing'])
                ->update([
                    'status'                  => 'canceled',
                    'pagarme_status'          => 'canceled',
                    'cancelled_at'            => now(),
                    'pagarme_subscription_id' => $pagarmeSubId,
                ]);

            if ($affected > 0) {
                Log::info('[PagarmeWebhook] Subscription cancelada via fallback external_payment_id', [
                    'pagarme_sub_id' => $pagarmeSubId,
                    'affected'       => $affected,
                ]);
            } else {
                Log::warning('[PagarmeWebhook] onSubscriptionCanceled: nenhuma subscription encontrada', [
                    'pagarme_sub_id' => $pagarmeSubId,
                ]);
            }
        } else {
            Log::info('[PagarmeWebhook] Subscription cancelada', [
                'pagarme_sub_id' => $pagarmeSubId,
                'affected'       => $affected,
            ]);
        }
    }

    protected function onSubscriptionUpdated(array $data): void
    {
        $pagarmeSubId = $data['id'] ?? null;
        $status       = $data['status'] ?? null;
        if (!$pagarmeSubId || !$status) return;

        $localStatus = match ($status) {
            'active'   => 'active',
            'canceled' => 'canceled',
            default    => $status,
        };

        Subscription::where('pagarme_subscription_id', $pagarmeSubId)
            ->update(['pagarme_status' => $status, 'status' => $localStatus]);

        Log::info('[PagarmeWebhook] Subscription atualizada', ['sub_id' => $pagarmeSubId, 'status' => $status]);
    }

    /**
     * Verifica assinatura HMAC-SHA256. Header: X-Hub-Signature: sha256=<hex>
     */
    protected function verifyHmac(string $payload, string $signature, string $secret): bool
    {
        if (empty($signature)) {
            return false;
        }
        $hash     = str_starts_with($signature, 'sha256=') ? substr($signature, 7) : $signature;
        $expected = hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $hash);
    }

    /**
     * SEL-345: Cria AffiliateCommission quando cliente indicado por afiliado faz upgrade.
     * Regra: 30% primeira fatura (event_type=upgrade), 10% recorrentes (event_type=recurring).
     * Detecta afiliado via AffiliateReferral do client.
     */
    private function createAffiliateCommission(\App\Models\Client $client, float $grossAmount, ?string $planSlug): void
    {
        try {
            $referral = \App\Models\AffiliateReferral::where('referred_client_id', $client->id)
                ->whereIn('status', ['pending', 'converted'])
                ->with('affiliate')
                ->latest()
                ->first();

            if (! $referral || ! $referral->affiliate) {
                return; // cliente nao veio via afiliado
            }

            $affiliate = $referral->affiliate;
            if ($affiliate->approval_status !== 'approved' || $affiliate->status !== 'active') {
                return; // afiliado nao aprovado
            }

            // SEL-GERENTE (09/08): override RECORRENTE do gerente sobre esta venda, se o
            // afiliado estiver sob um gerente. Roda em TODA cobranca (nao so a 1a) e
            // independente do cap de 6 comissoes do afiliado abaixo — nao interfere na
            // comissao normal dele.
            \App\Services\AffiliateManagerService::registerOverrideForSale(
                $affiliate,
                $grossAmount,
                $planSlug,
                null,
                $referral->id,
                'recurring'
            );

            // Determina se e primeira conversao ou recorrente
            $existingCommissions = \App\Models\AffiliateCommission::where('affiliate_id', $affiliate->id)
                ->where('referral_id', $referral->id)
                ->count();

            $eventType = $existingCommissions === 0 ? 'upgrade' : 'recurring';

            // Taxa: 30% primeira, 10% recorrentes (maximo 6 meses = 6 comissoes)
            if ($eventType === 'recurring' && $existingCommissions >= 6) {
                Log::info('[SEL-345] Afiliado atingiu limite de 6 comissoes recorrentes', [
                    'affiliate_id' => $affiliate->id,
                    'referral_id'  => $referral->id,
                ]);
                return;
            }

            // SEL-GERENTE (decisao Ruan 09/08 tarde): afiliado SOB gerente ganha a
            // fatia que o gerente configurou (0..pool do gerente) em vez do
            // 30%/10% fixo — a comissao SAI do pool do gerente (divisao), nao e
            // um bonus por cima. Afiliado SEM gerente: nada muda. Mesma regra de
            // AffiliateCommissionService::registerPayment (SEL-386), so que este
            // e o caminho de webhook direto do Pagar.me.
            $subRate = \App\Services\AffiliateManagerService::resolveSubAffiliateRate($affiliate);
            $rate    = $subRate ?? ($eventType === 'upgrade' ? 30.00 : 10.00);
            $amount  = round($grossAmount * ($rate / 100), 2);

            \App\Models\AffiliateCommission::create([
                'affiliate_id'    => $affiliate->id,
                'referral_id'     => $referral->id,
                'subscription_id' => null,
                'gross_amount'    => $grossAmount,
                'commission_rate' => $rate,
                'commission_amount' => $amount,
                'status'          => 'pending',
                'event_type'      => $eventType,
                'plan_slug'       => $planSlug,
            ]);

            // Marcar referral como convertido na primeira comissao
            if ($eventType === 'upgrade') {
                $referral->update([
                    'status'       => 'converted',
                    'converted_at' => now(),
                ]);
            }

            Log::info('[SEL-345] AffiliateCommission criada', [
                'affiliate_id'  => $affiliate->id,
                'client_id'     => $client->id,
                'event_type'    => $eventType,
                'amount'        => $amount,
                'plan_slug'     => $planSlug,
            ]);

            // Notifica afiliado
            $this->notifyAffiliateCommission($affiliate, $amount, $eventType, $planSlug);

        } catch (\Throwable $e) {
            Log::error('[SEL-345] Erro ao criar AffiliateCommission', [
                'client_id' => $client->id,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * SEL-345: Notifica afiliado por email quando comissao e gerada.
     */
    private function notifyAffiliateCommission(\App\Models\Affiliate $affiliate, float $amount, string $eventType, ?string $planSlug): void
    {
        try {
            $email = $affiliate->user?->email ?? $affiliate->application_email;
            $name  = $affiliate->user?->name  ?? $affiliate->application_name;
            $tipo  = $eventType === 'upgrade' ? 'primeira compra' : 'renovacao';
            $plan  = $planSlug ? " (plano {$planSlug})" : '';
            if ($email) {
                \Illuminate\Support\Facades\Mail::raw(
                    "Ola {$name}!\n\nVoce ganhou uma nova comissao de R$ " . number_format($amount, 2, ',', '.') . " referente a {$tipo}{$plan} de um cliente indicado por voce.\n\nAcesse seu dashboard em seller.global/afiliado/dashboard para ver o saldo.\n\nEquipe Seller Global",
                    fn ($m) => $m->to($email)->subject("Nova comissao de R$ " . number_format($amount, 2, ',', '.') . " no Programa de Afiliados!")
                );
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[SEL-345] Email comissao falhou', ['error' => $e->getMessage()]);
        }
    }


    /**
     * SEL-010: Dispara evento Purchase para Meta CAPI.
     * Env-gated: no-op silencioso se META_CAPI_TOKEN ausente.
     * event_id deterministico = purchase_{charge_id} para dedup com pixel client-side.
     */
    /**
     * SEL-META-CEGO (14/08): virou PUBLICO pra o reconciliador chamar O MESMO
     * disparador. Duplicar codigo de rastreamento e pior: as duas copias
     * desandam com o tempo e ninguem percebe, porque falha de pixel e calada.
     */
    public function dispatchCapiPurchase(string $chargeId, ?string $email, float $amount, array $data): void
    {
        $token   = env('META_CAPI_TOKEN', '');
        $pixelId = env('META_PIXEL_ID', '2114981255797268');
        if (empty($token)) { return; }

        $userData = [
            'client_ip_address' => request()->ip(),
            'client_user_agent'  => request()->userAgent() ?: 'server',
        ];
        if ($email) { $userData['em'] = hash('sha256', strtolower(trim($email))); }
        if ($phone = ($data['customer']['phones']['mobile_phone']['number'] ?? null)) {
            $userData['ph'] = hash('sha256', preg_replace('/\D/', '', $phone));
        }

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(10)->post(
                "https://graph.facebook.com/v21.0/{$pixelId}/events",
                [
                    'access_token' => $token,
                    'data' => [[
                        'event_name'       => 'Purchase',
                        'event_time'       => time(),
                        'event_id'         => 'purchase_' . $chargeId,
                        'action_source'    => 'website',
                        'event_source_url' => 'https://seller.global/obrigado',
                        'user_data'        => $userData,
                        'custom_data'     => [
                            'value'    => $amount,
                            'currency' => 'BRL',
                        ],
                    ]],
                ]
            );
            $body = $response->json();
            Log::info('[PagarmeWebhook][CAPI] Purchase enviado', [
                'charge_id'       => $chargeId,
                'event_id'        => 'purchase_' . $chargeId,
                'events_received' => $body['events_received'] ?? null,
                'http_status'     => $response->status(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[PagarmeWebhook][CAPI] Falha ao enviar Purchase', [
                'charge_id' => $chargeId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * SEL-TIKTOKAPI (13/08): dispara CompletePayment para a API de eventos do TikTok.
     *
     * Espelha o dispatchCapiPurchase do Meta: mesmo gatilho (transacao confirmada),
     * mesmo cuidado de rodar fora da transacao de banco, e no-op silencioso se a
     * chave nao existir no .env.
     *
     * DEDUP — o detalhe que decide se o numero fica certo:
     * o pixel no navegador (src/pages/site/Obrigado.tsx) manda event_id = orderId,
     * que e o `order.id` do Pagar.me. O webhook chega com o `charge.id` (ch_...),
     * que e OUTRO id. Se eu usasse chargeId aqui, o TikTok contaria DUAS compras
     * pra cada venda. Por isso pego o order.id do payload — o mesmo campo que o
     * proprio controller ja usa como chave da trava de concorrencia (SEL-CICLO).
     * Se por algum motivo nao vier order.id, caio no chargeId e registro no log,
     * porque nesse caso a dedup nao acontece e o numero merece desconfianca.
     */
    /** SEL-META-CEGO (14/08): publico pelo mesmo motivo do CAPI acima. */
    public function dispatchTiktokPurchase(string $chargeId, ?string $email, float $amount, array $data): void
    {
        $token   = env('TIKTOK_EVENTS_TOKEN', '');
        $pixelId = env('TIKTOK_PIXEL_ID', '');
        if (empty($token) || empty($pixelId)) { return; }

        $orderId = $data['order']['id'] ?? $data['order_id'] ?? null;
        if (empty($orderId)) {
            Log::warning('[PagarmeWebhook][TikTok] sem order.id no payload — dedup com o pixel do navegador NAO vai funcionar nesta venda', [
                'charge_id' => $chargeId,
            ]);
            $orderId = $chargeId;
        }

        $user = [
            'ip'         => request()->ip(),
            'user_agent' => request()->userAgent() ?: 'server',
        ];
        if ($email) { $user['email'] = hash('sha256', strtolower(trim($email))); }
        if ($phone = ($data['customer']['phones']['mobile_phone']['number'] ?? null)) {
            $so = preg_replace('/\D/', '', $phone);
            if ($so !== '' && strpos($so, '55') !== 0) { $so = '55' . $so; }
            $user['phone'] = hash('sha256', '+' . $so);
        }

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(10)
                ->withHeaders(['Access-Token' => $token])
                ->post('https://business-api.tiktok.com/open_api/v1.3/event/track/', [
                    'event_source'    => 'web',
                    'event_source_id' => $pixelId,
                    'data' => [[
                        'event'      => 'CompletePayment',
                        'event_time' => time(),
                        'event_id'   => (string) $orderId,
                        'user'       => $user,
                        'page'       => ['url' => 'https://seller.global/obrigado'],
                        'properties' => [
                            'currency' => 'BRL',
                            'value'    => $amount,
                        ],
                    ]],
                ]);
            $body = $response->json();
            Log::info('[PagarmeWebhook][TikTok] CompletePayment enviado', [
                'charge_id'   => $chargeId,
                'event_id'    => (string) $orderId,
                'code'        => $body['code'] ?? null,
                'message'     => $body['message'] ?? null,
                'http_status' => $response->status(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[PagarmeWebhook][TikTok] Falha ao enviar CompletePayment', [
                'charge_id' => $chargeId,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
