<?php

namespace App\Services\Ai;

use App\Models\User;
use App\Services\AiWalletService;
use Illuminate\Support\Facades\DB;

/**
 * SEL-329: guardião anti-prejuízo da geração de vídeo.
 *
 * Regras aplicadas em ordem:
 *   1. user.is_active = 1 (senão bloqueia)
 *   2. subscription do user tem plano == plan_required_slug (settings)
 *      E status ∈ (active, trialing) E pagarme_subscription_id NOT NULL
 *   3. sum(cost_brl) das gerações video/video-perfect deste user
 *      neste MÊS calendário + custo desta geração ≤ internal_cost_cap_brl_month
 *      (senão bloqueia se o wallet não cobre)
 *   4. Se estourou o cap: saldo wallet ≥ créditos_desta_geração?
 *      OK debita wallet; senão 402.
 *
 * Zero confiança no cliente: consulta banco pra tudo.
 */
class VideoBillingGate
{
    /** @return array{allowed:bool, reason?:string, http?:int, message?:string, cost_brl?:float, credits?:int, used_this_month_brl?:float, source?:string} */
    public static function check(User $user, string $quality, int $durationSec): array
    {
        // Se cobrança está desligada globalmente, libera sem gate (admin em teste)
        $settings = self::settings();
        if (($settings['billing_enabled'] ?? '0') !== '1') {
            return ['allowed' => true, 'source' => 'billing_disabled'];
        }

        // 1. usuário ativo
        if (!$user->is_active) {
            return ['allowed' => false, 'http' => 403, 'reason' => 'user_blocked',
                'message' => 'Conta bloqueada. Fale com o suporte.'];
        }

        // super_admin não passa por gate (Ruan testa infinito)
        if (in_array($user->role, ['admin', 'super_admin'], true)) {
            return ['allowed' => true, 'source' => 'admin_bypass'];
        }

        // 2. plano
        $client = DB::table('clients')->where('user_id', $user->id)->first();
        if (!$client) {
            return ['allowed' => false, 'http' => 403, 'reason' => 'no_client',
                'message' => 'Cadastro incompleto — complete seus dados antes de gerar.'];
        }
        $sub = DB::table('subscriptions')
            ->where('client_id', $client->id)
            ->whereIn('status', ['active', 'trialing'])
            ->orderByDesc('id')
            ->first();
        if (!$sub) {
            return ['allowed' => false, 'http' => 403, 'reason' => 'no_subscription',
                'message' => 'Assine o plano Pro pra gerar vídeos.'];
        }
        $requiredSlug = $settings['plan_required_slug'] ?? 'pro';
        $plan = DB::table('plans')->where('id', $sub->plan_id)->first();
        if (!$plan || $plan->slug !== $requiredSlug) {
            return ['allowed' => false, 'http' => 403, 'reason' => 'wrong_plan',
                'message' => 'Seu plano atual não libera geração de vídeo. Faça upgrade pro Pro.'];
        }
        if (empty($sub->pagarme_subscription_id)) {
            return ['allowed' => false, 'http' => 403, 'reason' => 'subscription_not_paid',
                'message' => 'Sua assinatura Pro ainda não foi paga. Finalize o pagamento pra liberar.'];
        }

        // 3. cap mensal (custo interno)
        $costMap = json_decode((string) ($settings['internal_cost_by_quality_brl_per_s'] ?? '{}'), true) ?: [];
        $creditsMap = json_decode((string) ($settings['credits_by_quality_per_s'] ?? '{}'), true) ?: [];
        if (!isset($costMap[$quality]) || !isset($creditsMap[$quality])) {
            return ['allowed' => false, 'http' => 422, 'reason' => 'invalid_quality',
                'message' => 'Qualidade inválida.'];
        }
        $costThisGeneration = (float) $costMap[$quality] * $durationSec;
        $creditsThisGeneration = (int) round((float) $creditsMap[$quality] * $durationSec);

        // ai_generations só tem cost_usd — converte via usd_brl_rate (settings)
        $rate = (float) ($settings['usd_brl_rate'] ?? 5.50);
        $usedUsd = (float) DB::table('ai_generations')
            ->where('user_id', $user->id)
            ->where('service', 'video')
            ->whereIn('status', ['succeeded', 'processing'])
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('cost_usd');
        $usedThisMonth = $usedUsd * $rate;

        $cap = (float) ($settings['internal_cost_cap_brl_month'] ?? 50.00);

        // Fica dentro do cap? → cobra do plano (grátis pro cliente, contador interno)
        if ($usedThisMonth + $costThisGeneration <= $cap) {
            return [
                'allowed' => true,
                'source' => 'plan_included',
                'cost_brl' => round($costThisGeneration, 4),
                'credits' => $creditsThisGeneration,
                'used_this_month_brl' => round($usedThisMonth, 2),
            ];
        }

        // 4. Passou do cap → precisa saldo no wallet
        $balance = AiWalletService::getBalance($client->id);
        if ($balance >= $creditsThisGeneration) {
            return [
                'allowed' => true,
                'source' => 'wallet_credits',
                'cost_brl' => round($costThisGeneration, 4),
                'credits' => $creditsThisGeneration,
                'used_this_month_brl' => round($usedThisMonth, 2),
                'wallet_balance_before' => $balance,
            ];
        }

        return [
            'allowed' => false,
            'http' => 402,
            'reason' => 'monthly_cap_reached',
            'message' => 'Você atingiu o limite mensal do plano. Compre um pacote de créditos pra continuar.',
            'used_this_month_brl' => round($usedThisMonth, 2),
            'cap_brl' => $cap,
            'wallet_balance' => $balance,
            'credits_needed' => $creditsThisGeneration,
        ];
    }

    /** Debita crédito do wallet quando gerado (chamar SÓ se check() retornou source=wallet_credits). */
    public static function debitWallet(int $clientId, int $credits, ?string $ref = null): float
    {
        return AiWalletService::debit($clientId, (float) $credits, 'video_generation', $ref, 'Geração de vídeo (fora do plano)');
    }

    /** Configs cacheadas em memória por request. */
    private static ?array $cache = null;
    private static function settings(): array
    {
        if (self::$cache !== null) return self::$cache;
        return self::$cache = DB::table('settings')
            ->where('group', 'video_billing')
            ->pluck('value', 'key')
            ->toArray();
    }
}
