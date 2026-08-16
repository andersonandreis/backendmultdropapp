<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * JT-010: Enriquece catalogo de produtos com brand, model, ncm, ml_category_id,
 * shopee_category_id, ml_attributes, shopee_attributes e recalcula quality scores.
 *
 * Fontes de dados (por prioridade):
 *   1. Match EAN nos outros bancos do mesmo servidor (hubaiapp, multdrop, fornecefy, mestoredrop)
 *   2. API ML domain_discovery (publica, sem auth) para categoria ML
 *   3. Tabela de mapeamento interna NCM/Shopee por tipo de produto
 *   4. Parse do nome/descricao para brand/model
 *
 * Uso:
 *   artisan catalog:enrich --tenant=jtdrop --dry-run --limit=20
 *   artisan catalog:enrich --tenant=jtdrop --limit=200
 */
class EnrichCatalogCommand extends Command
{
    protected $signature = 'catalog:enrich
        {--tenant= : Tenant slug (ex: jtdrop). Obrigatorio.}
        {--dry-run : Apenas exibe o que seria feito, sem gravar}
        {--limit=  : Limitar numero de produtos a processar (default: todos)}
        {--skip=0  : Pular os primeiros N produtos (para retomar)}';

    protected $description = 'JT-010: Enriquece catalogo de produtos (brand, ncm, categorias ML/Shopee, atributos, quality scores)';

    // Bancos de referencia no mesmo servidor (match por EAN para copiar dados preenchidos)
    private const REFERENCE_DBS = [
        'hubaiapp',
        'mestoredropapp_production',
        'dropksrapp_production',
    ];

    // Mapeamento NCM por palavras-chave do produto (NCM Brasil mais comuns para utilidades/eletronicos)
    private const NCM_MAP = [
        // Cozinha / utilidades domesticas
        'abridor'       => '8205.51.00',
        'afiador'       => '8214.20.00',
        'espremedor'    => '8509.40.10',
        'liquidificador'=> '8509.40.10',
        'panela'        => '7323.93.00',
        'frigideira'    => '7323.91.00',
        'forma'         => '7323.99.00',
        'tigela'        => '3924.10.00',
        'pote'          => '3924.10.00',
        'jarra'         => '7013.49.00',
        'copo'          => '7013.37.00',
        'garrafa'       => '3926.90.90',
        'termico'       => '9617.00.00',
        'termica'       => '9617.00.00',
        'faca'          => '8211.91.00',
        'tesoura'       => '8213.00.00',
        'colher'        => '8215.99.00',
        'garfo'         => '8215.99.00',
        'talheres'      => '8215.10.00',
        'escorredor'    => '3924.10.00',
        'peneira'       => '3924.10.00',
        'ralo'          => '3924.10.00',
        'tabua'         => '4419.19.00',
        'suporte'       => '3924.90.00',
        'organizador'   => '3924.90.00',
        'porta'         => '3924.90.00',
        'lixeira'       => '3924.90.00',
        'dispenser'     => '8481.30.00',
        'torneira'      => '8481.80.93',
        'extensor'      => '8481.80.93',
        // Limpeza / casa
        'vassoura'      => '9603.29.00',
        'rodo'          => '9603.90.00',
        'esponja'       => '3924.90.00',
        'escova'        => '9603.90.00',
        'pano'          => '6307.90.10',
        'toalha'        => '6302.60.00',
        'tapete'        => '5705.00.00',
        // Fitness / esporte
        'pilates'       => '9506.91.00',
        'yoga'          => '9506.91.00',
        'anel'          => '9506.91.00',
        'haltere'       => '9506.91.00',
        'corda'         => '9506.91.00',
        'bicicleta'     => '9506.91.00',
        // Eletronicos / eletrodomesticos
        'carregador'    => '8504.40.40',
        'cabo'          => '8544.42.00',
        'adaptador'     => '8504.40.40',
        'tomada'        => '8536.41.00',
        'lampada'       => '9405.40.00',
        'led'           => '9405.40.00',
        'luminaria'     => '9405.20.00',
        'ventilador'    => '8414.51.00',
        'ar condicionado'=> '8415.10.11',
        'radio'         => '8527.91.00',
        'caixa de som'  => '8518.22.00',
        'fone'          => '8518.30.00',
        'headphone'     => '8518.30.00',
        'mouse'         => '8471.60.53',
        'teclado'       => '8471.60.52',
        'webcam'        => '8525.89.29',
        'celular'       => '8517.12.31',
        'smartphone'    => '8517.12.31',
        'tablet'        => '8471.30.12',
        'notebook'      => '8471.30.19',
        // Beleza / pessoal
        'escova cabelo' => '9603.21.00',
        'chapinha'      => '8516.32.00',
        'secador'       => '8516.31.00',
        'maquiagem'     => '3304.99.90',
        'perfume'       => '3303.00.20',
        'esponja maquiagem' => '3304.99.90',
        // Brinquedos
        'brinquedo'     => '9503.00.99',
        'boneca'        => '9502.10.00',
        'carrinho'      => '9501.00.00',
        // Ferramentas
        'chave'         => '8204.11.00',
        'martelo'       => '8205.20.00',
        'alicate'       => '8203.20.00',
        'furadeira'     => '8467.21.00',
        'parafuso'      => '7318.15.00',
        // Papelaria / escritorio
        'caneta'        => '9608.10.00',
        'caderno'       => '4820.10.10',
        'pasta'         => '4820.10.20',
        'mochila'       => '4202.92.20',
        'bolsa'         => '4202.21.00',
        // Default
        'outros'        => '3926.90.90',
    ];

    // Mapeamento simples ML categoria -> Shopee categoria
    // Baseado nos IDs mais comuns do marketplace brasileiro
    private const SHOPEE_CAT_MAP = [
        'MLB5726'  => 11000263, // Eletroportateis
        'MLB1574'  => 11000263, // Cozinha
        'MLB5688'  => 11000179, // Fitness
        'MLB1276'  => 11000179, // Esportes
        'MLB1648'  => 11000030, // Telefonia
        'MLB1051'  => 11000030, // Computadores
        'MLB1000'  => 11000073, // Eletronicos
        'MLB1499'  => 11000073, // Audio
        'MLB43'    => 11000073, // Informatica
        'MLB1368'  => 11000073, // TV/Video
        'MLB5672'  => 11000015, // Casa e Decoracao
        'MLB1459'  => 11000015, // Construcao
        'MLB12'    => 11000015, // Beleza
        'MLB1246'  => 11000028, // Brinquedos
        'MLB1182'  => 11000055, // Ferramentas
        // Default
        'DEFAULT'  => 11000263,
    ];

    // Default Shopee category para utilidades domesticas
    private const SHOPEE_DEFAULT_CAT = 11000263;
    private const ML_DEFAULT_CAT     = 'MLB1574'; // Cozinha e Lares

    private bool $dryRun   = false;
    private int  $enriched = 0;
    private int  $skipped  = 0;
    private int  $errors   = 0;

    public function handle(): int
    {
        $tenant = $this->option('tenant');
        if (empty($tenant)) {
            $this->error('--tenant e obrigatorio. Ex: --tenant=jtdrop');
            return 1;
        }

        $this->dryRun = (bool) $this->option('dry-run');
        $limit  = $this->option('limit') ? (int) $this->option('limit') : null;
        $skip   = (int) ($this->option('skip') ?? 0);

        $this->info("[JT-010] catalog:enrich iniciado | tenant={$tenant} | dry-run=" . ($this->dryRun ? 'SIM' : 'NAO') . " | limit=" . ($limit ?? 'todos'));

        // Buscar produtos sem enriquecimento completo
        // MariaDB nao suporta OFFSET sem LIMIT — usar cursor por id quando skip > 0
        $query = DB::table('products')
            ->where(function($q) {
                $q->whereNull('brand')
                  ->orWhereNull('ml_category_id')
                  ->orWhereNull('shopee_category_id')
                  ->orWhereNull('ncm');
            })
            ->when($skip > 0, fn($q) => $q->where('id', '>', $skip))
            ->orderBy('id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $products = $query->get(['id', 'sku', 'name', 'description', 'ean', 'gtin', 'brand', 'model', 'ncm', 'ml_category_id', 'shopee_category_id', 'ml_attributes', 'shopee_attributes', 'category_id', 'is_active']);

        $this->info("Produtos a processar: " . $products->count());

        // Cache EAN reference data de outros bancos
        $eanReferenceCache = [];
        if ($products->count() > 0) {
            $eans = $products->pluck('ean')->filter()->unique()->values()->toArray();
            if (!empty($eans)) {
                $eanReferenceCache = $this->fetchEanReferences($eans);
                $this->info("Referencia EAN carregada: " . count($eanReferenceCache) . " matches nos outros bancos");
            }
        }

        $bar = $this->output->createProgressBar($products->count());
        $bar->start();

        foreach ($products as $product) {
            try {
                $this->enrichProduct($product, $eanReferenceCache);
                $this->enriched++;
            } catch (\Throwable $e) {
                $this->errors++;
                Log::error('[JT-010][EnrichCatalog] Erro ao enriquecer produto', [
                    'product_id' => $product->id,
                    'sku'        => $product->sku,
                    'error'      => $e->getMessage(),
                ]);
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("[JT-010] Concluido | enriched={$this->enriched} | skipped={$this->skipped} | errors={$this->errors}");

        return 0;
    }

    private function enrichProduct(object $product, array $eanRefCache): void
    {
        $updates = [];

        // ----------------------------------------------------------------
        // 1. Brand e Model: match por EAN nos outros bancos, depois parse
        // ----------------------------------------------------------------
        $ref = $eanRefCache[$product->ean ?? ''] ?? $eanRefCache[$product->gtin ?? ''] ?? null;

        if (empty($product->brand)) {
            if ($ref && !empty($ref['brand'])) {
                $updates['brand'] = mb_substr($ref['brand'], 0, 255);
            } else {
                $updates['brand'] = $this->parseBrandFromName($product->name, $product->description ?? '');
            }
        }

        if (empty($product->model)) {
            if ($ref && !empty($ref['model'])) {
                $updates['model'] = mb_substr($ref['model'], 0, 100);
            } else {
                $updates['model'] = $this->parseModelFromName($product->name);
            }
        }

        // ----------------------------------------------------------------
        // 2. NCM: match por EAN, depois inferir por categoria/nome
        // ----------------------------------------------------------------
        if (empty($product->ncm)) {
            if ($ref && !empty($ref['ncm'])) {
                $updates['ncm'] = $ref['ncm'];
            } else {
                $updates['ncm'] = $this->inferNcm($product->name, $product->description ?? '');
            }
        }

        // ----------------------------------------------------------------
        // 3. ML Category: match por EAN, depois domain_discovery
        // ----------------------------------------------------------------
        $mlCategoryId = $product->ml_category_id;
        if (empty($mlCategoryId)) {
            if ($ref && !empty($ref['ml_category_id'])) {
                $mlCategoryId = $ref['ml_category_id'];
                $updates['ml_category_id'] = $mlCategoryId;
            } else {
                $mlCategoryId = $this->fetchMlCategory($product->name);
                $updates['ml_category_id'] = $mlCategoryId;
            }
        }

        // ----------------------------------------------------------------
        // 4. Shopee Category: mapeamento do ML ou tabela interna
        // ----------------------------------------------------------------
        if (empty($product->shopee_category_id)) {
            if ($ref && !empty($ref['shopee_category_id'])) {
                $updates['shopee_category_id'] = (int) $ref['shopee_category_id'];
            } else {
                $updates['shopee_category_id'] = $this->mapShopeeCategory($mlCategoryId);
            }
        }

        // ----------------------------------------------------------------
        // 5. ML Attributes (formato JSON com BRAND, MODEL, GTIN)
        // ----------------------------------------------------------------
        if (empty($product->ml_attributes)) {
            $brand = $updates['brand'] ?? $product->brand ?? 'Generico';
            $model = $updates['model'] ?? $product->model ?? $product->name;
            $gtin  = $product->ean ?? $product->gtin ?? '';

            $mlAttrs = [
                ['id' => 'BRAND', 'value_name' => $brand],
                ['id' => 'MODEL', 'value_name' => $model],
            ];
            if (!empty($gtin)) {
                $mlAttrs[] = ['id' => 'GTIN', 'value_name' => $gtin];
            }
            $updates['ml_attributes'] = json_encode($mlAttrs);
        }

        // ----------------------------------------------------------------
        // 6. Shopee Attributes (formato JSON com BRAND, MODEL, GTIN)
        // ----------------------------------------------------------------
        if (empty($product->shopee_attributes)) {
            $brand = $updates['brand'] ?? $product->brand ?? 'Generico';
            $model = $updates['model'] ?? $product->model ?? $product->name;
            $gtin  = $product->ean ?? $product->gtin ?? '';

            $shopeeAttrs = [
                'brand' => $brand,
                'model' => $model,
            ];
            if (!empty($gtin)) {
                $shopeeAttrs['gtin'] = $gtin;
            }
            $updates['shopee_attributes'] = json_encode($shopeeAttrs);
        }

        if (empty($updates)) {
            $this->skipped++;
            return;
        }

        if ($this->dryRun) {
            $this->line("\n[DRY-RUN] Produto #{$product->id} ({$product->sku}): " . json_encode($updates, JSON_UNESCAPED_UNICODE));
            return;
        }

        $updates['updated_at'] = now();
        DB::table('products')->where('id', $product->id)->update($updates);

        // Recalcular quality scores apos enriquecimento
        $this->recalculateQualityScores($product->id);

        Log::info('[JT-010][EnrichCatalog] Produto enriquecido', [
            'product_id' => $product->id,
            'sku'        => $product->sku,
            'fields'     => array_keys($updates),
        ]);
    }

    /**
     * Busca dados de referencia por EAN nos outros bancos do servidor.
     * Retorna array indexado por EAN.
     */
    private function fetchEanReferences(array $eans): array
    {
        $result = [];
        $placeholders = implode(',', array_fill(0, count($eans), '?'));

        foreach (self::REFERENCE_DBS as $db) {
            try {
                $rows = DB::select(
                    "SELECT ean, gtin, brand, model, ncm, ml_category_id, shopee_category_id
                     FROM {$db}.products
                     WHERE (ean IN ({$placeholders}) OR gtin IN ({$placeholders}))
                       AND (brand IS NOT NULL OR ml_category_id IS NOT NULL OR ncm IS NOT NULL)
                     LIMIT 2000",
                    array_merge($eans, $eans)
                );

                foreach ($rows as $row) {
                    $key = $row->ean ?? $row->gtin ?? null;
                    if ($key && empty($result[$key])) {
                        $result[$key] = (array) $row;
                    }
                }
            } catch (\Throwable $e) {
                // Banco pode nao existir ou nao ter acesso — continua
                Log::debug("[JT-010][EnrichCatalog] DB {$db} skip: " . $e->getMessage());
            }
        }

        return $result;
    }

    /**
     * Busca categoria ML via domain_discovery (endpoint publico, sem auth).
     */
    private function fetchMlCategory(string $productName): string
    {
        try {
            $cleanName = mb_substr(preg_replace('/[^a-zA-Z0-9\s\xC0-\xFF]/u', ' ', $productName), 0, 100);
            $url = 'https://api.mercadolibre.com/sites/MLB/domain_discovery/search?limit=1&q=' . urlencode($cleanName);

            $response = Http::timeout(8)->get($url);

            if ($response->successful()) {
                $data = $response->json();
                if (!empty($data[0]['category_id'])) {
                    return $data[0]['category_id'];
                }
            }
        } catch (\Throwable $e) {
            Log::debug('[JT-010][EnrichCatalog] ML domain_discovery falhou: ' . $e->getMessage());
        }

        return self::ML_DEFAULT_CAT;
    }

    /**
     * Mapeia categoria ML para categoria Shopee usando tabela interna.
     */
    private function mapShopeeCategory(string $mlCategoryId): int
    {
        // Verificar prefixo da categoria (ex: MLB5726 -> MLB5726 ou MLB57XX)
        foreach (self::SHOPEE_CAT_MAP as $mlPrefix => $shopeeId) {
            if (str_starts_with($mlCategoryId, $mlPrefix)) {
                return $shopeeId;
            }
        }

        // Tentar pelo prefixo de 5 chars
        $prefix5 = mb_substr($mlCategoryId, 0, 8);
        if (isset(self::SHOPEE_CAT_MAP[$prefix5])) {
            return self::SHOPEE_CAT_MAP[$prefix5];
        }

        return self::SHOPEE_DEFAULT_CAT;
    }

    /**
     * Infere NCM por palavras-chave no nome/descricao do produto.
     */
    private function inferNcm(string $name, string $description): string
    {
        $text = mb_strtolower($name . ' ' . mb_substr($description, 0, 200));

        foreach (self::NCM_MAP as $keyword => $ncm) {
            if (str_contains($text, $keyword)) {
                return $ncm;
            }
        }

        return '3926.90.90'; // Outros artigos de plastico (default seguro)
    }

    /**
     * Tenta extrair marca do nome/descricao do produto.
     * Fallback para "Generico".
     */
    private function parseBrandFromName(string $name, string $description): string
    {
        // Marcas conhecidas para buscar no nome
        $knownBrands = [
            'tramontina', 'mondial', 'arno', 'philips', 'samsung', 'lg', 'sony',
            'fischer', 'intex', 'britania', 'consul', 'brastemp', 'electrolux',
            'multilaser', 'positivo', 'xiaomi', 'huawei', 'motorola', 'apple',
            'nike', 'adidas', 'speedo', 'reebok', 'mizuno', 'asics',
        ];

        $text = mb_strtolower($name . ' ' . mb_substr($description, 0, 100));

        foreach ($knownBrands as $brand) {
            if (str_contains($text, $brand)) {
                return ucfirst($brand);
            }
        }

        return 'Generico';
    }

    /**
     * Tenta extrair modelo do nome do produto (ultima parte apos palavras descritivas).
     */
    private function parseModelFromName(string $name): string
    {
        // Pegar as primeiras 2-3 palavras como modelo simplificado
        $words = explode(' ', trim($name));
        return mb_substr(implode(' ', array_slice($words, 0, 3)), 0, 100);
    }

    /**
     * Recalcula quality_score_ml e quality_score_shopee para um produto.
     * Usa a mesma logica do ProductQualityService (simplificada para evitar
     * carregar Eloquent model completo em batch).
     */
    private function recalculateQualityScores(int $productId): void
    {
        $product = DB::table('products')->where('id', $productId)->first();
        if (!$product) {
            return;
        }

        $score  = 0;
        $issues = [];

        // Titulo
        $title = $product->ai_title ?: $product->name;
        if ($title && mb_strlen(trim($title)) > 3) {
            $score += 10;
        } else {
            $issues[] = 'Sem titulo definido';
        }

        // Descricao
        $desc = $product->ai_description ?: $product->description;
        if ($desc && mb_strlen(trim($desc)) > 200) {
            $score += 10;
        } elseif ($desc && mb_strlen(trim($desc)) > 0) {
            $issues[] = 'Descricao muito curta';
        } else {
            $issues[] = 'Sem descricao';
        }

        // Imagens (sem carregar media, apenas pontuar 0 = sem imagens)
        // Sera recalculado quando usuario abrir o produto
        $issues[] = 'Imagens a verificar';

        // Marca
        if (!empty($product->brand)) {
            $score += 10;
        } else {
            $issues[] = 'Sem marca';
        }

        // EAN
        if (!empty($product->ean) || !empty($product->gtin)) {
            $score += 5;
        } else {
            $issues[] = 'Sem EAN';
        }

        // Dimensoes
        if ($product->weight_kg && $product->height_cm && $product->width_cm && $product->length_cm) {
            $score += 10;
        } else {
            $issues[] = 'Dimensoes incompletas';
        }

        // Categoria
        if ($product->ml_category_id || $product->shopee_category_id || $product->category_id) {
            $score += 10;
        } else {
            $issues[] = 'Sem categoria';
        }

        // Atributos
        if (!empty($product->ml_attributes) || !empty($product->shopee_attributes)) {
            $score += 10;
        } else {
            $issues[] = 'Sem atributos';
        }

        $finalScore = min($score, 100);

        DB::table('products')->where('id', $productId)->update([
            'quality_score_ml'     => $finalScore,
            'quality_score_shopee' => $finalScore,
            'quality_issues'       => json_encode($issues),
        ]);
    }
}
