<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * SEL-274 Ruan 20:06 — "tem várias aqui que tem quase 20 imagens iguais, só
 * que são produtos totalmente diferente".
 *
 * Causa: ~13 produtos Kalodata do topo do ranking vêm SEM image_url no payload
 * e o front caía num fallback categórico (mesma foto pra produtos diferentes).
 *
 * Este comando busca a foto REAL:
 *   1. Se tiktok_url é vídeo TT (@user/video/id) → tikwm.com/api origin_cover
 *      (thumb do vídeo geralmente mostra o produto — mesmo path dos criadores).
 *   2. Fallback: PDP pública TT Shop → og:image (bloqueado pra crawler na maioria).
 *
 * Uso:
 *   php artisan tiktok:enrich-kalodata-images            (último snapshot, só sem foto)
 *   php artisan tiktok:enrich-kalodata-images --limit=50
 *   php artisan tiktok:enrich-kalodata-images --dry
 */
class EnrichKalodataProductImages extends Command
{
    protected $signature = 'tiktok:enrich-kalodata-images
                            {--limit=40 : máximo de PDPs a buscar por rodada}
                            {--dry : não grava, só reporta o que encontraria}';

    protected $description = 'SEL-274 preenche image_url/shop_name reais (tikwm+PDP og:image) nos produtos Kalodata sem foto';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $dry = (bool) $this->option('dry');

        $date = DB::table('kalodata_raw')->where('type', 'products')->max('snapshot_date');
        if (!$date) {
            $this->warn('kalodata_raw sem snapshot de products');
            return self::SUCCESS;
        }

        $rows = DB::table('kalodata_raw')
            ->where('type', 'products')
            ->where('snapshot_date', $date)
            ->orderBy('id')
            ->get();

        $done = 0; $found = 0; $miss = 0;
        $seenPdp = []; // dedup: se a mesma URL repete 6x no snapshot, só busca 1x
        foreach ($rows as $row) {
            if ($done >= $limit) break;
            $p = json_decode($row->payload, true);
            if (!is_array($p)) continue;
            $hasImage = !empty($p['image_url']);
            $hasShop  = !empty($p['shop_name']);
            if ($hasImage && $hasShop) continue;

            $pdpUrl = $this->pdpUrl($p, $row->external_id);
            if (!$pdpUrl) continue;

            // Cache por URL: mesma URL no snapshot => reusa resultado
            $cacheKey = md5($pdpUrl);
            if (isset($seenPdp[$cacheKey])) {
                $cached = $seenPdp[$cacheKey];
                if ($cached['image'] && !$hasImage) { $p['image_url'] = $cached['image']; $hasImage = true; }
                if ($cached['shop']  && !$hasShop)  { $p['shop_name'] = $cached['shop'];  $hasShop  = true; }
                if ($cached['image'] || $cached['shop']) {
                    if (!$dry) DB::table('kalodata_raw')->where('id', $row->id)->update([
                        'payload' => json_encode($p, JSON_UNESCAPED_UNICODE), 'updated_at' => now(),
                    ]);
                    $found++;
                    $this->line("[{$row->id}] cache hit → " . ($cached['image'] ?: '(shop only)'));
                }
                continue;
            }

            $done++;
            $this->line("[{$row->id}] " . mb_substr($p['product_title'] ?? '', 0, 50) . " → $pdpUrl");

            $img = null; $shop = null;

            // 1. Video TT → tikwm cover
            if (!$hasImage && preg_match('~tiktok\.com/@[\w.\-]+/video/(\d+)~i', $pdpUrl)) {
                if ($tw = $this->fetchTikwmCover($pdpUrl)) {
                    $img = $tw;
                    $this->info('    image via tikwm: ' . mb_substr($img, 0, 90));
                }
            }

            // 2. PDP TT Shop → og:image + shop_name
            if (!$img || !$hasShop) {
                $html = $this->fetchPdp($pdpUrl);
                if ($html !== null) {
                    if (!$img && ($og = $this->extractOgImage($html))) {
                        $img = $og;
                        $this->info('    image via og: ' . mb_substr($img, 0, 90));
                    }
                    if (!$hasShop && ($sn = $this->extractShopName($html))) {
                        $shop = $sn;
                        $this->info("    shop: $shop");
                    }
                }
            }

            $seenPdp[$cacheKey] = ['image' => $img, 'shop' => $shop];

            $changed = false;
            if ($img && !$hasImage)  { $p['image_url'] = $img;  $changed = true; $found++; }
            if ($shop && !$hasShop)  { $p['shop_name'] = $shop; $changed = true; }
            if (!$changed) $miss++;

            if ($changed && !$dry) {
                DB::table('kalodata_raw')->where('id', $row->id)->update([
                    'payload'    => json_encode($p, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
            }
            sleep(1); // gentileza — sem burst
        }

        $this->info("PDPs consultadas: $done · imagens preenchidas: $found · sem resultado: $miss" . ($dry ? ' (dry-run)' : ''));
        return self::SUCCESS;
    }

    private function pdpUrl(array $p, ?string $externalId): ?string
    {
        $u = $p['tiktok_url'] ?? null;
        if ($u && preg_match('~^https?://~i', $u)) return $u;
        $id = $p['id'] ?? $externalId;
        return $id ? "https://shop.tiktok.com/view/product/{$id}?region=BR&locale=pt-BR" : null;
    }

    private function fetchPdp(string $url): ?string
    {
        try {
            $r = Http::timeout(10)
                ->withHeaders([
                    'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36',
                    'Accept'          => 'text/html,application/xhtml+xml',
                    'Accept-Language' => 'pt-BR,pt;q=0.9',
                ])
                ->withOptions(['allow_redirects' => true])
                ->get($url);
            return $r->successful() ? $r->body() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function extractOgImage(string $html): ?string
    {
        if (preg_match('~<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']~i', $html, $m)
            || preg_match('~<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']~i', $html, $m)) {
            $img = html_entity_decode($m[1]);
            return preg_match('~^https?://~i', $img) ? $img : null;
        }
        if (preg_match('~"(?:main_images|images)"\s*:\s*\[\s*\{[^}]*?"url_list"\s*:\s*\[\s*"([^"]+)"~', $html, $m)) {
            return stripslashes($m[1]);
        }
        return null;
    }

    private function extractShopName(string $html): ?string
    {
        if (preg_match('~"(?:shop_name|seller_name)"\s*:\s*"([^"]{2,80})"~u', $html, $m)) {
            $name = trim(stripslashes($m[1]));
            return $name !== '' ? $name : null;
        }
        return null;
    }

    private function fetchTikwmCover(string $videoUrl): ?string
    {
        try {
            $r = Http::timeout(8)->asForm()->post('https://tikwm.com/api/', [
                'url' => $videoUrl, 'hd' => 0,
            ]);
            if (!$r->successful()) return null;
            $j = $r->json();
            $c = $j['data']['origin_cover'] ?? ($j['data']['cover'] ?? null);
            return (is_string($c) && preg_match('~^https?://~i', $c)) ? $c : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
