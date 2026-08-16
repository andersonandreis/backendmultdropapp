<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SEL-CONVITE Fase A — regras do trial fechado (/convite).
 *
 * FONTE DA VERDADE 100% server-side. O front so exibe cronometro/oferta; toda
 * decisao (é trial? já gerou o 1? passou 24h? email/fingerprint/ip consumiu?)
 * é resolvida aqui.
 *
 * Reusa a infra anti-fraude que ja existe: device_fingerprints / IP.
 * Limitacao honesta: nao le MAC real (privacy sandbox). fingerprint composto
 * ~99% unico por maquina — deter casual, nao VPN+incognito+outro aparelho.
 */
class InviteTrialService
{
    public const TRIAL_HOURS       = 24;
    public const MAX_PER_FP        = 1;   // 1 trial por device
    public const MAX_PER_IP_24H    = 3;   // tolera NAT (escritorio/4G)

    /* ---------------- settings (group=convite) ---------------- */

    public static function setting(string $key, $default = null)
    {
        $v = DB::table('settings')->where('group', 'convite')->where('key', $key)->value('value');
        return $v ?? $default;
    }

    public static function mode(): string
    {
        return self::setting('mode', 'waitlist') === 'open' ? 'open' : 'waitlist';
    }

    public static function dailyCap(): int
    {
        return (int) self::setting('daily_cap', '50');
    }

    public static function offer(): array
    {
        $d = ['label' => 'Plano Ultra', 'price' => 'R$297', 'url' => '/planos'];
        $j = self::setting('offer_json');
        if (! $j) return $d;
        $p = json_decode($j, true);
        return is_array($p) ? array_merge($d, $p) : $d;
    }

    public static function trialVideosToday(): int
    {
        return DB::table('trial_invites')->whereNotNull('video_used_at')->whereDate('video_used_at', today())->count();
    }

    /* ---------------- elegibilidade ---------------- */

    /** Usuario ja pagante / admin — clica /convite e entra NORMAL (nao vira trial). */
    public static function isPaying(User $user): bool
    {
        if (in_array($user->role, ['admin', 'super_admin'], true)) return true;

        $client = DB::table('clients')->where('user_id', $user->id)->first(['id']);
        if (! $client) return false;

        $sub = DB::table('subscriptions as s')
            ->leftJoin('plans as p', 'p.id', '=', 's.plan_id')
            ->where('s.client_id', $client->id)
            ->where('s.status', 'active')
            ->orderByDesc('s.id')
            ->first(['s.plan_id', 'p.slug', 'p.price_monthly', 'p.price_yearly']);

        if (! $sub || ! $sub->plan_id) return false;

        return ((float) ($sub->price_monthly ?? 0)) > 0
            || ((float) ($sub->price_yearly ?? 0)) > 0
            || in_array((string) $sub->slug, ['tt_shop_annual', 'drop_start', 'drop_meio', 'drop_top'], true);
    }

    public static function isAffiliate(int $userId): bool
    {
        return DB::table('affiliates')->where('user_id', $userId)
            ->where('approval_status', 'approved')->where('status', 'active')->exists();
    }

    /** Email ja QUEIMOU o trial (expirado/consumido) — re-login cai na parede de upgrade. */
    public static function consumedEmail(string $email): bool
    {
        return DB::table('trial_invites')->where('email', strtolower($email))
            ->whereIn('status', ['expired', 'consumed'])->exists();
    }

    public static function fingerprintTrialsUsed(?string $fp): int
    {
        if (! $fp) return 0;
        return DB::table('trial_invites')->where('fingerprint_hash', $fp)->count();
    }

    public static function ipTrials24h(?string $ip): int
    {
        if (! $ip) return 0;
        return DB::table('trial_invites')->where('signup_ip', $ip)->where('created_at', '>=', now()->subDay())->count();
    }

    /* ---------------- ciclo de vida do trial ---------------- */

    /** Trial ATIVO e nao-expirado do usuario. Expira preguicosamente se venceu. */
    public static function activeTrial(?int $userId): ?object
    {
        if (! $userId) return null;
        $t = DB::table('trial_invites')->where('user_id', $userId)
            ->where('status', 'active')->orderByDesc('id')->first();
        if (! $t) return null;

        if ($t->expires_at && Carbon::parse($t->expires_at)->isPast()) {
            DB::table('trial_invites')->where('id', $t->id)
                ->update(['status' => 'expired', 'consumed_reason' => '24h', 'updated_at' => now()]);
            return null;
        }
        return $t;
    }

    public static function isTrialActive(?int $userId): bool
    {
        return self::activeTrial($userId) !== null;
    }

    /** Info pro /me: cronometro + estado. Null se o usuario nunca teve trial. */
    public static function trialInfoFor(int $userId): ?array
    {
        $t = DB::table('trial_invites')->where('user_id', $userId)->orderByDesc('id')->first();
        if (! $t) return null;

        $past    = $t->expires_at ? Carbon::parse($t->expires_at)->isPast() : true;
        $expired = $t->status !== 'active' || $past;

        if ($t->status === 'active' && $past) {
            DB::table('trial_invites')->where('id', $t->id)
                ->update(['status' => 'expired', 'consumed_reason' => '24h', 'updated_at' => now()]);
        }

        return [
            'kind'        => 'convite',
            'active'      => ! $expired,
            'expired'     => $expired,
            'expires_at'  => $t->expires_at ? Carbon::parse($t->expires_at)->toIso8601String() : null,
            'video_used'  => ! is_null($t->video_used_at),
            'video_limit' => 1,
        ];
    }

    public static function startTrial(int $userId, string $email, ?string $fp, ?string $ip): object
    {
        $now     = now();
        $expires = $now->copy()->addHours(self::TRIAL_HOURS);
        $id = DB::table('trial_invites')->insertGetId([
            'user_id'          => $userId,
            'email'            => strtolower($email),
            'fingerprint_hash' => $fp,
            'signup_ip'        => $ip,
            'started_at'       => $now,
            'expires_at'       => $expires,
            'status'           => 'active',
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
        return DB::table('trial_invites')->where('id', $id)->first();
    }

    /**
     * CLAIM atomico do 1 video. Chamado no barrarPipeline (nivel job), amarrado
     * ao pipelineId. Retorna true so se ESTA chamada foi quem reservou (1 linha).
     * Corrida de 2 geracoes: so a 1a ganha; a 2a recebe false -> bloqueada.
     */
    public static function claimVideo(int $trialId, int $pipelineId): bool
    {
        $affected = DB::table('trial_invites')->where('id', $trialId)
            ->whereNull('video_used_at')
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->update(['video_used_at' => now(), 'video_pipeline_id' => $pipelineId, 'updated_at' => now()]);
        return $affected === 1;
    }

    public static function expireAndRevoke(User $user): void
    {
        DB::table('trial_invites')->where('user_id', $user->id)->where('status', 'active')
            ->update(['status' => 'expired', 'consumed_reason' => '24h', 'updated_at' => now()]);
        // Derruba a sessao do trial (tokens de entrada do cliente).
        try { $user->tokens()->delete(); } catch (\Throwable $e) { /* ignore */ }
    }

    /* ---------------- waitlist ---------------- */

    public static function addToWaitlist(string $email, ?string $fp, ?string $ip): void
    {
        $email = strtolower($email);
        if (DB::table('convite_waitlist')->where('email', $email)->exists()) return;
        DB::table('convite_waitlist')->insert([
            'email' => $email, 'fingerprint_hash' => $fp, 'ip' => $ip,
            'status' => 'waiting', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
