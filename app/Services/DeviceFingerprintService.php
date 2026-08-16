<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SEL-227 Ruan 18/07/2026 — anti-fraude device fingerprint.
 *
 * Grava fingerprint composto (canvas + WebGL + hardware + UA) na
 * `device_fingerprints`. Bloqueia cadastro se:
 *   1. mesmo fingerprint ja criou 3+ contas nas ultimas 24h (fake mass signup)
 *   2. IP em range conhecido de datacenter (AWS/GCP/Azure — provavelmente bot)
 *   3. headless detectado no client (webdriver=true, plugins vazios)
 *
 * Rate limit adicional por IP: max 5 cadastros/24h independente do hash.
 */
class DeviceFingerprintService
{
    // Prefixos conhecidos de datacenter (subset — expandir com maxmind se necessário)
    private const DATACENTER_PREFIXES = [
        '3.',    '13.',   '15.',   '18.',   '34.',   '35.',   '52.',   '54.',  // AWS
        '104.196.', '104.197.', '104.198.', '104.199.', '35.184.', '35.185.',   // GCP
        '20.',   '40.',   '52.146.', '52.147.', '52.148.', '52.149.', '52.150.', // Azure
        '138.68.', '167.99.', '178.62.', '188.166.',                             // DigitalOcean
        '104.131.', '159.203.', '162.243.',                                       // DO extra
        '45.55.',  '45.79.',  '172.104.',                                         // Linode
    ];

    public static function isDatacenterIp(?string $ip): bool
    {
        if (!$ip) return false;
        foreach (self::DATACENTER_PREFIXES as $prefix) {
            if (str_starts_with($ip, $prefix)) return true;
        }
        return false;
    }

    /**
     * Grava evento de fingerprint. Retorna array com:
     *   ['blocked' => bool, 'reason' => ?string, 'fingerprint_hash' => string]
     */
    public static function record(Request $request, array $client, string $event = 'heartbeat', ?int $userId = null): array
    {
        $ip = $request->ip();
        $ipForwarded = $request->header('X-Forwarded-For');
        $userAgent = $request->userAgent() ?? '';

        // Compõe hash canonicamente
        $canvas = (string) ($client['canvas'] ?? '');
        $webgl = (string) ($client['webgl'] ?? '');
        $screen = (string) ($client['screen'] ?? '');
        $platform = (string) ($client['platform'] ?? '');
        $timezone = (string) ($client['timezone'] ?? '');
        $language = (string) ($client['language'] ?? '');
        $hwc = (int) ($client['hardwareConcurrency'] ?? 0);
        $devMem = (int) ($client['deviceMemory'] ?? 0);
        $isHeadless = (bool) ($client['isHeadless'] ?? false);

        $canvasHash = $canvas ? hash('sha256', $canvas) : null;
        $webglHash = $webgl ? hash('sha256', $webgl) : null;
        $screenHash = $screen ? hash('sha256', $screen) : null;

        $composite = implode('|', [
            $canvasHash ?? '-',
            $webglHash ?? '-',
            $screenHash ?? '-',
            $platform,
            $hwc,
            $devMem,
            $userAgent,
        ]);
        $fingerprintHash = hash('sha256', $composite);

        $isDatacenter = self::isDatacenterIp($ip);

        // Reason for blocking (só quando event=register)
        $blocked = false;
        $reason = null;

        if ($event === 'register') {
            // 1. Mesmo fingerprint criou 3+ contas nas ultimas 24h?
            $count = DB::table('device_fingerprints')
                ->where('fingerprint_hash', $fingerprintHash)
                ->where('event', 'register')
                ->where('created_at', '>=', now()->subDay())
                ->count();
            if ($count >= 3) {
                $blocked = true;
                $reason = 'device_limit_exceeded';
            }
            // 2. IP em datacenter
            elseif ($isDatacenter) {
                $blocked = true;
                $reason = 'datacenter_ip';
            }
            // 3. Headless flag do client
            elseif ($isHeadless) {
                $blocked = true;
                $reason = 'headless_browser';
            }
            // 4. Rate limit IP: max 5 registros/24h independente do hash
            else {
                $ipCount = DB::table('device_fingerprints')
                    ->where('ip', $ip)
                    ->where('event', 'register')
                    ->where('created_at', '>=', now()->subDay())
                    ->count();
                if ($ipCount >= 5) {
                    $blocked = true;
                    $reason = 'ip_rate_limit';
                }
            }
        }

        // Grava sempre (mesmo bloqueado — pra ver o padrão depois)
        DB::table('device_fingerprints')->insert([
            'user_id'              => $userId,
            'ip'                   => $ip,
            'ip_forwarded'         => $ipForwarded ? substr($ipForwarded, 0, 255) : null,
            'fingerprint_hash'     => $fingerprintHash,
            'canvas_hash'          => $canvasHash,
            'webgl_hash'           => $webglHash,
            'screen_hash'          => $screenHash,
            'user_agent'           => $userAgent,
            'platform'             => $platform ?: null,
            'language'             => $language ?: null,
            'timezone'             => $timezone ?: null,
            'hardware_concurrency' => $hwc ?: null,
            'device_memory'        => $devMem ?: null,
            'is_headless'          => $isHeadless,
            'is_datacenter_ip'     => $isDatacenter,
            'event'                => $event,
            'first_seen_at'        => now(),
            'last_seen_at'         => now(),
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        return [
            'blocked' => $blocked,
            'reason' => $reason,
            'fingerprint_hash' => $fingerprintHash,
        ];
    }
}
