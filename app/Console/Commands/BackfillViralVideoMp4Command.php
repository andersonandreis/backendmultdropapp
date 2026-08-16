<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SEL-319: Backfill de vídeos MP4 dos virais TikTok.
 *
 * Fluxo:
 *  1. Busca cada video sem play_url local (nao contém /storage/tt-media)
 *  2. Resolve URL fresca via tikwm.com/api/?url=<tiktok_url>&hd=1
 *  3. Baixa o MP4 pra /storage/tt-media/viralvid_ext_{id}.mp4
 *  4. Atualiza play_url_hd no banco com URL local
 *  5. Throttle de 1s entre downloads pra nao sobrecarregar tikwm
 *
 * Uso:
 *   php artisan sel:backfill-viral-mp4 [--limit=100] [--dry-run]
 *
 * Logs em storage/logs/backfill-viral-mp4-YYYY-MM-DD.log
 */
class BackfillViralVideoMp4Command extends Command
{
    protected $signature   = 'sel:backfill-viral-mp4
                                {--limit=0 : Max videos a processar (0 = todos)}
                                {--dry-run : Simula sem baixar nem gravar}';
    protected $description = 'SEL-319: Baixa mp4 dos virais TikTok pra storage local';

    private const TIKWM_BASE  = 'https://www.tikwm.com/api/';
    private const SLEEP_MS    = 1_000_000; // 1s entre downloads
    private const MIN_MP4_SIZE = 10_000;   // 10KB mínimo pra considerar válido

    public function handle(): int
    {
        if (config('app.tenant') !== 'sellerglobal') {
            $this->error('[INF-BKP] Comando exclusivo do tenant sellerglobal. Atual: ' . config('app.tenant', 'null'));
            return 1;
        }
        $limit  = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');
        $logFile = storage_path('logs/backfill-viral-mp4-' . now()->format('Y-m-d') . '.log');

        $this->log($logFile, "[SEL-319] Iniciando backfill mp4. limit={$limit} dry_run=" . ($dryRun ? 'true' : 'false'));
        $this->info("[SEL-319] Backfill MP4 — limit={$limit} dry_run=" . ($dryRun ? 'yes' : 'no'));

        $dir = storage_path('app/public/tt-media');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        // Query: videos sem URL local de mp4
        $query = DB::table('tiktok_viral_videos')
            ->where(function ($q) {
                $q->whereNull('play_url_hd')
                  ->orWhere('play_url_hd', 'NOT LIKE', '%/storage/tt-media%');
            })
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $total   = $query->count();
        $this->info("Encontrados {$total} videos sem mp4 local.");
        $this->log($logFile, "Total pendente: {$total}");

        if ($dryRun) {
            $this->info('dry-run: abortando sem baixar nada.');
            return 0;
        }

        $rows     = $query->get(['id', 'external_video_id', 'video_url', 'play_url_hd']);
        $ok       = 0;
        $failed   = 0;
        $skipped  = 0;

        foreach ($rows as $row) {
            $safeId  = preg_replace('/[^A-Za-z0-9_-]/', '', $row->external_video_id);
            $mp4File = "{$dir}/viralvid_ext_{$safeId}.mp4";
            $localUrl = rtrim(config('app.url'), '/') . "/storage/tt-media/viralvid_ext_{$safeId}.mp4";

            // Já existe local?
            if (is_file($mp4File) && filesize($mp4File) > self::MIN_MP4_SIZE) {
                DB::table('tiktok_viral_videos')
                    ->where('id', $row->id)
                    ->update(['play_url_hd' => $localUrl, 'updated_at' => now()]);
                $skipped++;
                $this->log($logFile, "[skip-exists] id={$row->id} ext={$row->external_video_id}");
                continue;
            }

            // Resolve URL fresca via tikwm
            $freshUrl = $this->resolveUrlViaTikwm($row->video_url, $logFile, $row->id);

            if (!$freshUrl) {
                $failed++;
                $this->log($logFile, "[failed-resolve] id={$row->id} ext={$row->external_video_id}");
                usleep(self::SLEEP_MS);
                continue;
            }

            // Baixa o mp4
            try {
                $videoRes = Http::timeout(90)
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (compatible; HubAI-SEL319/1.0)',
                        'Referer'    => 'https://www.tikwm.com/',
                    ])
                    ->get($freshUrl);

                $body = $videoRes->successful() ? $videoRes->body() : '';

                if (strlen($body) < self::MIN_MP4_SIZE) {
                    $failed++;
                    $this->log($logFile, "[failed-download] id={$row->id} size=" . strlen($body));
                    usleep(self::SLEEP_MS);
                    continue;
                }

                file_put_contents($mp4File, $body);

                DB::table('tiktok_viral_videos')
                    ->where('id', $row->id)
                    ->update(['play_url_hd' => $localUrl, 'updated_at' => now()]);

                $ok++;
                $sizeMb = round(strlen($body) / 1_048_576, 1);
                $this->log($logFile, "[ok] id={$row->id} ext={$row->external_video_id} size={$sizeMb}MB");

                if ($ok % 10 === 0) {
                    $this->info("Progresso: {$ok} ok | {$failed} falhas | {$skipped} ja existiam");
                }

            } catch (\Throwable $e) {
                $failed++;
                $this->log($logFile, "[exception] id={$row->id} err=" . $e->getMessage());
            }

            usleep(self::SLEEP_MS);
        }

        $summary = "Concluído: {$ok} ok | {$failed} falhas | {$skipped} ja existiam | total={$total}";
        $this->info($summary);
        $this->log($logFile, "[done] {$summary}");

        return 0;
    }

    /**
     * Resolve URL de download fresca via tikwm.com/api/?url=<tiktokUrl>&hd=1.
     * Retorna play URL ou null em caso de falha.
     */
    private function resolveUrlViaTikwm(string $tiktokUrl, string $logFile, int $rowId): ?string
    {
        try {
            $res = Http::timeout(20)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; HubAI-SEL319/1.0)',
                    'Referer'    => 'https://www.tikwm.com/',
                ])
                ->get(self::TIKWM_BASE, [
                    'url' => $tiktokUrl,
                    'hd'  => 1,
                ]);

            if (!$res->successful()) {
                $this->log($logFile, "[tikwm-http-err] id={$rowId} status=" . $res->status());
                return null;
            }

            $body = $res->json();
            if (($body['code'] ?? -1) !== 0) {
                $this->log($logFile, "[tikwm-code-err] id={$rowId} code=" . ($body['code'] ?? 'null') . ' msg=' . ($body['msg'] ?? ''));
                return null;
            }

            $data = $body['data'] ?? [];
            return $data['hdplay'] ?? $data['play'] ?? null;

        } catch (\Throwable $e) {
            $this->log($logFile, "[tikwm-exception] id={$rowId} err=" . $e->getMessage());
            return null;
        }
    }

    private function log(string $file, string $msg): void
    {
        $line = '[' . now()->format('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
        file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }
}
