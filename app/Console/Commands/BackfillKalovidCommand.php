<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * SEL-321/D7: baixa mp4 dos videos referenciados nos payloads kalodata
 * type=products (video_url tiktokcdn assinada expira em horas) pra
 * /storage/tt-media/kalovid_{video_id}.mp4.
 *
 * Uso: php artisan tiktok:backfill-kalovid [--limit=0]
 * Log: storage/logs/backfill-kalovid-YYYY-MM-DD.log
 */
class BackfillKalovidCommand extends Command
{
    protected $signature   = 'tiktok:backfill-kalovid {--limit=0 : Max videos (0 = todos)}';
    protected $description = 'SEL-321: persiste mp4 local dos videos dos payloads kalodata products';

    private const MIN_MP4_SIZE = 10_000;

    public function handle(): int
    {
        if (config('app.tenant') !== 'sellerglobal') {
            $this->error('[INF-BKP] Comando exclusivo do tenant sellerglobal. Atual: ' . config('app.tenant', 'null'));
            return 1;
        }
        $logFile = storage_path('logs/backfill-kalovid-' . now()->format('Y-m-d') . '.log');
        $dir = storage_path('app/public/tt-media');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $videos = [];
        foreach (DB::table('kalodata_raw')->where('type', 'products')->get(['payload']) as $r) {
            $p = json_decode($r->payload, true) ?: [];
            if (!empty($p['video_id'])) {
                $videos[(string) $p['video_id']] = $p['video_url'] ?? null;
            }
        }
        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $videos = array_slice($videos, 0, $limit, true);
        }
        $this->info('Videos distintos: ' . count($videos));

        $ok = $skip = $fail = 0;
        foreach ($videos as $videoId => $rawUrl) {
            $safeId  = preg_replace('/[^A-Za-z0-9_-]/', '', $videoId);
            $mp4File = "{$dir}/kalovid_{$safeId}.mp4";
            if (is_file($mp4File) && filesize($mp4File) > self::MIN_MP4_SIZE) {
                $skip++;
                continue;
            }

            $body = $rawUrl ? $this->download($rawUrl) : '';
            if (strlen($body) < self::MIN_MP4_SIZE) {
                $fresh = $this->freshUrlViaTikwm($videoId);
                $body  = $fresh ? $this->download($fresh) : '';
            }
            if (strlen($body) < self::MIN_MP4_SIZE) {
                $fail++;
                file_put_contents($logFile, now()->toDateTimeString() . " [fail] {$videoId}\n", FILE_APPEND);
                usleep(1_000_000);
                continue;
            }
            file_put_contents($mp4File, $body);
            @chmod($mp4File, 0664);
            $ok++;
            file_put_contents($logFile, now()->toDateTimeString() . " [ok] {$videoId} " . strlen($body) . " bytes\n", FILE_APPEND);
            usleep(1_000_000);
        }

        $this->info("ok={$ok} skip={$skip} fail={$fail}");
        return 0;
    }

    private function download(string $url): string
    {
        try {
            $res = Http::timeout(90)->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; HubAI-SEL321/1.0)',
                'Referer'    => 'https://www.tiktok.com/',
            ])->get($url);
            return $res->successful() ? $res->body() : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function freshUrlViaTikwm(string $videoId): ?string
    {
        try {
            $res = Http::timeout(15)->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; HubAI-SEL321/1.0)',
                'Referer'    => 'https://www.tikwm.com/',
            ])->get('https://www.tikwm.com/api/', [
                'url' => "https://www.tiktok.com/@tiktok/video/{$videoId}",
                'hd'  => 1,
            ]);
            if ($res->successful() && ($res->json('code') ?? -1) === 0) {
                $d = $res->json('data') ?? [];
                return $d['hdplay'] ?? ($d['play'] ?? null);
            }
        } catch (\Throwable $e) {
            // silencio: fallback esgotado, caller loga o fail
        }
        return null;
    }
}
