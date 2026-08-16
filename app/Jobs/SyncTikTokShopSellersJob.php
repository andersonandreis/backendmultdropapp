<?php

namespace App\Jobs;

use App\Models\TiktokShopSeller;
use App\Models\TiktokShopSellerProduct;
use App\Services\TikTokShop\SellerScraperService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SEL-198: Job diario que sincroniza sellers do TikTok Shop BR.
 * Fila: default, timeout 900s.
 * Schedule: dailyAt 06:00 BRT (console.php).
 *
 * Fluxo:
 *  1. Chama SellerScraperService::scrapeSellersList()
 *  2. Se null, usa seedFallback()
 *  3. Para cada seller: upsert em tiktok_shop_sellers (por handle)
 *  4. Para o top-5 sellers: scrapeSellerCatalog e insere produtos
 */
class SyncTikTokShopSellersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;
    public int $tries   = 2;

    public function __construct(private int $limit = 50) {}

    public function handle(SellerScraperService $scraper): void
    {
        Log::info('[SEL-198 SyncSellersJob] iniciando', ['limit' => $this->limit]);

        // 1. Obtem lista de sellers (scrape real ou seed fallback)
        $sellers = $scraper->scrapeSellersList($this->limit)
                ?? $scraper->seedFallback($this->limit);

        $upserted = 0;

        // 2. Upsert em tiktok_shop_sellers por handle
        foreach ($sellers as $s) {
            $handle = mb_substr((string) ($s['handle'] ?? ''), 0, 120);
            if (! $handle) continue;

            TiktokShopSeller::updateOrCreate(
                ['handle' => $handle],
                [
                    'name'                => mb_substr((string) ($s['name'] ?? $handle), 0, 255),
                    'avatar_url'          => $s['avatar_url'] ? mb_substr((string) $s['avatar_url'], 0, 512) : null,
                    'followers'           => (int) ($s['followers'] ?? 0),
                    'products_count'      => (int) ($s['products_count'] ?? 0),
                    'avg_rating'          => isset($s['avg_rating']) ? (float) $s['avg_rating'] : null,
                    'avg_commission_rate' => isset($s['avg_commission_rate']) ? (float) $s['avg_commission_rate'] : null,
                    'gmv_estimate'        => (float) ($s['gmv_estimate'] ?? 0),
                    'rank_position'       => isset($s['rank_position']) ? (int) $s['rank_position'] : null,
                    'category_l1'         => $s['category_l1'] ? mb_substr((string) $s['category_l1'], 0, 120) : null,
                    'raw'                 => $s['raw'] ?? null,
                    'is_visible'          => true,
                ]
            );
            $upserted++;
        }

        Log::info('[SEL-198 SyncSellersJob] sellers upserted', ['count' => $upserted]);

        // 3. Scrape catalogo dos top-5 por rank (best-effort, nao bloqueia se falhar)
        $top5 = TiktokShopSeller::where('is_visible', true)
            ->whereNotNull('rank_position')
            ->orderBy('rank_position')
            ->limit(5)
            ->get();

        foreach ($top5 as $seller) {
            try {
                $products = $scraper->scrapeSellerCatalog($seller->handle);
                if (empty($products)) continue;

                // Deleta produtos antigos e reinsere (catalogo muda diariamente)
                TiktokShopSellerProduct::where('seller_id', $seller->id)->delete();

                $sellerCommission = $seller->avg_commission_rate ?? 15.0;

                foreach (array_slice($products, 0, 20) as $p) {
                    $extId = $p['external_product_id'] ?? null;
                    $commission = isset($p['commission_rate'])
                        ? (float) $p['commission_rate']
                        : $sellerCommission;

                    // Gera link do programa de afiliados TikTok Shop BR
                    $affiliateLink = $extId
                        ? 'https://shop.tiktok.com/br/product/' . $extId . '?aff=1'
                        : null;

                    TiktokShopSellerProduct::create([
                        'seller_id'           => $seller->id,
                        'external_product_id' => $extId,
                        'product_json'        => json_encode($p['product_json'] ?? []),
                        'sales_count'         => (int) ($p['sales_count'] ?? 0),
                        'rating'              => isset($p['rating']) ? (float) $p['rating'] : null,
                        'price'               => isset($p['price']) ? (float) $p['price'] : null,
                        'commission_rate'     => $commission,
                        'affiliate_link'      => $affiliateLink,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('[SEL-198 SyncSellersJob] catalog fail', [
                    'handle' => $seller->handle,
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        Log::info('[SEL-198 SyncSellersJob] concluido', ['sellers' => $upserted]);
    }
}
