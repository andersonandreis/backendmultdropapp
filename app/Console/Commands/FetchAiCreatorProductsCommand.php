<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * SEL-237 Ruan 18/07/2026 — pra cada ai_creator busca os produtos TT Shop
 * que ele vende, extraindo os anchors dos vídeos via tikwm.
 *
 * Fluxo:
 *   1. tikwm.com/api/user/videos?unique_id=@{handle}&count=30 → lista vídeos
 *   2. pra cada vídeo, tikwm.com/api/?url={video_url} → extract data.anchors[]
 *   3. anchor type_id=2 tem product_id + keyword (nome loja)
 *   4. upsert em ai_creator_products (unique por creator+product_id)
 *
 * Rate limit tikwm ~1/s → 30 vídeos = ~30s por criador. 52 criadores = ~26min.
 * Roda dailyAt 07:30 BRT (30min depois do backfill perfis 07:00).
 *
 * Uso: php artisan ai-creators:fetch-products [--limit=N] [--id=X]
 */
class FetchAiCreatorProductsCommand extends Command
{
    protected $signature = 'ai-creators:fetch-products {--limit=52} {--id=} {--only-empty}';
    protected $description = 'SEL-237 busca produtos TT Shop que cada criador IA vende';

    public function handle(): int
    {
        $q = DB::table('ai_creators')
            ->select('id', 'handle', 'name')
            ->where('is_visible', 1)
            ->where('is_approved', 1);

        if ($id = $this->option('id')) {
            $q->where('id', (int) $id);
        }
        if ($this->option('only-empty')) {
            $q->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('ai_creator_products')
                    ->whereColumn('ai_creator_products.ai_creator_id', 'ai_creators.id');
            });
        }

        $creators = $q->limit((int) $this->option('limit'))->get();
        $this->info("Processando {$creators->count()} criadores…");

        $totalProducts = 0;

        foreach ($creators as $creator) {
            $handle = ltrim($creator->handle, '@');
            $this->info("[{$creator->id}] @{$handle} — {$creator->name}");

            try {
                $videosResp = Http::timeout(15)
                    ->get("https://www.tikwm.com/api/user/videos", ['unique_id' => '@' . $handle, 'count' => 30]);
                if (!$videosResp->successful()) {
                    $this->warn("  videos endpoint HTTP {$videosResp->status()}");
                    continue;
                }
                $videos = $videosResp->json('data.videos') ?? [];
                $this->line("  {$this->countStr(count($videos))} vídeos encontrados");

                $productsFound = 0;

                foreach ($videos as $v) {
                    $vidId = $v['video_id'] ?? null;
                    if (!$vidId) continue;

                    $videoUrl = "https://www.tiktok.com/@{$handle}/video/{$vidId}";
                    usleep(1_100_000); // ~1.1s entre calls pra respeitar rate limit
                    $detailResp = Http::timeout(15)->get("https://www.tikwm.com/api/", ['url' => $videoUrl]);
                    if (!$detailResp->successful()) continue;

                    $anchors = $detailResp->json('data.anchors') ?? [];
                    if (!is_array($anchors)) continue;

                    foreach ($anchors as $a) {
                        if (($a['type_id'] ?? 0) !== 2) continue; // 2 = product anchor
                        $productId = $a['id'] ?? null;
                        if (!$productId) continue;

                        $extras = $a['extra_info'] ?? [];
                        $keyword = $a['keyword'] ?? null; // shop/product name

                        DB::table('ai_creator_products')->updateOrInsert(
                            ['ai_creator_id' => $creator->id, 'product_id' => (string) $productId],
                            [
                                'shop_id' => $extras['seller_id'] ?? $a['schema'] ?? null,
                                'title' => mb_substr($keyword ?: 'Produto TikTok Shop', 0, 490),
                                'image_url' => $a['icon']['url_list'][0] ?? $a['thumbnail'] ?? null,
                                'price' => isset($extras['price']) ? (float) $extras['price'] : null,
                                'currency' => $extras['currency'] ?? 'BRL',
                                'rating' => isset($extras['rating']) ? (float) $extras['rating'] : null,
                                'sold_count' => isset($extras['sold_count']) ? (int) $extras['sold_count'] : null,
                                'product_url' => $a['schema'] ?? null,
                                'shop_name' => $keyword,
                                'raw' => json_encode($a, JSON_UNESCAPED_UNICODE),
                                'scraped_at' => now(),
                                'updated_at' => now(),
                                'created_at' => now(),
                            ]
                        );
                        $productsFound++;
                    }
                }

                $this->info("  ✅ {$productsFound} produtos");
                $totalProducts += $productsFound;
            } catch (\Throwable $e) {
                $this->error("  exception: " . $e->getMessage());
            }
        }

        $this->info("Total: {$totalProducts} produtos catalogados");
        return self::SUCCESS;
    }

    private function countStr(int $n): string
    {
        return $n === 1 ? '1' : "$n";
    }
}
