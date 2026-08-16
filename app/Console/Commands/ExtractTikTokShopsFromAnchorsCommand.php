<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * SEL-246 Ruan 18/07/2026 — extrai LOJAS TT Shop REAIS dos anchors dos
 * vídeos virais em `tiktok_viral_videos`.
 *
 * Ruan corrigiu 2x: em "Fornecedores pra Afiliado" queremos as LOJAS
 * (shop_id do TT Shop) que vendem o produto que o creator divulga —
 * NÃO o próprio creator/afiliado.
 *
 * Fluxo:
 *   1. Pega N viral videos ainda não processados
 *   2. tikwm.com/api/?url={video_url} → extrai data.anchors[]
 *   3. anchor type_id=2 (product): tem id (product_id) + keyword (shop/product name)
 *   4. Deduplica shops por keyword ou seller_id em extra_info
 *   5. Grava em `tiktok_shop_sellers` com source='real_scrape_anchor'
 *
 * Rate limit tikwm ~1/s. 1861 videos → ~30-45min.
 * Cron dailyAt 05:30 BRT (antes do SyncTikTokShopSellersJob 06:00, que agora
 * fica desativado porque catalogava lado errado).
 *
 * Uso: php artisan tiktok:extract-shops [--limit=200] [--reset]
 */
class ExtractTikTokShopsFromAnchorsCommand extends Command
{
    protected $signature = 'tiktok:extract-shops {--limit=200} {--reset : trunca tabela shops antes}';
    protected $description = 'SEL-246 extrai LOJAS TT Shop reais dos anchors dos viral videos';

    public function handle(): int
    {
        if ($this->option('reset')) {
            // Backup antes de truncar
            $bkpName = 'tiktok_shop_sellers_bkp_' . now()->format('Ymd_His');
            DB::statement("CREATE TABLE {$bkpName} AS SELECT * FROM tiktok_shop_sellers");
            DB::table('tiktok_shop_sellers')->truncate();
            $this->info("backup em {$bkpName}");
        }

        $limit = (int) $this->option('limit');
        $videos = DB::table('tiktok_viral_videos')
            ->select('id', 'external_video_id', 'creator_handle', 'video_url')
            ->where('anchors_processed', false)
            ->orWhereNull('anchors_processed')
            ->limit($limit)
            ->get();

        $this->info("Processando {$videos->count()} vídeos…");

        $shopsFound = 0;
        $processed = 0;

        foreach ($videos as $v) {
            try {
                $resp = Http::timeout(15)->get('https://www.tikwm.com/api/', ['url' => $v->video_url]);
                if (!$resp->successful()) {
                    $this->warn("[{$v->id}] HTTP {$resp->status()}");
                    usleep(1_200_000);
                    continue;
                }
                $body = $resp->json();
                $anchors = $body['data']['anchors'] ?? [];
                if (!is_array($anchors)) $anchors = [];

                foreach ($anchors as $a) {
                    if (($a['type_id'] ?? 0) !== 2) continue; // 2 = product anchor
                    $productId = $a['id'] ?? null;
                    $keyword = $a['keyword'] ?? null; // shop/product name
                    if (!$productId || !$keyword) continue;

                    $extra = $a['extra_info'] ?? [];
                    $shopId = $extra['seller_id'] ?? $extra['shop_id'] ?? md5($keyword);
                    $handle = 'shop_' . substr(md5($keyword), 0, 12);

                    $exists = DB::table('tiktok_shop_sellers')
                        ->where('external_id', $shopId)
                        ->orWhere('handle', $handle)
                        ->first();

                    if (!$exists) {
                        DB::table('tiktok_shop_sellers')->insert([
                            'external_id' => (string) $shopId,
                            'handle' => $handle,
                            'name' => mb_substr($keyword, 0, 200),
                            'avatar_url' => $a['icon']['url_list'][0] ?? null,
                            'affiliate_link' => $a['schema'] ?? null,
                            'followers' => 0,
                            'total_products' => 1,
                            'rating' => isset($extra['rating']) ? (float) $extra['rating'] : null,
                            'sold_count' => isset($extra['sold_count']) ? (int) $extra['sold_count'] : 0,
                            'source' => 'real_scrape_anchor',
                            'is_visible' => 1,
                            'is_approved' => 1,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $shopsFound++;
                    } else {
                        DB::table('tiktok_shop_sellers')->where('id', $exists->id)->update([
                            'total_products' => (int) ($exists->total_products ?? 0) + 1,
                            'updated_at' => now(),
                        ]);
                    }
                }

                DB::table('tiktok_viral_videos')->where('id', $v->id)->update([
                    'anchors_processed' => true,
                    'updated_at' => now(),
                ]);
                $processed++;
            } catch (\Throwable $e) {
                $this->error("[{$v->id}] " . $e->getMessage());
            }
            usleep(1_100_000);
        }

        $this->info("SEL-246 concluído: videos processados={$processed} shops novos={$shopsFound}");
        return self::SUCCESS;
    }
}
