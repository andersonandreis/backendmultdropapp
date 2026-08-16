<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SEL-126 Ruan 15/07: scraper de tendencias do Mercado Livre.
 *
 * Estrategia:
 *   1. Tenta usar a API oficial /trends/MLB via qualquer conta ML ativa
 *      (mesma tecnica do TrendsController@fetchLocal).
 *   2. Se nao houver conta ML disponivel (ou API falhar), tenta scrape
 *      simples da pagina publica https://tendencias.mercadolivre.com.br
 *   3. Se ambos falharem: cai no seed hardcode com 15 keywords (fallback pra
 *      demo/desenvolvimento nunca ficar vazio).
 *
 * Insere em tiktok_shop_trends com source='ml_trends' — a tabela ja e
 * generica pra multiplas fontes (SEL-126 migration).
 *
 * Roda diario 05:00 BRT via routes/console.php. Idempotente por (source, external_id).
 */
class ScrapeMlTrends extends Command
{
    protected $signature = 'trends:scrape-ml {--limit=30 : quantidade maxima de trends} {--dry-run}';
    protected $description = 'Scrape trends do Mercado Livre e insere em tiktok_shop_trends com source=ml_trends (SEL-126)';

    private const SOURCE = 'ml_trends';
    private const PUBLIC_URL = 'https://tendencias.mercadolivre.com.br';

    public function handle(): int
    {
        $dry   = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));

        $this->info("SEL-126 trends:scrape-ml iniciando (limit={$limit})..." . ($dry ? ' (dry-run)' : ''));

        $trends = $this->fetchFromMlApi($limit)
               ?? $this->fetchFromPublicPage($limit)
               ?? $this->seedFallback();

        $this->info('Trends coletados: ' . count($trends));

        if ($dry) {
            foreach (array_slice($trends, 0, 5) as $i => $t) {
                $this->line(($i + 1) . '. ' . ($t['title'] ?? '?') . '  [' . ($t['external_id'] ?? '?') . ']');
            }
            return self::SUCCESS;
        }

        $inserted = 0;
        $updated  = 0;

        foreach ($trends as $t) {
            $data = [
                'kind'         => 'product',
                'source'       => self::SOURCE,
                'source_url'   => $t['source_url'] ?? self::PUBLIC_URL,
                'external_id'  => $t['external_id'] ?? null,
                'title'        => mb_substr((string) ($t['title'] ?? ''), 0, 250),
                'images'       => isset($t['image']) ? json_encode([$t['image']]) : null,
                'category_l1'  => $t['category'] ?? null,
                'sales_l30d'   => $t['sales_l30d'] ?? null,
                'raw'          => json_encode($t, JSON_UNESCAPED_UNICODE),
                'captured_at'  => now(),
                'updated_at'   => now(),
            ];

            // Idempotente: upsert por (source + external_id) ou (source + title)
            $existing = DB::table('tiktok_shop_trends')
                ->where('source', self::SOURCE)
                ->where(function ($q) use ($t) {
                    if (!empty($t['external_id'])) {
                        $q->where('external_id', $t['external_id']);
                    } else {
                        $q->where('title', $t['title'] ?? '');
                    }
                })
                ->first();

            if ($existing) {
                DB::table('tiktok_shop_trends')->where('id', $existing->id)->update($data);
                $updated++;
            } else {
                $data['created_at'] = now();
                DB::table('tiktok_shop_trends')->insert($data);
                $inserted++;
            }
        }

        $this->info("Inserted={$inserted}, Updated={$updated}.");
        Log::info('trends:scrape-ml concluido', ['inserted' => $inserted, 'updated' => $updated]);

        return self::SUCCESS;
    }

    /**
     * Tenta buscar via API oficial /trends/MLB usando qualquer conta ML ativa.
     */
    private function fetchFromMlApi(int $limit): ?array
    {
        try {
            $svc = app(\App\Services\Integrations\Marketplaces\MercadoLivreService::class);
        } catch (\Throwable $e) {
            return null;
        }

        $account = \App\Models\MarketplaceAccount::whereIn('platform', ['ml', 'mercadolivre', 'mercado_livre'])
            ->where('status', 'active')
            ->whereNotNull('ml_access_token')
            ->orderByDesc('ml_token_expires_at')
            ->first();

        if (!$account) {
            $this->warn('Nenhuma conta ML ativa disponivel — pulando API oficial.');
            return null;
        }

        try {
            $token = $svc->getAccessToken($account);
        } catch (\Throwable $e) {
            return null;
        }
        if (!$token) return null;

        try {
            $resp = Http::withToken($token)
                ->timeout(15)
                ->get('https://api.mercadolibre.com/trends/MLB');
        } catch (\Throwable $e) {
            Log::warning('[SEL-126 ScrapeMlTrends] API exception: ' . $e->getMessage());
            return null;
        }

        if (!$resp->ok()) {
            Log::warning('[SEL-126 ScrapeMlTrends] API falhou', ['status' => $resp->status()]);
            return null;
        }

        $trends = collect($resp->json())
            ->take($limit)
            ->map(function ($t) {
                return [
                    'external_id' => $t['keyword'] ?? null,
                    'title'       => $t['keyword'] ?? null,
                    'source_url'  => $t['url'] ?? null,
                    'category'    => null,
                    'image'       => null,
                ];
            })
            ->filter(fn ($t) => !empty($t['title']))
            ->values()
            ->all();

        return count($trends) ? $trends : null;
    }

    /**
     * Best-effort scrape da pagina publica https://tendencias.mercadolivre.com.br
     * (sem login). Regex simples pega os cards. Se ML mudar HTML, cai no seed.
     */
    private function fetchFromPublicPage(int $limit): ?array
    {
        try {
            $resp = Http::timeout(20)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; HubAI-Bot/1.0)'])
                ->get(self::PUBLIC_URL);
        } catch (\Throwable $e) {
            Log::warning('[SEL-126 ScrapeMlTrends] page exception: ' . $e->getMessage());
            return null;
        }

        if (!$resp->ok()) return null;

        $html = $resp->body();
        $trends = [];

        // Cada card na pagina publica tem um link com o termo. Regex tolerante.
        if (preg_match_all('/<a[^>]+href="([^"]*\/trends[^"]*)"[^>]*>([^<]+)<\/a>/i', $html, $matches)) {
            foreach ($matches[2] as $i => $keyword) {
                $keyword = trim(html_entity_decode($keyword, ENT_QUOTES, 'UTF-8'));
                if (!$keyword || mb_strlen($keyword) < 3) continue;
                $trends[] = [
                    'external_id' => $keyword,
                    'title'       => $keyword,
                    'source_url'  => $matches[1][$i] ?? self::PUBLIC_URL,
                ];
                if (count($trends) >= $limit) break;
            }
        }

        return count($trends) ? $trends : null;
    }

    /**
     * Fallback: 15 keywords hardcode. Usado quando nao ha conexao com ML
     * e o scrape publico falhou — tabela nunca fica vazia em ambiente demo.
     */
    private function seedFallback(): array
    {
        $seed = [
            'kit smart home',
            'fone bluetooth',
            'cadeira gamer',
            'notebook gamer',
            'iphone 15',
            'ar condicionado portatil',
            'aspirador robo',
            'bicicleta eletrica',
            'suplemento whey',
            'ventilador de teto',
            'monitor 4k',
            'perfume masculino',
            'tenis nike',
            'smartwatch xiaomi',
            'purificador de agua',
        ];
        return array_map(fn ($k) => [
            'external_id' => $k,
            'title'       => $k,
            'source_url'  => 'https://lista.mercadolivre.com.br/' . rawurlencode($k),
        ], $seed);
    }
}
