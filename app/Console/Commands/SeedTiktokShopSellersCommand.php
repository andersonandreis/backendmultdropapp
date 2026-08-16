<?php

namespace App\Console\Commands;

use App\Jobs\SyncTikTokShopSellersJob;
use App\Models\TiktokShopSeller;
use App\Services\TikTokShop\SellerScraperService;
use Illuminate\Console\Command;

/**
 * SEL-198: Backfill inicial de sellers TikTok Shop.
 * Uso: php artisan tiktok:seed-sellers [--limit=50] [--force-seed]
 *
 * --force-seed: usa seed hardcode sem tentar scrape real.
 * Sem --force-seed: tenta scrape real, se falhar usa seed.
 */
class SeedTiktokShopSellersCommand extends Command
{
    protected $signature = 'tiktok:seed-sellers
        {--limit=50 : Quantidade maxima de sellers a inserir}
        {--force-seed : Ignora scrape e usa seed hardcode diretamente}';

    protected $description = 'SEL-198: Backfill inicial de sellers TikTok Shop BR (seed + scrape)';

    public function handle(SellerScraperService $scraper): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $forceSeed = (bool) $this->option('force-seed');

        $this->info("SEL-198 tiktok:seed-sellers iniciando (limit={$limit})...");

        $sellers = null;

        if (! $forceSeed) {
            $this->line('Tentando scrape real TikTok Shop Mall BR...');
            $sellers = $scraper->scrapeSellersList($limit);
            if ($sellers) {
                $this->info('Scrape real: ' . count($sellers) . ' sellers encontrados.');
            } else {
                $this->warn('Scrape real falhou (SPA/rate limit). Usando seed hardcode.');
            }
        }

        if (! $sellers) {
            $sellers = $scraper->seedFallback($limit);
            $this->info('Seed hardcode: ' . count($sellers) . ' sellers.');
        }

        $inserted = 0;
        $updated  = 0;

        foreach ($sellers as $s) {
            $handle = mb_substr((string) ($s['handle'] ?? ''), 0, 120);
            if (! $handle) continue;

            $existing = TiktokShopSeller::where('handle', $handle)->exists();

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

            $existing ? $updated++ : $inserted++;
        }

        $this->info("Concluido: inserted={$inserted}, updated={$updated}.");
        $this->line('Execute: php artisan migrate --force && php artisan tiktok:seed-sellers --limit=50');

        return self::SUCCESS;
    }
}
