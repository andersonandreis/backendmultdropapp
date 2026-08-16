<?php

namespace App\Services;

use App\Models\AffiliateCommission;
use App\Models\AffiliateReferral;
use App\Models\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Services\SalePushNotifier;

/**
 * SEL-386 — comissao de afiliado a partir de um pagamento confirmado.
 *
 * A regra ja existia no PagarmeWebhookController (SEL-345), mas o caminho Asaas (PIX, INF-064)
 * nunca gerou comissao — por isso affiliate_commissions estava zerada. Este servico centraliza
 * a regra para que qualquer gateway consiga registrar.
 *
 * Regra: 30% na primeira fatura (event_type=upgrade), 10% nas recorrentes, no maximo 6.
 */
class AffiliateCommissionService
{
    public static function registerPayment(
        Client $client,
        float $grossAmount,
        ?string $planSlug = null,
        ?int $subscriptionId = null
    ): ?AffiliateCommission {
        try {
            if ($grossAmount <= 0) {
                return null;
            }

            $referral = AffiliateReferral::where('referred_client_id', $client->id)
                ->whereIn('status', ['pending', 'converted'])
                ->with('affiliate')
                ->latest()
                ->first();

            if (! $referral || ! $referral->affiliate) {
                return null; // cliente nao veio de afiliado
            }

            $affiliate = $referral->affiliate;
            if ($affiliate->approval_status !== 'approved' || $affiliate->status !== 'active') {
                return null;
            }

            // SEL-GERENTE (09/08): override RECORRENTE do gerente sobre esta venda, se o
            // afiliado estiver sob um gerente. Roda em TODA cobranca (nao so a 1a) e
            // independente do cap de 6 comissoes do afiliado abaixo — nao interfere na
            // comissao normal dele.
            \App\Services\AffiliateManagerService::registerOverrideForSale(
                $affiliate,
                $grossAmount,
                $planSlug,
                $subscriptionId,
                $referral->id,
                'recurring'
            );

            $jaExistentes = AffiliateCommission::where('affiliate_id', $affiliate->id)
                ->where('referral_id', $referral->id)
                ->count();

            $eventType = $jaExistentes === 0 ? 'upgrade' : 'recurring';

            if ($eventType === 'recurring' && $jaExistentes >= 6) {
                Log::info('[SEL-386] limite de 6 comissoes recorrentes atingido', [
                    'affiliate_id' => $affiliate->id,
                    'referral_id'  => $referral->id,
                ]);
                return null;
            }

            // SEL-GERENTE (decisao Ruan 09/08 tarde): afiliado SOB gerente ganha a
            // fatia que o gerente configurou (0..pool do gerente) em vez do
            // 30%/10% fixo — a comissao SAI do pool do gerente (divisao), nao e
            // um bonus por cima. Afiliado SEM gerente: nada muda.
            $subRate = \App\Services\AffiliateManagerService::resolveSubAffiliateRate($affiliate);
            $rate    = $subRate ?? ($eventType === 'upgrade' ? 30.00 : 10.00);
            $amount  = round($grossAmount * ($rate / 100), 2);

            $comissao = AffiliateCommission::create([
                'affiliate_id'      => $affiliate->id,
                'referral_id'       => $referral->id,
                'subscription_id'   => $subscriptionId,
                'gross_amount'      => $grossAmount,
                'commission_rate'   => $rate,
                'commission_amount' => $amount,
                'status'            => 'pending',
                'event_type'        => $eventType,
                'plan_slug'         => $planSlug,
            ]);

            if ($eventType === 'upgrade') {
                $referral->update(['status' => 'converted', 'converted_at' => now()]);
            }

            $affiliate->increment('total_earned', $amount);

            Log::info('[SEL-386] comissao registrada', [
                'affiliate_id' => $affiliate->id,
                'client_id'    => $client->id,
                'event_type'   => $eventType,
                'gross'        => $grossAmount,
                'amount'       => $amount,
                'plan_slug'    => $planSlug,
            ]);

            self::notificar($affiliate, $amount, $eventType);

            // SEL-388: push com som de venda pro afiliado
            if ($affiliate->user_id) {
                SalePushNotifier::notifyAffiliateCommission(
                    (int) $affiliate->user_id,
                    (float) $amount,
                    (string) ($client->user?->name ?? 'Cliente')
                );
            }

            return $comissao;
        } catch (\Throwable $e) {
            Log::error('[SEL-386] falha ao registrar comissao', [
                'client_id' => $client->id,
                'error'     => $e->getMessage(),
            ]);
            return null;
        }
    }

    private static function notificar($affiliate, float $amount, string $eventType): void
    {
        try {
            $email = $affiliate->user?->email ?? $affiliate->application_email;
            if (! $email) {
                return;
            }
            $nome  = $affiliate->user?->name ?? $affiliate->application_name;
            $tipo  = $eventType === 'upgrade' ? 'primeira fatura' : 'renovacao';
            $valor = number_format($amount, 2, ',', '.');

            Mail::raw(
                "Ola {$nome}!\n\nVoce recebeu uma nova comissao de R$ {$valor} ({$tipo}) por uma indicacao sua.\n\nAcompanhe pelo painel de afiliado.\n\nEquipe Seller Global",
                fn ($m) => $m->to($email)->subject('Nova comissao no Programa de Afiliados!')
            );
        } catch (\Throwable $e) {
            Log::warning('[SEL-386] email de comissao falhou', ['error' => $e->getMessage()]);
        }
    }
}
