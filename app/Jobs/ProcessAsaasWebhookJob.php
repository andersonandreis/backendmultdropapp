<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PixTransaction;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;

class ProcessAsaasWebhookJob implements ShouldQueue
{
    use Queueable;

    protected array $payload;

    public function __construct(array $payload)
    {
        $this->payload = $payload;
    }

    public function handle(): void
    {
        Log::info('ProcessAsaasWebhookJob started Processing', $this->payload);

        $event = $this->payload['event'] ?? null;
        $paymentData = $this->payload['payment'] ?? [];

        // --- Subscription (recorrencia cartao): payment traz o id sub_... ---
        if (in_array($event, ['PAYMENT_CONFIRMED', 'PAYMENT_RECEIVED'], true) && isset($paymentData['subscription'])) {
            $subscription = Subscription::where('external_payment_id', $paymentData['subscription'])->first();
            if ($subscription) {
                $this->activateSubscription($subscription, isset($paymentData['value']) ? (float) $paymentData['value'] : null);
                Log::info("Assinatura Asaas #{$subscription->id} ativada/renovada via Job Assincrono.");
                return;
            }
        }

        // --- Wallet PIX top-up detection ---
        $externalRef = $paymentData['externalReference'] ?? '';
        if (in_array($event, ['PAYMENT_RECEIVED', 'PAYMENT_CONFIRMED']) && str_starts_with($externalRef, 'wallet_')) {
            ProcessPaymentWebhookJob::dispatch($this->payload, 'asaas');
            Log::info('ProcessAsaasWebhookJob: delegando recarga wallet para ProcessPaymentWebhookJob', [
                'external_reference' => $externalRef,
            ]);
            return;
        }

        if (in_array($event, ['PAYMENT_RECEIVED', 'PAYMENT_CONFIRMED'])) {
            $asaasId = $paymentData['id'] ?? null;
            if (!$asaasId) {
                return;
            }

            // --- INF-064: checkout seller.global (PIX/boleto avulso) ---
            // external_payment_id da subscription = pay_... criado no checkout.
            $subscription = Subscription::where('external_payment_id', $asaasId)->first();
            if ($subscription) {
                $this->activateSubscription($subscription, isset($paymentData['value']) ? (float) $paymentData['value'] : null);
                Log::info('ProcessAsaasWebhookJob: assinatura checkout ativada via PIX/boleto', [
                    'subscription_id' => $subscription->id,
                    'asaas_payment'   => $asaasId,
                ]);
                return;
            }

            // --- Pedido manual via PIX Asaas (NOV-099/HUB-115) ---
            // payment[id] vem como external_id do Payment do pedido manual.
            // Idempotente: se Payment ja esta paid, retorna; senao marca paid + Order paid + PixTransaction paid.
            $payment = Payment::where('external_id', $asaasId)
                ->where('gateway', 'asaas')
                ->first();
            if (!$payment) {
                Log::info('ProcessAsaasWebhookJob: payment nao encontrado pelo external_id', ['external_id' => $asaasId]);
                return;
            }
            if ($payment->status === 'paid') {
                Log::info('ProcessAsaasWebhookJob: payment ja pago (idempotencia)', ['payment_id' => $payment->id]);
                return;
            }
            DB::transaction(function () use ($payment) {
                $payment->update([
                    'status'  => 'paid',
                    'paid_at' => now(),
                ]);
                if ($payment->order_id) {
                    Order::where('id', $payment->order_id)
                        ->where('status', 'pending_payment')
                        ->update([
                            'status'  => 'paid',
                            'paid_at' => now(),
                        ]);
                }
                if ($payment->pix_transaction_id) {
                    PixTransaction::where('id', $payment->pix_transaction_id)
                        ->update(['status' => 'paid']);
                }
            });
            Log::info('ProcessAsaasWebhookJob: pedido manual marcado como pago', [
                'payment_id' => $payment->id,
                'order_id'   => $payment->order_id,
            ]);
        }
    }

    /**
     * INF-064: ativa assinatura paga + side effects de boas-vindas (mesmo
     * padrao do CheckoutController quando o pagamento aprova na hora).
     * Idempotente: se pagarme_status ja era paid, so renova o periodo.
     */
    protected function activateSubscription(Subscription $subscription, ?float $paidAmount = null): void
    {
        $wasPaid = $subscription->pagarme_status === 'paid';

        $subscription->update([
            'status'               => 'active',
            'pagarme_status'       => 'paid',
            'trial_ends_at'        => null,
            'current_period_start' => now(),
            'current_period_end'   => now()->addMonth(),
        ]);

        // SEL-386: comissao de afiliado. O caminho Pagar.me ja fazia isso (SEL-345); o Asaas nao,
        // entao venda por PIX indicada por afiliado nunca gerava comissao.
        // Fica ANTES do return de renovacao pra contemplar tambem as recorrentes (10%).
        if ($subscription->payment_method !== \App\Services\AffiliateAccessService::GRANT_METHOD) {
            $cli = $subscription->client;
            if ($cli) {
                $bruto = $paidAmount
                    ?? (float) ($subscription->plan?->price_yearly ?: $subscription->plan?->price_monthly ?: 0);
                \App\Services\AffiliateCommissionService::registerPayment(
                    $cli,
                    (float) $bruto,
                    $subscription->plan?->slug,
                    $subscription->id
                );
            }
        }

        if ($wasPaid) {
            return; // renovacao — sem reenviar boas-vindas
        }

        $client = $subscription->client;
        $user   = $client?->user;

        if ($user && !$user->is_active) {
            $user->update(['is_active' => true]);
        }

        try {
            \App\Services\SalePushNotifier::notifySale($subscription->id);
        } catch (\Throwable $e) {
            Log::warning('ProcessAsaasWebhookJob: SalePushNotifier falhou', ['err' => $e->getMessage()]);
        }

        // SEL-113 auto-invite grupo WhatsApp (mesma logica do CheckoutController)
        $whatsappGroupUrl = null;
        try {
            DB::transaction(function () use ($client, &$whatsappGroupUrl) {
                $cfg = DB::table('whatsapp_group_configs')->where('id', 1)->lockForUpdate()->first();
                if ($cfg && $cfg->auto_invite_enabled && $cfg->group_url && (int) $cfg->auto_invite_used < (int) $cfg->auto_invite_limit) {
                    DB::table('clients')->where('id', $client->id)->update(['whatsapp_invited_at' => now()]);
                    DB::table('whatsapp_group_configs')->where('id', 1)->increment('auto_invite_used');
                    $whatsappGroupUrl = $cfg->group_url;
                }
            });
        } catch (\Throwable $e) {
            Log::warning('ProcessAsaasWebhookJob: auto-invite falhou', ['err' => $e->getMessage()]);
        }

        try {
            if ($user) {
                Mail::to($user->email)->queue(new \App\Mail\SellerWelcomeMail(
                    $user, $client, $subscription->plan, '123456', $whatsappGroupUrl
                ));
            }
        } catch (\Throwable $e) {
            Log::warning('ProcessAsaasWebhookJob: SellerWelcomeMail falhou', ['err' => $e->getMessage(), 'subscription_id' => $subscription->id]);
        }
    }
}
