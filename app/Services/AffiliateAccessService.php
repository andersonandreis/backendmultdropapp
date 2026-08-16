<?php

namespace App\Services;

use App\Models\Affiliate;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Support\Facades\Log;

/**
 * SEL-386 — acesso concedido ao afiliado.
 *
 * O afiliado aprovado recebe os mesmos beneficios de quem paga o plano anual do TikTok Shop
 * (R$297), mas a assinatura nasce marcada com payment_method='affiliate_grant' para que
 * NUNCA seja confundida com receita. Todo relatorio financeiro deve excluir esse metodo.
 */
class AffiliateAccessService
{
    public const GRANT_METHOD = 'affiliate_grant';
    public const DEFAULT_PLAN_SLUG = 'tt_shop_annual';

    /** Concede (ou renova) o acesso. Idempotente: nao duplica concessao ativa. */
    public static function grant(Affiliate $affiliate, ?string $planSlug = null): ?Subscription
    {
        $client = $affiliate->user?->client;
        if (! $client) {
            Log::warning('[SEL-386] afiliado sem client — acesso nao concedido', [
                'affiliate_id' => $affiliate->id,
                'user_id'      => $affiliate->user_id,
            ]);
            return null;
        }

        $slug = $planSlug ?: ($affiliate->granted_plan_slug ?: self::DEFAULT_PLAN_SLUG);
        $plan = Plan::where('slug', $slug)->first();
        if (! $plan) {
            Log::warning('[SEL-386] plano inexistente', ['slug' => $slug]);
            return null;
        }

        $inicio = now();
        $fim    = now()->addYear();

        $sub = Subscription::where('client_id', $client->id)
            ->where('payment_method', self::GRANT_METHOD)
            ->whereNull('cancelled_at')
            ->first();

        if ($sub) {
            $sub->update([
                'plan_id'              => $plan->id,
                'status'               => 'active',
                'current_period_start' => $inicio,
                'current_period_end'   => $fim,
            ]);
        } else {
            $sub = Subscription::create([
                'client_id'            => $client->id,
                'plan_id'              => $plan->id,
                'status'               => 'active',
                'payment_method'       => self::GRANT_METHOD,
                'external_payment_id'  => null,
                'current_period_start' => $inicio,
                'current_period_end'   => $fim,
            ]);
        }

        if ($affiliate->granted_plan_slug !== $slug) {
            $affiliate->granted_plan_slug = $slug;
            $affiliate->save();
        }

        Log::info('[SEL-386] acesso de afiliado concedido', [
            'affiliate_id'    => $affiliate->id,
            'client_id'       => $client->id,
            'plan'            => $slug,
            'subscription_id' => $sub->id,
        ]);

        return $sub;
    }

    /** Revoga SOMENTE a concessao — nunca toca em assinatura paga. */
    public static function revoke(Affiliate $affiliate): int
    {
        $client = $affiliate->user?->client;
        if (! $client) {
            return 0;
        }

        $n = Subscription::where('client_id', $client->id)
            ->where('payment_method', self::GRANT_METHOD)
            ->whereNull('cancelled_at')
            ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        Log::info('[SEL-386] acesso de afiliado revogado', [
            'affiliate_id' => $affiliate->id,
            'client_id'    => $client->id,
            'revogadas'    => $n,
        ]);

        return $n;
    }
}
