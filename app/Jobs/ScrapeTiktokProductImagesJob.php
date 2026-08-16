<?php

namespace App\Jobs;

use App\Services\TikTokMediaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * SEL-321: Scrape de imagens de produto TikTok Shop (anúncio + avaliações).
 *
 * Fila: default. Timeout: 300s. Tries: 2.
 * Disparo: GET /v1/insights/tiktok/product-images/{key} quando tabela vazia.
 *
 * Fluxo:
 *  1. Busca produto no kalodata_raw/tt_shop_raw pelo product_key
 *  2. Importa image_url do kalodata como source=kalodata (se ainda não existe)
 *  3. Tenta scrape da página TikTok Shop (tiktok_url) via HTTP com User-Agent
 *  4. Extrai imagens do anúncio (og:image, json-ld, meta) → source=listing
 *  5. Extrai fotos das avaliações (json embedded) → source=review
 *  6. Baixa cada imagem pra tt-media (TikTokMediaService::downloadAndStore)
 *  7. Persiste em tiktok_product_images com quality_score por source
 */
class ScrapeTiktokProductImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries   = 2;

    private const QUALITY = [
        'upload'   => 90,
        'kalodata' => 80,
        'listing'  => 70,
        'review'   => 60,
        'catalog'  => 50,
    ];

    public function __construct(private string $productKey) {}

    public function handle(TikTokMediaService $media): void
    {
        if (config('app.tenant') !== 'sellerglobal') {
            \Illuminate\Support\Facades\Log::info('[INF-BKP] ' . __CLASS__ . ' skipped (tenant=' . config('app.tenant', 'null') . ')');
            return;
        }
        Log::info('[SEL-321 ScrapeTiktokProductImages] iniciando', ['key' => $this->productKey]);

        // 1. Importar imagem kalodata (se não existe)
        $this->importKalodata($media);

        // 2. Tentar scrape TikTok Shop
        $tiktokUrl = $this->findTiktokUrl();
        if ($tiktokUrl) {
            $this->scrapeTiktokPage($tiktokUrl, $media);
        } else {
            Log::info('[SEL-321] sem tiktok_url para scrape', ['key' => $this->productKey]);
        }

        // 3. Importar do catálogo (client_products match por título)
        $this->importFromCatalog($media);

        // 4. Extrair frames do vídeo do produto (ffmpeg) — shop.tiktok.com é
        // SPA (HTML shell 5KB sem imagens), então o vídeo local é a melhor
        // fonte de imagens reais do produto em uso.
        $this->extractVideoFrames();

        // 5. Fechar marcador __scrape_queued__ (pending órfão bloqueava
        // re-dispatch pra sempre no controller)
        DB::table('tiktok_product_images')
            ->where('product_key', $this->productKey)
            ->where('url_original', '__scrape_queued__')
            ->update(['scrape_status' => 'done', 'updated_at' => now()]);

        Log::info('[SEL-321 ScrapeTiktokProductImages] concluído', [
            'key'   => $this->productKey,
            'total' => DB::table('tiktok_product_images')
                ->where('product_key', $this->productKey)
                ->whereNotNull('url_local')
                ->count(),
        ]);
    }

    // ─── Importar imagem Kalodata ─────────────────────────────────────────

    private function importKalodata(TikTokMediaService $media): void
    {
        $exists = DB::table('tiktok_product_images')
            ->where('product_key', $this->productKey)
            ->where('source', 'kalodata')
            ->exists();

        if ($exists) return;

        $row = DB::table('kalodata_raw')
            ->where('type', 'products')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.id')) = ?", [$this->productKey])
            ->orderByDesc('snapshot_date')
            ->first();

        if (! $row) return;

        $payload  = json_decode($row->payload, true);
        $imageUrl = $payload['image_url'] ?? null;
        if (! $imageUrl) return;

        $localUrl = $media->ensureLocal($imageUrl);
        $this->upsert('kalodata', $imageUrl, $localUrl);
    }

    // ─── Resolver tiktok_url ──────────────────────────────────────────────

    private function findTiktokUrl(): ?string
    {
        // Kalodata
        $row = DB::table('kalodata_raw')
            ->where('type', 'products')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.id')) = ?", [$this->productKey])
            ->orderByDesc('snapshot_date')
            ->first();

        if ($row) {
            $p = json_decode($row->payload, true);
            if (! empty($p['tiktok_url'])) return $p['tiktok_url'];
            // Montar URL padrão TikTok Shop se tiver external_id
            if (! empty($p['id'])) {
                return 'https://shop.tiktok.com/view/product/' . $p['id'];
            }
        }

        // tt_shop_raw
        $ttRow = DB::table('tt_shop_raw')
            ->where('type', 'product')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.product_id')) = ?", [$this->productKey])
            ->orderByDesc('snapshot_date')
            ->first();

        if ($ttRow) {
            $p = json_decode($ttRow->payload, true);
            return $p['tiktok_url'] ?? null;
        }

        return null;
    }

    // ─── Scrape página TikTok Shop ────────────────────────────────────────

    private function scrapeTiktokPage(string $url, TikTokMediaService $media): void
    {
        try {
            $resp = Http::timeout(20)
                ->withHeaders([
                    'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                    'Accept-Language' => 'pt-BR,pt;q=0.9',
                    'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Referer'         => 'https://shop.tiktok.com/',
                ])
                ->withOptions(['allow_redirects' => ['max' => 5, 'strict' => false]])
                ->get($url);

            if (! $resp->successful()) {
                Log::info('[SEL-321] scrape HTTP falhou', [
                    'key'    => $this->productKey,
                    'status' => $resp->status(),
                ]);
                return;
            }

            $html = $resp->body();

            // Extrair imagens do anúncio
            $listingImages = $this->extractListingImages($html);
            foreach (array_slice($listingImages, 0, 8) as $imgUrl) {
                $local = $media->downloadAndStore($imgUrl);
                if ($local) $this->upsert('listing', $imgUrl, $local);
            }

            // Extrair fotos de avaliações
            $reviewImages = $this->extractReviewImages($html);
            foreach (array_slice($reviewImages, 0, 12) as $imgUrl) {
                $local = $media->downloadAndStore($imgUrl);
                if ($local) $this->upsert('review', $imgUrl, $local);
            }
        } catch (\Throwable $e) {
            Log::warning('[SEL-321] scrape exception', [
                'key' => $this->productKey,
                'err' => $e->getMessage(),
            ]);
            DB::table('tiktok_product_images')->updateOrInsert(
                ['product_key' => $this->productKey, 'source' => 'listing', 'url_original' => $url],
                ['scrape_status' => 'failed', 'scrape_error' => substr($e->getMessage(), 0, 500), 'updated_at' => now()]
            );
        }
    }

    private function extractListingImages(string $html): array
    {
        $images = [];

        // og:image
        if (preg_match_all('/<meta[^>]+property="og:image"[^>]+content="([^"]+)"/i', $html, $m)) {
            foreach ($m[1] as $u) {
                if ($this->isProductImage($u)) $images[] = $u;
            }
        }

        // JSON-LD product images
        if (preg_match_all('/"image"\s*:\s*(\[[^\]]+\]|"[^"]+")/', $html, $m)) {
            foreach ($m[1] as $raw) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $u) {
                        if (is_string($u) && str_starts_with($u, 'http')) $images[] = $u;
                    }
                } elseif (is_string($decoded) && str_starts_with($decoded, 'http')) {
                    $images[] = $decoded;
                }
            }
        }

        // data-src em imagens de galeria
        if (preg_match_all('/data-src="(https?:\/\/[^"]+(?:tiktokcdn|ttwstatic|ibyteimg)[^"]+)"/i', $html, $m)) {
            foreach ($m[1] as $u) $images[] = $u;
        }

        return array_unique($images);
    }

    private function extractReviewImages(string $html): array
    {
        $images = [];

        // Padrão: "reviewImage":[{"url":"..."}]
        if (preg_match_all('/"reviewImage"\s*:\s*\[([^\]]+)\]/', $html, $m)) {
            foreach ($m[1] as $block) {
                if (preg_match_all('/"url"\s*:\s*"([^"]+)"/i', $block, $mu)) {
                    foreach ($mu[1] as $u) {
                        if ($this->isProductImage($u)) $images[] = $u;
                    }
                }
            }
        }

        // Padrão alternativo: "image_url":"https://..."
        if (preg_match_all('/"image_url"\s*:\s*"(https?:\/\/[^"]+)"/i', $html, $m)) {
            foreach ($m[1] as $u) {
                if ($this->isProductImage($u)) $images[] = $u;
            }
        }

        return array_unique($images);
    }

    private function isProductImage(string $url): bool
    {
        return str_starts_with($url, 'http')
            && (str_contains($url, 'tiktokcdn') || str_contains($url, 'ttwstatic') || str_contains($url, 'ibyteimg'))
            && ! str_contains($url, 'avatar')
            && ! str_contains($url, 'profile')
            && ! str_contains($url, 'cover_v2');
    }

    // ─── Importar do catálogo ─────────────────────────────────────────────

    private function importFromCatalog(TikTokMediaService $media): void
    {
        // Busca título do produto
        $row = DB::table('kalodata_raw')
            ->where('type', 'products')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.id')) = ?", [$this->productKey])
            ->orderByDesc('snapshot_date')
            ->first();

        if (! $row) return;
        $title = json_decode($row->payload, true)['product_title'] ?? null;
        if (! $title) return;

        // Busca em client_products por título similar (custom_title; imagens em
        // image_url + custom_images — a tabela não tem colunas title/images)
        $slug = mb_strtolower(mb_substr(preg_replace('/\s+/', ' ', $title), 0, 60));
        $products = DB::table('client_products')
            ->where('custom_title', 'LIKE', '%' . mb_substr($slug, 0, 30) . '%')
            ->where(function ($q) {
                $q->whereNotNull('image_url')->orWhereNotNull('custom_images');
            })
            ->limit(5)
            ->get();

        foreach ($products as $cp) {
            $images = [];
            if (! empty($cp->image_url)) {
                $images[] = $cp->image_url;
            }
            $customRaw = $cp->custom_images ?? null;
            if ($customRaw) {
                $custom = is_string($customRaw) ? json_decode($customRaw, true) : $customRaw;
                if (is_array($custom)) {
                    foreach ($custom as $ci) {
                        if (is_string($ci)) {
                            $images[] = $ci;
                        } elseif (is_array($ci) && ! empty($ci['url'])) {
                            $images[] = $ci['url'];
                        }
                    }
                }
            }

            foreach (array_slice(array_unique($images), 0, 3) as $imgUrl) {
                if (! str_starts_with($imgUrl, 'http')) continue;
                $local = $media->ensureLocal($imgUrl);
                if ($local) $this->upsert('catalog', $imgUrl, $local);
            }
        }
    }

    // ─── Frames do vídeo do produto (ffmpeg) ──────────────────────────────

    private function extractVideoFrames(): void
    {
        $row = DB::table('kalodata_raw')
            ->where('type', 'products')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.id')) = ?", [$this->productKey])
            ->orderByDesc('snapshot_date')
            ->first();
        if (! $row) return;

        $p = json_decode($row->payload, true);
        // Payload cru traz video_url do CDN (expira); o mp4 persistido (SEL-308)
        // segue o padrão kalovid_{video_id}.mp4 em tt-media.
        $file = null;
        if (! empty($p['video_id'])) {
            $candidate = 'kalovid_' . preg_replace('/\D/', '', (string) $p['video_id']) . '.mp4';
            if (is_file(storage_path('app/public/tt-media/' . $candidate))) $file = $candidate;
        }
        if (! $file && ! empty($p['video_url']) && str_contains($p['video_url'], '/storage/tt-media/')) {
            $file = basename(parse_url($p['video_url'], PHP_URL_PATH));
        }
        if (! $file) return;
        $videoUrl = url('/storage/tt-media/' . $file);
        $path     = storage_path('app/public/tt-media/' . $file);
        if (! is_file($path)) return;

        $already = DB::table('tiktok_product_images')
            ->where('product_key', $this->productKey)
            ->where('source', 'listing')
            ->where('url_original', 'LIKE', '%#frame%')
            ->whereNotNull('url_local')
            ->count();
        if ($already >= 4) return;

        $dur = (float) trim((string) shell_exec(
            'ffprobe -v error -show_entries format=duration -of csv=p=0 ' . escapeshellarg($path) . ' 2>/dev/null'
        ));
        if ($dur <= 1) return;

        foreach ([0.2, 0.4, 0.6, 0.8] as $i => $pos) {
            $ts      = number_format($dur * $pos, 2, '.', '');
            $outFile = 'prodframe_' . $this->productKey . '_' . ($i + 1) . '.jpg';
            $outPath = storage_path('app/public/tt-media/' . $outFile);
            shell_exec(
                'ffmpeg -y -ss ' . escapeshellarg($ts) . ' -i ' . escapeshellarg($path)
                . ' -frames:v 1 -q:v 3 ' . escapeshellarg($outPath) . ' 2>/dev/null'
            );
            if (is_file($outPath) && filesize($outPath) > 5000) {
                $this->upsert('listing', $videoUrl . '#frame' . ($i + 1), url('/storage/tt-media/' . $outFile));
            }
        }
    }

    // ─── Persistência ─────────────────────────────────────────────────────

    private function upsert(string $source, ?string $original, ?string $local): void
    {
        DB::table('tiktok_product_images')->updateOrInsert(
            [
                'product_key'  => $this->productKey,
                'source'       => $source,
                'url_original' => $original,
            ],
            [
                'url_local'     => $local,
                'quality_score' => self::QUALITY[$source] ?? 50,
                'scrape_status' => $local ? 'done' : 'failed',
                'updated_at'    => now(),
                'created_at'    => now(),
            ]
        );
    }
}
