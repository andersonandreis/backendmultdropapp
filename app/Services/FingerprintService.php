<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SEL-200B: analisa fingerprint pra detectar múltiplas contas do mesmo dispositivo.
 * Score 0-100 — 0 = limpo, 100 = certeza absoluta que é bot/repeat.
 *
 * Chamado no POST /api/register quando plano == tt_shop_trial_3d.
 */
class FingerprintService
{
    public function analyze(Request $request, array $clientFp = []): array
    {
        $ip = $request->ip();
        $ua = $request->userAgent() ?? '';
        $lang = $request->header('Accept-Language') ?? '';
        $browserFp = $clientFp['browser_fp'] ?? null;
        $pushEndpointHash = isset($clientFp['push_endpoint']) ? substr(hash('sha256', $clientFp['push_endpoint']), 0, 64) : null;
        $timezone = $clientFp['timezone'] ?? null;

        $flags = [];
        $score = 0;

        // 1. IP repeat 30d
        $ipCount = DB::table('signup_fingerprints')
            ->where('ip_address', $ip)
            ->where('created_at', '>', now()->subDays(30))
            ->count();
        if ($ipCount >= 3) { $score += 40; $flags[] = 'ip_repeat_30d'; }
        elseif ($ipCount >= 1) { $score += 15; $flags[] = 'ip_seen_recent'; }

        // 2. Browser FP repeat 30d
        if ($browserFp) {
            $fpCount = DB::table('signup_fingerprints')
                ->where('browser_fp', $browserFp)
                ->where('created_at', '>', now()->subDays(30))
                ->count();
            if ($fpCount >= 1) { $score += 45; $flags[] = 'browser_fp_repeat'; }
        }

        // 3. Push endpoint repeat
        if ($pushEndpointHash) {
            $pushCount = DB::table('signup_fingerprints')
                ->where('push_endpoint_hash', $pushEndpointHash)
                ->count();
            if ($pushCount >= 1) { $score += 30; $flags[] = 'push_endpoint_repeat'; }
        }

        // 4. UA suspeita (bot)
        if (preg_match('/curl|wget|python|bot|crawler|http/i', $ua)) { $score += 30; $flags[] = 'bot_ua'; }
        if ($ua === '' || strlen($ua) < 20) { $score += 20; $flags[] = 'missing_ua'; }

        return [
            'score' => min(100, $score),
            'flags' => $flags,
            'ip' => $ip,
            'browser_fp' => $browserFp,
            'push_endpoint_hash' => $pushEndpointHash,
            'user_agent' => $ua,
            'accept_language' => $lang,
            'timezone' => $timezone,
        ];
    }

    public function persist(?int $userId, array $analysis): void
    {
        DB::table('signup_fingerprints')->insert([
            'user_id' => $userId,
            'ip_address' => $analysis['ip'],
            'browser_fp' => $analysis['browser_fp'],
            'push_endpoint_hash' => $analysis['push_endpoint_hash'],
            'user_agent' => $analysis['user_agent'],
            'accept_language' => $analysis['accept_language'],
            'timezone' => $analysis['timezone'],
            'flags' => json_encode($analysis['flags']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
