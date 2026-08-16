<?php

namespace App\Jobs;

use App\Models\TiktokViralVideo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SEL-218: Scraper diário de vídeos virais do TikTok BR.
 *
 * Fila: default. Timeout: 900s. Tries: 2.
 * Schedule: dailyAt('04:00') registrado em routes/console.php.
 *
 * Fluxo:
 *  1. Itera cada keyword de descoberta de produto
 *  2. Chama tikwm.com/api/feed/search?keywords=<termo>&region=br
 *  3. Pagina com cursor até 200-300 vídeos por termo (max 10 páginas de 30)
 *  4. Dedup por external_video_id (unique no banco)
 *  5. Upsert com viral_score calculado
 *  6. Purge de registros com scraped_at < agora - 7 dias
 */
class ScrapeTiktokViralVideosJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;
    public int $tries   = 2;

    /**
     * Palavras-chave de descoberta de produto BR (Ruan SEL-218 18/07).
     * Termos em PT + hashtags EN que viralizam no BR.
     */
    private const KEYWORDS = [
        '#productfindsbrasil',
        '#produtofinds',
        '#achadinhosdatiktok',
        '#achadinhostiktokshop',
        '#tiktokmademebuyit',
        '#tiktokmademebuyitbrasil',
        '#tiktokshopfinds',
        '#produtoviral',
        '#compraviralizada',
        '#compraquevaleu',
        '#tiktokshopbrasil',
        '#tiktokshopbr',
        'product finds',
        'produto viral',
        'achadinho tiktok',
        'tiktok shop brasil',
        'produto tiktok',
        'comprei no tiktok',
    ];

    private const TIKWM_BASE     = 'https://www.tikwm.com/api/feed/search';
    private const REGION          = 'br';
    private const PER_PAGE        = 30;
    private const MAX_PAGES       = 10; // até 300 vídeos por termo
    private const PURGE_DAYS      = 7;

    public function handle(): void
    {
        if (config('app.tenant') !== 'sellerglobal') {
            \Illuminate\Support\Facades\Log::info('[INF-BKP] ' . __CLASS__ . ' skipped (tenant=' . config('app.tenant', 'null') . ')');
            return;
        }
        Log::info('[SEL-218 ScrapeTiktokViralVideosJob] iniciando');

        $seen     = [];   // dedup cross-keyword nesta execução
        $upserted = 0;

        foreach (self::KEYWORDS as $keyword) {
            $cursor = 0;
            $page   = 0;

            do {
                $page++;
                $response = $this->fetchPage($keyword, $cursor);

                if (empty($response['videos'])) {
                    break;
                }

                foreach ($response['videos'] as $v) {
                    $videoId = (string) ($v['video_id'] ?? $v['aweme_id'] ?? '');
                    if (! $videoId || isset($seen[$videoId])) {
                        continue;
                    }
                    $seen[$videoId] = true;

                    $author  = $v['author'] ?? [];
                    $stats   = $v['statistics'] ?? $v;

                    $views    = (int) ($stats['play_count']    ?? $v['play_count']    ?? 0);
                    $comments = (int) ($stats['comment_count'] ?? $v['comment_count'] ?? 0);
                    $likes    = (int) ($stats['digg_count']    ?? $v['digg_count']    ?? $v['likes'] ?? 0);
                    $shares   = (int) ($stats['share_count']   ?? $v['share_count']   ?? 0);

                    $handle   = (string) ($author['unique_id'] ?? $author['handle'] ?? '');
                    $videoUrl = $handle
                        ? "https://www.tiktok.com/@{$handle}/video/{$videoId}"
                        : "https://www.tiktok.com/video/{$videoId}";

                    // Extrai link shop.tiktok.com do caption (regex simples)
                    $caption    = (string) ($v['title'] ?? $v['desc'] ?? '');
                    $productUrl = null;
                    if (preg_match('#https?://shop\.tiktok\.com/\S+#i', $caption, $m)) {
                        $productUrl = $m[0];
                    }

                    // Hashtags do caption (#palavra)
                    preg_match_all('/#[\w\x{00C0}-\x{017E}]+/u', $caption, $tagMatches);
                    $hashtags = $tagMatches[0] ?: null;

                    $publishedTs = isset($v['create_time']) ? (int) $v['create_time'] : null;

                    $score = TiktokViralVideo::calcViralScore($views, $comments, $likes, $shares);

                    // SEL-315: URLs de cover do CDN TikTok expiram em ~48h — persistir local
                    $coverRemote = mb_substr((string) ($v['cover'] ?? $v['origin_cover'] ?? ''), 0, 512) ?: null;
                    $coverLocal  = $this->persistCover($videoId, $coverRemote);

                    // SEL-319: persistir mp4 local (URLs tiktokcdn expiram e o player quebra)
                    $playRemote = mb_substr((string) ($v['hdplay'] ?? $v['play'] ?? ''), 0, 512) ?: null;
                    $playLocal  = $this->persistMp4($videoId, $playRemote);

                    TiktokViralVideo::updateOrCreate(
                        ['external_video_id' => $videoId],
                        [
                            'video_url'              => mb_substr($videoUrl, 0, 512),
                            'cover_url'              => $coverLocal ?? $coverRemote,
                            'play_url_hd'            => $playLocal ?? $playRemote,
                            'creator_handle'         => $handle ? mb_substr($handle, 0, 120) : null,
                            'creator_name'           => mb_substr((string) ($author['nickname'] ?? $author['name'] ?? ''), 0, 255) ?: null,
                            'creator_avatar_url'     => mb_substr((string) ($author['avatar'] ?? $author['avatar_thumb']['url_list'][0] ?? ''), 0, 512) ?: null,
                            'caption'                => mb_substr($caption, 0, 2000) ?: null,
                            'hashtags'               => $hashtags,
                            'views'                  => $views,
                            'comments'               => $comments,
                            'likes'                  => $likes,
                            'shares'                 => $shares,
                            'viral_score'            => $score,
                            'detected_product_url'   => $productUrl,
                            'search_term'            => mb_substr($keyword, 0, 128),
                            'published_at'           => $publishedTs ? \Carbon\Carbon::createFromTimestamp($publishedTs) : null,
                            'scraped_at'             => now(),
                        ]
                    );
                    $upserted++;
                }

                $cursor = (int) ($response['cursor'] ?? 0);
                $hasMore = (bool) ($response['hasMore'] ?? false);

                // Throttle cortês: 200ms entre páginas
                if ($hasMore && $page < self::MAX_PAGES) {
                    usleep(200_000);
                }

            } while ($hasMore && $cursor > 0 && $page < self::MAX_PAGES);

            // Throttle entre keywords
            usleep(500_000);
        }

        Log::info('[SEL-218 ScrapeTiktokViralVideosJob] upserted', ['count' => $upserted]);

        // Purge de vídeos antigos (> 7 dias) + covers locais órfãos
        $stale = TiktokViralVideo::where('scraped_at', '<', now()->subDays(self::PURGE_DAYS))
            ->get(['id', 'external_video_id']);
        foreach ($stale as $row) {
            @unlink(storage_path("app/public/tt-media/viralvid_{$row->id}.jpg"));
            @unlink(storage_path("app/public/tt-media/viralvid_ext_{$row->external_video_id}.jpg"));
        }
        $purged = TiktokViralVideo::whereIn('id', $stale->pluck('id'))->delete();
        Log::info('[SEL-218 ScrapeTiktokViralVideosJob] purged', ['count' => $purged]);
    }

    /**
     * Baixa o cover pra storage local (tt-media) e devolve a URL própria.
     * Se já existir arquivo válido, reutiliza sem baixar de novo.
     */
    private function persistCover(string $videoId, ?string $remote): ?string
    {
        $safeId = preg_replace('/[^A-Za-z0-9_-]/', '', $videoId);
        if ($safeId === '') {
            return null;
        }

        $dir  = storage_path('app/public/tt-media');
        $file = "{$dir}/viralvid_ext_{$safeId}.jpg";
        $url  = rtrim(config('app.url'), '/')."/storage/tt-media/viralvid_ext_{$safeId}.jpg";

        if (is_file($file) && filesize($file) > 1000) {
            return $url;
        }

        if (! $remote) {
            return null;
        }

        try {
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $res = Http::timeout(12)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; HubAI-SEL218/1.0)'])
                ->get($remote);
            $body = $res->successful() ? $res->body() : '';
            if (strlen($body) > 1000) {
                file_put_contents($file, $body);
                return $url;
            }
        } catch (\Throwable $e) {
            // cover é best-effort; mantém URL remota como fallback
        }

        return null;
    }

    /**
     * SEL-319: Baixa o mp4 pra storage local (tt-media) e devolve a URL propria.
     * Best-effort — em caso de falha mantem URL remota como fallback.
     */
    private function persistMp4(string $videoId, ?string $remote): ?string
    {
        $safeId = preg_replace('/[^A-Za-z0-9_-]/', '', $videoId);
        if ($safeId === '' || !$remote) {
            return null;
        }

        $dir  = storage_path('app/public/tt-media');
        $file = "{$dir}/viralvid_ext_{$safeId}.mp4";
        $url  = rtrim(config('app.url'), '/') . "/storage/tt-media/viralvid_ext_{$safeId}.mp4";

        if (is_file($file) && filesize($file) > 10000) {
            return $url;
        }

        try {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $res = Http::timeout(60)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; HubAI-SEL319/1.0)'])
                ->get($remote);
            $body = $res->successful() ? $res->body() : '';
            if (strlen($body) > 10000) {
                file_put_contents($file, $body);
                return $url;
            }
        } catch (\Throwable $e) {
            // mp4 e best-effort; mantem URL remota como fallback
        }

        return null;
    }

    /**
     * Busca uma página do tikwm.com/api/feed/search.
     * Retorna ['videos' => [...], 'cursor' => int, 'hasMore' => bool].
     */
    private function fetchPage(string $keyword, int $cursor): array
    {
        try {
            $res = Http::timeout(20)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; HubAI-SEL218/1.0)',
                    'Referer'    => 'https://www.tikwm.com/',
                ])
                ->get(self::TIKWM_BASE, [
                    'keywords' => $keyword,
                    'region'   => self::REGION,
                    'hd'       => 1,
                    'cursor'   => $cursor,
                    'count'    => self::PER_PAGE,
                ]);

            if (! $res->successful()) {
                Log::warning('[SEL-218] tikwm HTTP error', [
                    'keyword' => $keyword,
                    'status'  => $res->status(),
                ]);
                return [];
            }

            $body = $res->json();

            // tikwm retorna { code: 0, data: { videos: [...], cursor: N, hasMore: bool } }
            if (($body['code'] ?? -1) !== 0) {
                Log::warning('[SEL-218] tikwm non-zero code', [
                    'keyword' => $keyword,
                    'code'    => $body['code'] ?? 'null',
                    'msg'     => $body['msg'] ?? '',
                ]);
                return [];
            }

            $data = $body['data'] ?? [];

            return [
                'videos'  => $data['videos'] ?? [],
                'cursor'  => (int) ($data['cursor'] ?? 0),
                'hasMore' => (bool) ($data['hasMore'] ?? false),
            ];

        } catch (\Throwable $e) {
            Log::warning('[SEL-218] tikwm exception', [
                'keyword' => $keyword,
                'error'   => $e->getMessage(),
            ]);
            return [];
        }
    }
}
