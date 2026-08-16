<?php

namespace App\Console\Commands;

use App\Services\TikTokMediaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SEL-308 — Backfill idempotente de midia TikTok para storage proprio.
 *
 * Varre kalodata_raw e tt_shop_raw, detecta URLs assinadas expiradas,
 * baixa a midia via tikwm (server-side) e atualiza o payload com URL local.
 *
 * Uso:
 *   php artisan tt:media-localize                   (todos os tipos, ultimo snapshot)
 *   php artisan tt:media-localize --type=products   (so produtos)
 *   php artisan tt:media-localize --dry             (nao grava)
 *   php artisan tt:media-localize --limit=20        (max por tipo)
 */
class TtMediaLocalizeCommand extends Command
{
    protected $signature = 'tt:media-localize
                            {--type= : Tipo especifico: products|creators|videos|lives|shops (omitir = todos)}
                            {--limit=200 : Max de registros a processar por tipo}
                            {--dry : Nao grava alteracoes, so reporta}
                            {--force : Re-processa mesmo registros que ja tem URL local}';

    protected $description = 'SEL-308: Persiste midia TikTok (covers de produto/creator/live) no storage proprio';

    public function __construct(private TikTokMediaService $media)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $typeFilter = $this->option('type');
        $limit      = (int) $this->option('limit');
        $dry        = (bool) $this->option('dry');
        $force      = (bool) $this->option('force');

        if ($dry) $this->warn('[DRY RUN] Nenhuma alteracao sera gravada.');

        $totals = ['processed' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];

        // --- kalodata_raw / products ---
        if (!$typeFilter || $typeFilter === 'products') {
            $t = $this->processKalodataProducts($limit, $dry, $force);
            $this->mergeTotals($totals, $t);
        }

        // --- kalodata_raw / creators ---
        if (!$typeFilter || $typeFilter === 'creators') {
            $t = $this->processKalodataCreators($limit, $dry, $force);
            $this->mergeTotals($totals, $t);
        }

        // --- kalodata_raw / lives ---
        if (!$typeFilter || $typeFilter === 'lives') {
            $t = $this->processKalodataLives($limit, $dry, $force);
            $this->mergeTotals($totals, $t);
        }

        // --- tt_shop_raw / product ---
        if (!$typeFilter || $typeFilter === 'tt_shop') {
            $t = $this->processTtShopProducts($limit, $dry, $force);
            $this->mergeTotals($totals, $t);
        }

        $this->newLine();
        $this->info(sprintf(
            '=== SEL-308 tt:media-localize concluido%s === processados=%d updated=%d skipped=%d failed=%d',
            $dry ? ' (DRY)' : '',
            $totals['processed'],
            $totals['updated'],
            $totals['skipped'],
            $totals['failed']
        ));

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Produtos kalodata_raw
    // -------------------------------------------------------------------------

    private function processKalodataProducts(int $limit, bool $dry, bool $force): array
    {
        $this->info('--- kalodata_raw / products ---');
        $date = DB::table('kalodata_raw')->where('type', 'products')->max('snapshot_date');
        if (!$date) { $this->warn('Sem snapshot de products'); return $this->emptyTotals(); }

        $query = DB::table('kalodata_raw')
            ->where('type', 'products')
            ->where('snapshot_date', $date)
            ->orderBy('id')
            ->limit($limit);

        $totals = $this->emptyTotals();

        foreach ($query->get() as $row) {
            $totals['processed']++;
            $p = json_decode($row->payload, true);
            if (!is_array($p)) { $totals['skipped']++; continue; }

            $imgUrl   = $p['image_url'] ?? null;
            $needsImg = $force || $this->needsLocalize($imgUrl);

            if (!$needsImg) { $totals['skipped']++; continue; }

            $hints = [
                'video_id'   => $p['video_id'] ?? null,
                'tiktok_url' => $p['tiktok_url'] ?? null,
            ];

            $local = $this->media->ensureLocal($imgUrl, $hints);

            if (!$local) {
                $totals['failed']++;
                $this->line("[products #{$row->id}] FAIL — sem URL resolvel");
                continue;
            }

            $this->line("[products #{$row->id}] " . mb_substr($p['product_title'] ?? '', 0, 40) . " => " . substr($local, 0, 80));
            $p['image_url'] = $local;

            if (!$dry) {
                DB::table('kalodata_raw')->where('id', $row->id)->update([
                    'payload'    => json_encode($p, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
            }
            $totals['updated']++;
            usleep(300_000); // 300ms entre requests
        }

        return $totals;
    }

    // -------------------------------------------------------------------------
    // Criadores kalodata_raw
    // -------------------------------------------------------------------------

    private function processKalodataCreators(int $limit, bool $dry, bool $force): array
    {
        $this->info('--- kalodata_raw / creators ---');
        $date = DB::table('kalodata_raw')->where('type', 'creators')->max('snapshot_date');
        if (!$date) { $this->warn('Sem snapshot de creators'); return $this->emptyTotals(); }

        $totals = $this->emptyTotals();

        $rows = DB::table('kalodata_raw')
            ->where('type', 'creators')
            ->where('snapshot_date', $date)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($rows as $row) {
            $totals['processed']++;
            $p = json_decode($row->payload, true);
            if (!is_array($p)) { $totals['skipped']++; continue; }

            $avatarUrl  = $p['avatar'] ?? null;
            $needsLocal = $force || $this->needsLocalize($avatarUrl);

            if (!$needsLocal) { $totals['skipped']++; continue; }

            $handle = $p['handle'] ?? null;
            $local  = $this->media->ensureLocal($avatarUrl, ['handle' => $handle]);

            if (!$local) {
                $totals['failed']++;
                $this->line("[creators #{$row->id}] FAIL handle=" . ($handle ?? 'null'));
                continue;
            }

            $this->line("[creators #{$row->id}] @{$handle} => " . substr($local, 0, 80));
            $p['avatar'] = $local;

            if (!$dry) {
                DB::table('kalodata_raw')->where('id', $row->id)->update([
                    'payload'    => json_encode($p, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
            }
            $totals['updated']++;
            usleep(1_200_000); // 1.2s para tikwm user/info rate limit
        }

        return $totals;
    }

    // -------------------------------------------------------------------------
    // Lives kalodata_raw
    // -------------------------------------------------------------------------

    private function processKalodataLives(int $limit, bool $dry, bool $force): array
    {
        $this->info('--- kalodata_raw / lives ---');
        $date = DB::table('kalodata_raw')->where('type', 'lives')->max('snapshot_date');
        if (!$date) { $this->warn('Sem snapshot de lives'); return $this->emptyTotals(); }

        $totals = $this->emptyTotals();

        $rows = DB::table('kalodata_raw')
            ->where('type', 'lives')
            ->where('snapshot_date', $date)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($rows as $row) {
            $totals['processed']++;
            $p = json_decode($row->payload, true);
            if (!is_array($p)) { $totals['skipped']++; continue; }

            $avatarUrl  = $p['host_avatar'] ?? null;
            $needsLocal = $force || $this->needsLocalize($avatarUrl);

            if (!$needsLocal) { $totals['skipped']++; continue; }

            $handle = $p['handle'] ?? null;
            $local  = $this->media->ensureLocal($avatarUrl, ['handle' => $handle]);

            if (!$local) {
                $totals['failed']++;
                $this->line("[lives #{$row->id}] FAIL handle=" . ($handle ?? 'null'));
                continue;
            }

            $this->line("[lives #{$row->id}] @{$handle} => " . substr($local, 0, 80));
            $p['host_avatar'] = $local;

            if (!$dry) {
                DB::table('kalodata_raw')->where('id', $row->id)->update([
                    'payload'    => json_encode($p, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
            }
            $totals['updated']++;
            usleep(1_200_000);
        }

        return $totals;
    }

    // -------------------------------------------------------------------------
    // tt_shop_raw / products
    // -------------------------------------------------------------------------

    private function processTtShopProducts(int $limit, bool $dry, bool $force): array
    {
        $this->info('--- tt_shop_raw / product ---');
        $date = DB::table('tt_shop_raw')->where('type', 'product')->max('snapshot_date');
        if (!$date) { $this->warn('Sem snapshot de tt_shop_raw'); return $this->emptyTotals(); }

        $totals = $this->emptyTotals();

        $rows = DB::table('tt_shop_raw')
            ->where('type', 'product')
            ->where('snapshot_date', $date)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($rows as $row) {
            $totals['processed']++;
            $p = json_decode($row->payload, true);
            if (!is_array($p)) { $totals['skipped']++; continue; }

            $imgUrl     = $p['image']['url_list'][0] ?? null;
            $needsLocal = $force || $this->needsLocalize($imgUrl);

            if (!$needsLocal) { $totals['skipped']++; continue; }

            $local = $this->media->ensureLocal($imgUrl, []);

            if (!$local) {
                $totals['failed']++;
                $this->line("[tt_shop #{$row->id}] FAIL img=" . substr($imgUrl ?? '', 0, 60));
                continue;
            }

            $this->line("[tt_shop #{$row->id}] " . mb_substr($p['title'] ?? '', 0, 40) . " => " . substr($local, 0, 80));

            // Atualiza url_list[0] do payload
            if (!isset($p['image'])) $p['image'] = [];
            if (!isset($p['image']['url_list'])) $p['image']['url_list'] = [];
            $p['image']['url_list'][0] = $local;

            if (!$dry) {
                DB::table('tt_shop_raw')->where('id', $row->id)->update([
                    'payload'    => json_encode($p, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
            }
            $totals['updated']++;
            usleep(300_000);
        }

        return $totals;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function needsLocalize(?string $url): bool
    {
        if (!$url) return true;
        if (str_contains($url, 'api.seller.global/storage/')) return false;
        if ($this->media->isExpiredSignedUrl($url)) return true;
        if ($this->media->isTikTokCdnUrl($url)) return true;
        return false;
    }

    private function emptyTotals(): array
    {
        return ['processed' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
    }

    private function mergeTotals(array &$dest, array $src): void
    {
        foreach ($src as $k => $v) {
            $dest[$k] = ($dest[$k] ?? 0) + $v;
        }
    }
}
