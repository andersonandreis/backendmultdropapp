<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SEL-200B: bloqueia acesso a rotas TT Shop pagas se trial expirou.
 *
 * SEL-275 (2026-07-20 00:31): expandido pra bloquear tiktok_free após 3d.
 *
 * SEL-276 (2026-07-20 02:00): reforma completa — 2 componentes NOVOS:
 *   1) Push obrigatório: user sem push_activated_at → 402 stage=push_required
 *   2) Escada de preço:
 *      • 0-48h: acesso livre
 *      • 48-72h: acesso livre + banner "amanhã começa cobrança" (frontend decide)
 *      • 72-96h: 402 stage=mensal_countdown (24h pra pegar R$29,90)
 *      • 96h+: 402 stage=anual_only (só R$297)
 *
 * Decisão Ruan (AskUserQuestion 01:52):
 *   • Push preso até ativar (sem escape)
 *   • Timer 24h começa 72h após created_at (automático)
 *   • TODOS 1.400+ users existentes aplicam agora
 */
class EnsureTrialActive
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (!$user) return $next($request);

        // SEL-276 Ruan 02:05 ROLLBACK URGENTE: push_required DESATIVADO —
        // clientes aceitavam push no browser mas ficavam travados na tela
        // (push_activated_at não gravava, provável race entre subscribe e reload).
        // Deixamos push como opcional até frontend ter fluxo confiável.
        // if (empty($user->push_activated_at)) {
        //     return response()->json([
        //         'error'   => 'push_required',
        //         'stage'   => 'push_required',
        //         'message' => 'Ative as notificações pra continuar usando a plataforma.',
        //     ], 402);
        // }

        $client = $user->client ?? null;
        if (!$client) return $next($request);

        $sub = $client->subscriptions()
            ->with('plan:id,slug')
            ->whereIn('status', ['active','trialing'])
            ->latest('id')
            ->first();

        if (!$sub) return $next($request);

        $planSlug = $sub->plan?->slug;

        // Grandfathered ou já pagante — segue direto (após passar no push check)
        if ($sub->is_grandfathered || in_array($planSlug, ['tt_shop_monthly','tt_shop_annual','promo_live_297','drop_start','drop_meio','drop_top','pro','start','scaling','supplier_only'])) {
            return $next($request);
        }

        // SEL-276: escada de preço só se free tier
        if (!in_array($planSlug, ['tiktok_free','tt_shop_trial_3d'])) return $next($request);

        // SEL (08/08 Ruan): "bloquear TODOS os grátis". O tiktok_free bate no muro
        // de upgrade IMEDIATAMENTE (sem os 2 dias de carência). O trial de 3d
        // (tt_shop_trial_3d) mantém a escada abaixo pra não quebrar o funil de
        // quem acabou de entrar no teste. Reversível: é só remover este bloco.
        if (in_array($planSlug, ["tiktok_free","tt_shop_trial_3d"], true)) {
            return response()->json([
                'error'   => 'trial_expired',
                'stage'   => 'anual_only',
                'plans'   => [
                    'monthly' => ['price' => 29.90, 'url' => '/checkout/tt-shop-monthly'],
                    'annual'  => ['price' => 297.00, 'url' => '/checkout/tt-shop-annual'],
                ],
                'message' => 'O acesso grátis encerrou. Escolha um plano pra continuar criando vídeos que vendem no TikTok Shop.',
            ], 402);
        }

        $created = $user->created_at;
        if (!$created) return $next($request);

        $ageHours = now()->diffInHours($created, false);
        // diffInHours retorna negativo se $created no futuro — usa abs
        $ageHours = abs($ageHours);

        // 0-48h: acesso livre (2 dias grátis)
        if ($ageHours < 48) return $next($request);

        // 48-72h: acesso livre — frontend decide se mostra banner
        if ($ageHours < 72) {
            return $next($request);
        }

        // 72-96h: cronômetro 24h pra R$29,90
        if ($ageHours < 96) {
            return response()->json([
                'error'      => 'trial_expired',
                'stage'      => 'mensal_countdown',
                'deadline'   => $created->copy()->addHours(96)->toIso8601String(),
                'plans'      => [
                    'monthly' => ['price' => 29.90, 'url' => '/checkout/tt-shop-monthly'],
                    'annual'  => ['price' => 297.00, 'url' => '/checkout/tt-shop-annual'],
                ],
                'message'    => 'Últimas 24h pra pegar R$29,90/mês. Depois só o plano anual R$297.',
            ], 402);
        }

        // 96h+: só anual (mensal fechou)
        return response()->json([
            'error'      => 'trial_expired',
            'stage'      => 'anual_only',
            'plans'      => [
                'annual'  => ['price' => 297.00, 'url' => '/checkout/tt-shop-annual'],
            ],
            'message'    => 'Janela mensal fechada. Continue no plano anual R$297.',
        ], 402);
    }
}
