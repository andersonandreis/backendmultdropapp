<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * SEL-086 / SEL-148 - Enriquece registros da tabela tiktok_shop_trends com:
 *   - matched_supplier_id / matched_supplier_score: fornecedor da directory_suppliers
 *   - avg_rating / review_count / commission_rate: dados de produto
 *
 * Estrategia para avg_rating/review_count/commission_rate (SEL-148):
 *   1. Tenta extrair do campo raw (quando coletor Playwright ja capturou)
 *   2. Se nao encontrou no raw, tenta TikTok Affiliate API (requer shop conectado)
 *
 * Roda no schedule diario 08:15 BRT (routes/console.php). Idempotente.
 */
class EnrichTiktokTrendsCommand extends Command
{
    protected $signature = 'tiktok:enrich-trends
                            {--kind=all : product|creator|live|all}
                            {--skip-affiliate : pula busca de dados da Affiliate API}
                            {--only-affiliate : so busca dados da Affiliate API, pula match de fornecedor}';

    protected $description = 'Enriquece tiktok_shop_trends com matched_supplier + avg_rating/review/commission (SEL-086, SEL-148)';

    private string $appKey;
    private string $appSecret;
    private string $baseUrl;

    public function handle(): int
    {
        $this->appKey    = env('TIKTOK_APP_KEY', '');
        $this->appSecret = env('TIKTOK_APP_SECRET', '');
        $this->baseUrl   = rtrim(env('TIKTOK_API_URL', 'https://open-api.tiktokglobalshop.com'), '/');

        $kind          = $this->option('kind');
        $skipAffiliate = $this->option('skip-affiliate');
        $onlyAffiliate = $this->option('only-affiliate');

        $query = DB::table('tiktok_shop_trends');
        if ($kind !== 'all') {
            $query->where('kind', $kind);
        }
        $count = (clone $query)->count();
        $this->info("Processando {$count} registros (kind={$kind})...");

        if (!$onlyAffiliate) {
            $this->runSupplierMatch($query);
        }

        if (!$skipAffiliate) {
            if (!$this->appKey || !$this->appSecret) {
                $this->warn('TIKTOK_APP_KEY ou TIKTOK_APP_SECRET ausente - pulando enriquecimento de produto.');
            } else {
                $this->runProductDetailEnrich($query);
            }
        }

        $this->info('tiktok:enrich-trends concluido.');
        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Supplier match (SEL-086, logica preservada)
    // -------------------------------------------------------------------------

    private function runSupplierMatch($baseQuery): void
    {
        $suppliers = DB::table('directory_suppliers')
            ->select('id', 'name', 'categories')
            ->where('is_active', 1)
            ->get()
            ->map(function ($s) {
                $cats = [];
                if ($s->categories) {
                    $decoded = json_decode($s->categories, true);
                    if (is_array($decoded)) {
                        $cats = array_map('mb_strtolower', $decoded);
                    }
                }
                return [
                    'id'         => $s->id,
                    'name'       => mb_strtolower($s->name),
                    'categories' => $cats,
                ];
            });

        $processed = 0;
        $matched   = 0;

        (clone $baseQuery)->orderBy('id')->chunkById(200, function ($rows) use ($suppliers, &$processed, &$matched) {
            foreach ($rows as $row) {
                $title = mb_strtolower($row->title ?? '');
                $cats  = array_filter([$row->category_l1 ?? '', $row->category_l2 ?? '', $row->category_l3 ?? '']);
                $cats  = array_map('mb_strtolower', $cats);

                $bestId    = null;
                $bestScore = 0;

                foreach ($suppliers as $sup) {
                    $score = 0;
                    if ($sup['name'] && (Str::contains($title, $sup['name']) || Str::contains($sup['name'], explode(' ', $title)[0] ?? '_'))) {
                        $score += 40;
                    }
                    foreach ($cats as $c) {
                        foreach ($sup['categories'] as $sc) {
                            if ($sc && (Str::contains($c, $sc) || Str::contains($sc, $c))) {
                                $score += 20;
                                break 2;
                            }
                        }
                    }
                    if (count($sup['categories']) > 0 && count($cats) > 0) {
                        $score += 5;
                    }
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestId    = $sup['id'];
                    }
                }

                if ($bestId && $bestScore >= 25) {
                    DB::table('tiktok_shop_trends')
                        ->where('id', $row->id)
                        ->update([
                            'matched_supplier_id'    => $bestId,
                            'matched_supplier_score' => min(100, $bestScore),
                            'enriched_at'            => now(),
                        ]);
                    $matched++;
                }
                $processed++;
            }
        });

        $this->info("Supplier match: processados={$processed}, matched={$matched}.");
        Log::info('tiktok:enrich-trends supplier-match', compact('processed', 'matched'));
    }

    // -------------------------------------------------------------------------
    // Product detail enrich (SEL-148)
    // -------------------------------------------------------------------------

    /**
     * Para cada produto kind=product sem avg_rating ainda:
     *   1. Tenta extrair do campo raw (coletor Playwright pode incluir esses dados)
     *   2. Se nao encontrou no raw, tenta TikTok Affiliate API via app_key+sign
     *
     * A TikTok Affiliate API publica (sem access_token) retorna 400 pois requer
     * shop autenticado. Quando houver uma conexao ativa em tiktok_shop_connections,
     * o metodo fetchAffiliateProduct pode usar esse token.
     *
     * Por ora a extracao do raw e o caminho principal. Os campos sao adicionados
     * ao payload pelo coletor quando visita a pagina de detalhes do produto.
     */
    private function runProductDetailEnrich($baseQuery): void
    {
        $this->info('Enriquecendo produtos com avg_rating / review_count / commission_rate...');

        $rows = (clone $baseQuery)
            ->where('kind', 'product')
            ->where('source', 'tiktok_shop')
            ->whereNull('avg_rating')
            ->whereNotNull('external_id')
            ->whereRaw("external_id != ''")
            ->orderBy('id')
            ->limit(300)
            ->get(['id', 'title', 'external_id', 'category_l1', 'raw']);

        $this->info("Produtos para enriquecer: {$rows->count()}");

        // Verifica se ha conexao TikTok ativa antes do loop (SEL-148)
        $hasConnection = DB::table('tiktok_shop_connections')
            ->where('status', 'active')
            ->whereNotNull('access_token')
            ->whereRaw("access_token != ''")
            ->exists();

        $fromRaw  = 0;
        $fromApi  = 0;
        $notFound = 0;
        $errors   = 0;

        foreach ($rows as $row) {
            try {
                // Etapa 1: extrair do raw (gratuito, sem API)
                $data = $this->extractFromRaw($row->raw ?? null);

                // Etapa 2: tentar Affiliate API se raw nao tiver os dados
                if ($data === null) {
                    $data = $this->fetchAffiliateProduct($row->title, $row->category_l1);
                    if ($data !== null) {
                        $fromApi++;
                    }
                } else {
                    $fromRaw++;
                }

                if ($data === null) {
                    $notFound++;
                    usleep(200000);
                    continue;
                }

                DB::table('tiktok_shop_trends')
                    ->where('id', $row->id)
                    ->update([
                        'avg_rating'      => $data['avg_rating'],
                        'review_count'    => $data['review_count'],
                        'commission_rate' => $data['commission_rate'],
                        'enriched_at'     => now(),
                        'updated_at'      => now(),
                    ]);

                usleep(100000);
            } catch (\Exception $e) {
                Log::warning('tiktok:enrich-trends product-detail error', [
                    'id'      => $row->id,
                    'title'   => $row->title,
                    'message' => $e->getMessage(),
                ]);
                $errors++;
            }
        }

        $this->info("Product detail enrich: from_raw={$fromRaw}, from_api={$fromApi}, not_found={$notFound}, errors={$errors}.");
        Log::info('tiktok:enrich-trends product-detail', compact('fromRaw', 'fromApi', 'notFound', 'errors'));
    }

    /**
     * Tenta extrair avg_rating, review_count, commission_rate do campo raw.
     * Util quando o coletor Playwright ja capturou esses campos na pagina do produto
     * (ex: raspar a pagina de detalhe do produto no Seller Center ou Affiliate Market).
     */
    private function extractFromRaw(?string $rawJson): ?array
    {
        if (!$rawJson) return null;
        $raw = json_decode($rawJson, true);
        if (!is_array($raw)) return null;

        $commissionRate = null;
        if (isset($raw['commission_rate'])) {
            $cr = (float) $raw['commission_rate'];
            $commissionRate = ($cr > 0 && $cr <= 1.0) ? round($cr * 100, 2) : round($cr, 2);
        }

        $avgRating   = isset($raw['avg_rating'])   ? (float) $raw['avg_rating']  : null;
        $reviewCount = isset($raw['review_count'])  ? (int)   $raw['review_count'] : null;

        if ($commissionRate === null && $avgRating === null && $reviewCount === null) {
            return null;
        }

        return [
            'avg_rating'      => $avgRating,
            'review_count'    => $reviewCount,
            'commission_rate' => $commissionRate,
        ];
    }

    /**
     * Busca produto na TikTok Affiliate Products API.
     * NOTA: Requer access_token de shop autenticado (tiktok_shop_connections).
     * Enquanto nao houver conexao ativa, retorna null e registra no log.
     *
     * Quando uma conexao estiver disponivel, busca pelo titulo do produto
     * e retorna avg_rating, review_count, commission_rate do primeiro resultado.
     */
    private function fetchAffiliateProduct(string $title, ?string $categoryL1): ?array
    {
        // Verifica se ha conexao TikTok Shop ativa
        $connection = DB::table('tiktok_shop_connections')
            ->whereNotNull('access_token')
            ->where(function ($q) {
                $q->whereNull('access_token_expire_at')
                  ->orWhereRaw('access_token_expire_at > UNIX_TIMESTAMP()');
            })
            ->first();

        if (!$connection) {
            // Sem conexao ativa - logado apenas em debug para nao poluir logs
            Log::debug('tiktok:enrich-trends fetchAffiliateProduct: sem conexao ativa');
            return null;
        }

        $path      = '/affiliate/202309/products/search';
        $timestamp = time();

        $queryParams = [
            'app_key'   => $this->appKey,
            'timestamp' => $timestamp,
        ];

        $body = [
            'keyword'     => mb_substr($title, 0, 100),
            'page_size'   => 5,
            'page_number' => 1,
            'sort_type'   => 2,
            'sort_order'  => 1,
        ];

        $sign = $this->buildSign($path, $queryParams);
        $queryParams['sign'] = $sign;

        $url = $this->baseUrl . $path . '?' . http_build_query($queryParams);

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'Content-Type'       => 'application/json',
                    'x-tts-access-token' => $connection->access_token,
                ])
                ->post($url, $body);

            if ($response->failed()) {
                Log::debug('tiktok affiliate API failed', [
                    'status' => $response->status(),
                    'body'   => mb_substr($response->body(), 0, 300),
                ]);
                return null;
            }

            $json = $response->json();

            if (($json['code'] ?? -1) !== 0) {
                Log::debug('tiktok affiliate API code != 0', [
                    'code'    => $json['code'] ?? null,
                    'message' => $json['message'] ?? null,
                ]);
                return null;
            }

            $products = $json['data']['products'] ?? [];
            if (empty($products)) {
                return null;
            }

            $p = $products[0];

            $commissionRate = null;
            if (isset($p['commission_rate'])) {
                $raw = (float) $p['commission_rate'];
                $commissionRate = ($raw > 0 && $raw <= 1.0) ? round($raw * 100, 2) : round($raw, 2);
            }

            $avgRating = null;
            if (isset($p['avg_rating'])) {
                $avgRating = (float) $p['avg_rating'];
            } elseif (isset($p['ratings']['avg'])) {
                $avgRating = (float) $p['ratings']['avg'];
            }

            $reviewCount = null;
            if (isset($p['review_count'])) {
                $reviewCount = (int) $p['review_count'];
            } elseif (isset($p['ratings']['count'])) {
                $reviewCount = (int) $p['ratings']['count'];
            }

            if ($commissionRate === null && $avgRating === null && $reviewCount === null) {
                return null;
            }

            return [
                'avg_rating'      => $avgRating,
                'review_count'    => $reviewCount,
                'commission_rate' => $commissionRate,
            ];
        } catch (\Exception $e) {
            Log::warning('tiktok:enrich-trends fetchAffiliateProduct exception', [
                'message' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * HMAC-SHA256 conforme spec TikTok Shop Open API v2.
     * baseString = appSecret + path + sorted_params_concat + appSecret
     */
    private function buildSign(string $path, array $queryParams): string
    {
        $params = $queryParams;
        unset($params['sign'], $params['access_token']);
        ksort($params);

        $paramStr = '';
        foreach ($params as $k => $v) {
            $paramStr .= $k . (is_array($v) ? json_encode($v) : (string) $v);
        }

        $baseString = $this->appSecret . $path . $paramStr . $this->appSecret;

        return hash_hmac('sha256', $baseString, $this->appSecret);
    }
}