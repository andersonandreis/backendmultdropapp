<?php

namespace App\Console\Commands;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\ImageDownloadService;
use App\Services\Integrations\Cdn\BunnyCdnService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MES-046-E2: Importa APENAS os produtos-componente necessarios para resolver
 * component_product_id nos product_bundles do supplier 25 (dep 447).
 *
 * Regra Ruan: importar somente o minimo necessario — nao poluir o catalogo.
 *
 * Uso:
 *   php artisan import:bundle-components --deposito=447 --supplier=25
 *   php artisan import:bundle-components --dry-run
 */
class ImportBundleComponentsCommand extends Command
{
    protected $signature = 'import:bundle-components
        {--deposito=447 : ID do deposito legado}
        {--supplier=25  : Supplier ID no banco novo}
        {--dry-run      : Mostra o que seria importado sem persistir}';

    protected $description = 'MES-046-E2: Importa componentes de kits faltantes no product_bundles (idempotente)';

    private int $created  = 0;
    private int $updated  = 0;
    private int $skipped  = 0;
    private int $images   = 0;
    private int $errors   = 0;
    private int $notFound = 0;

    public function handle(): int
    {
        $depositoId = (int) $this->option('deposito');
        $supplierId = (int) $this->option('supplier');
        $dryRun     = (bool) $this->option('dry-run');

        $this->info('=== MES-046-E2: IMPORT BUNDLE COMPONENTS ===');
        $this->info("Deposito legado: {$depositoId} | Supplier: {$supplierId}" . ($dryRun ? ' | DRY-RUN' : ''));

        // 1. Conectar ao legado
        try {
            DB::connection('legacy')->selectOne('SELECT 1 AS ok');
            $this->info('Conexao com legado OK');
        } catch (\Throwable $e) {
            $this->error('Falha conexao legado: ' . $e->getMessage());
            return self::FAILURE;
        }

        // 2. Supplier deve existir
        $supplier = Supplier::find($supplierId);
        if (!$supplier) {
            $this->error("Supplier ID={$supplierId} nao encontrado.");
            return self::FAILURE;
        }
        $this->info("Supplier: {$supplier->company_name}");

        // 3. Levantar todos os legacy_sku_pai_id referenciados nos kits ativos do deposito
        $this->info('Levantando componentes necessarios nos kits ativos do legado...');
        $neededIds = DB::connection('legacy')
            ->table('sku_pai_kit as k')
            ->join('sku_pai_kit_item as ki', 'ki.id_sku_pai_kit', '=', 'k.id')
            ->where('k.id_deposito', $depositoId)
            ->where('k.status', 1)
            ->distinct()
            ->pluck('ki.id_sku_pai')
            ->map(fn($v) => (int) $v)
            ->toArray();

        $totalNeeded = count($neededIds);
        $this->info("Componentes unicos referenciados: {$totalNeeded}");

        if ($totalNeeded === 0) {
            $this->warn('Nenhum componente necessario. Nada a fazer.');
            return self::SUCCESS;
        }

        // 4. Filtrar apenas os que NAO existem ainda no banco novo
        $existingIds = Product::where('supplier_id', $supplierId)
            ->whereIn('legacy_sku_pai_id', $neededIds)
            ->pluck('legacy_sku_pai_id')
            ->map(fn($v) => (int) $v)
            ->toArray();

        $missingIds = array_values(array_diff($neededIds, $existingIds));
        $this->info("Ja existem no banco novo: " . count($existingIds));
        $this->info("Faltam importar: " . count($missingIds));

        if (empty($missingIds)) {
            $this->info('Todos os componentes ja estao no banco novo. Nada a importar.');
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('[DRY-RUN] IDs a importar: ' . implode(', ', array_slice($missingIds, 0, 20)) . (count($missingIds) > 20 ? '...' : ''));
            return self::SUCCESS;
        }

        // 5. Buscar dados desses produtos no legado e importar
        $this->info('Importando componentes faltantes...');

        $chunks = array_chunk($missingIds, 50);
        $bar    = $this->output->createProgressBar(count($missingIds));

        foreach ($chunks as $chunk) {
            $rows = DB::connection('legacy')
                ->table('sku_pai')
                ->whereIn('id', $chunk)
                ->where('id_deposito', $depositoId)
                ->select([
                    'id', 'sku', 'descricao', 'desc_produto',
                    'custo', 'custo_curso', 'estoque',
                    'peso', 'largura', 'altura', 'comprimento',
                    'marca', 'ean', 'ncm', 'origem',
                    'img',
                    'meli_atributos', 'shopee_atributos',
                    'id_deposito', 'cor', 'tamanho', 'garantia',
                    'data_add', 'data_update',
                ])
                ->get();

            // Produtos que estavam no chunk mas nao retornaram do legado
            $foundIds = $rows->pluck('id')->map(fn($v) => (int) $v)->toArray();
            $notFoundInChunk = array_diff($chunk, $foundIds);
            foreach ($notFoundInChunk as $nfId) {
                $this->warn("  AVISO: sku_pai.id={$nfId} nao encontrado no legado (deposito mismatch ou deletado)");
                $this->notFound++;
                $bar->advance();
            }

            foreach ($rows as $row) {
                $this->processProduct($row, $supplier);
                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine();

        // 6. Relatorio
        $this->info('=== RESULTADO ===');
        $this->table(
            ['Metrica', 'Valor'],
            [
                ['Componentes necessarios (kits ativos)',  $totalNeeded],
                ['Ja existiam no banco novo',              count($existingIds)],
                ['Importados agora (criados)',             $this->created],
                ['Atualizados (existiam por SKU)',         $this->updated],
                ['Imagens importadas',                     $this->images],
                ['Nao encontrados no legado (irrecup.)',   $this->notFound],
                ['Erros',                                  $this->errors],
            ]
        );

        $total = Product::where('supplier_id', $supplierId)->whereIn('legacy_sku_pai_id', $neededIds)->count();
        $this->info("Total de componentes disponiveis no banco novo apos import: {$total} / {$totalNeeded}");

        return self::SUCCESS;
    }

    private function processProduct(object $row, Supplier $supplier): void
    {
        try {
            $rawSku = $row->sku ?: "SKU-{$row->id}";
            $sku    = "D{$row->id_deposito}-{$rawSku}";

            $attributes = [];
            if ($row->meli_atributos) {
                $meli = json_decode($row->meli_atributos, true);
                if (is_array($meli)) $attributes['mercadolivre'] = $meli;
            }
            if ($row->shopee_atributos) {
                $shopee = json_decode($row->shopee_atributos, true);
                if (is_array($shopee)) $attributes['shopee'] = $shopee;
            }
            if ($row->cor)     $attributes['cor']     = $row->cor;
            if ($row->tamanho) $attributes['tamanho'] = $row->tamanho;

            $name        = mb_convert_encoding($row->descricao    ?? 'Sem nome', 'UTF-8', 'auto');
            $description = mb_convert_encoding($row->desc_produto ?? '',          'UTF-8', 'auto');

            $product = Product::where('legacy_sku_pai_id', $row->id)->first()
                ?: Product::where('sku', $sku)->where('supplier_id', $supplier->id)->first();

            $data = [
                'legacy_sku_pai_id' => (int) $row->id,
                'supplier_id'       => $supplier->id,
                'sku'               => $sku,
                'name'              => $name,
                'description'       => $description ?: null,
                'price'             => (float) ($row->custo_curso ?: $row->custo ?: 0),
                'cost'              => (float) ($row->custo ?: 0),
                'ean'               => $row->ean    ?: null,
                'brand'             => $row->marca   ? mb_convert_encoding($row->marca, 'UTF-8', 'auto') : null,
                'weight_kg'         => (float) ($row->peso        ?: 0),
                'height_cm'         => (float) ($row->altura      ?: 0),
                'width_cm'          => (float) ($row->largura     ?: 0),
                'length_cm'         => (float) ($row->comprimento ?: 0),
                'ncm'               => $row->ncm       ?: null,
                'is_active'         => true,
                'attributes'        => !empty($attributes) ? $attributes : null,
            ];

            if ($product) {
                $product->update($data);
                $this->updated++;
            } else {
                $product = Product::create($data);
                $this->created++;
            }

            // Inventory
            Inventory::updateOrCreate(
                ['product_id' => $product->id, 'warehouse_id' => $supplier->id],
                [
                    'producer_id' => $supplier->id,
                    'quantity'    => max(0, (int) ($row->estoque ?: 0)),
                ]
            );

            // Imagens
            $this->importImages($product, $row->img);

        } catch (\Throwable $e) {
            Log::warning("MES-046-E2: componente legado id={$row->id} falhou: " . $e->getMessage());
            $this->errors++;
        }
    }

    /**
     * MUL-146 FIX: faz download + upload BunnyCDN + grava content_hash.
     * Regra: NUNCA gravar linha em product_media sem arquivo real no CDN.
     * UNIQUE(product_id, content_hash) — colisao = skip (idempotente).
     */
    private function importImages(Product $product, ?string $imgField): void
    {
        if (!$imgField) {
            return;
        }

        $decoded = json_decode($imgField, true);
        if (is_array($decoded)) {
            $images = $decoded;
        } elseif (str_contains($imgField, '|')) {
            $images = explode('|', $imgField);
        } elseif (str_contains($imgField, ',')) {
            $images = explode(',', $imgField);
        } else {
            $images = [$imgField];
        }

        $baseUrl     = 'https://goolhub.io/imagens/produtos/';
        $baseStorage = storage_path('app/public/');
        $cdn         = app(BunnyCdnService::class);
        $cdnBase     = rtrim(config('services.bunnycdn.pull_zone_url', 'https://multdrop-images.b-cdn.net'), '/');

        foreach ($images as $index => $img) {
            $img = trim($img);
            if (!$img) {
                continue;
            }

            $origUrl = str_starts_with($img, 'http') ? $img : $baseUrl . $img;
            $origUrl = str_replace('www.sistemagrupoonline.com.br', 'goolhub.io', $origUrl);
            $origUrl = str_replace('://sistemagrupoonline.com.br',  '://goolhub.io', $origUrl);

            try {
                // 1. Baixar para storage local (retorna path relativo + url local)
                $mat = app(ImageDownloadService::class)->downloadAndStoreImage(
                    $origUrl,
                    (int) $product->supplier_id,
                    (int) $product->id
                );

                if ($mat === null) {
                    // Imagem inacessivel — nao cria linha fantasma
                    Log::debug("MUL-146: imagem inacessivel, pulando url={$origUrl}");
                    continue;
                }

                $localRelPath = $mat['path']; // products/1/{id}/hash.ext
                $localAbsPath = $baseStorage . $localRelPath;

                if (!file_exists($localAbsPath)) {
                    Log::warning("MUL-146: arquivo local ausente apos download url={$origUrl} path={$localAbsPath}");
                    continue;
                }

                // 2. Calcular content_hash (dedup por conteudo)
                $contentHash = md5_file($localAbsPath);

                // 3. Verificar colisao UNIQUE(product_id, content_hash) — idempotente
                $existingByHash = \App\Models\ProductMedia::where('product_id', $product->id)
                    ->where('content_hash', $contentHash)
                    ->first();
                if ($existingByHash) {
                    continue;
                }

                // 4. Upload para BunnyCDN ANTES de gravar no banco
                $remotePath = $localRelPath; // products/1/{id}/hash.ext
                $uploaded   = $cdn->upload($localAbsPath, $remotePath);

                if (!$uploaded) {
                    Log::warning("MUL-146: BunnyCDN upload falhou url={$origUrl} remote={$remotePath}");
                    continue;
                }

                // 5. URL CDN confirmada
                $cdnUrl = $cdnBase . '/' . ltrim($remotePath, '/');

                // 6. Gravar linha com content_hash e URL CDN real (arquivo garantido no CDN)
                $created = \App\Models\ProductMedia::firstOrCreate(
                    ['product_id' => $product->id, 'content_hash' => $contentHash],
                    [
                        'type'         => 'image',
                        'url'          => $cdnUrl,
                        'original_url' => $origUrl,
                        'local_path'   => $localRelPath,
                        'position'     => $index,
                        'content_hash' => $contentHash,
                    ]
                );

                if ($created->wasRecentlyCreated) {
                    $this->images++;
                }
            } catch (\Throwable $e) {
                Log::debug("MES-046-E2: imagem falhou url={$origUrl}: " . $e->getMessage());
            }
        }
    }
}
