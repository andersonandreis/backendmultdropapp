<?php

namespace App\Console\Commands;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\Supplier;
use App\Services\ImageDownloadService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * NOV-107: Importa catalogo MEStoreDrop do legado para o NovoHubAI.
 *
 * Depositos:
 *   - 447 (M&E store atacado e drop) -> supplier_id=25 (ja importado ~1009 prod)
 *   - 355 (M&E Store drop)           -> supplier criado via ensureSupplier()
 *
 * Uso:
 *   php artisan import:mestoredrop-catalog --deposito=355
 *   php artisan import:mestoredrop-catalog --deposito=447 --force
 */
class ImportMEstoredropCatalogCommand extends Command
{
    protected $signature = 'import:mestoredrop-catalog
        {--deposito=447 : ID do deposito legado (447=principal, 355=secundario)}
        {--force        : Re-importa mesmo que produto ja exista}
        {--chunk=100    : Tamanho do chunk para processamento}';

    protected $description = 'Importa catalogo MEStoreDrop do MySQL legado para o NovoHubAI (idempotente)';

    private int $created = 0;
    private int $updated = 0;
    private int $skipped = 0;
    private int $images  = 0;
    private int $errors  = 0;

    private \PDO $legacyPdo;

    public function handle(): int
    {
        $depositoId = (int) $this->option('deposito');
        $force      = (bool) $this->option('force');
        $chunkSize  = (int) $this->option('chunk');

        $this->info('=== IMPORT MESTOREDROP CATALOGO ===');
        $this->info("Deposito legado: {$depositoId} | Force: " . ($force ? 'sim' : 'nao') . " | Chunk: {$chunkSize}");

        try {
            $this->legacyPdo = new \PDO(
                'mysql:host=217.216.81.157;port=32000;dbname=tudoonline_production;charset=utf8mb4',
                'tudoonline_production',
                '1D5IaXlXxmtlT0e4GTF4mzU3rsf38BvCCZHUaSFD',
                [
                    \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE  => \PDO::FETCH_OBJ,
                    \PDO::ATTR_TIMEOUT             => 15,
                    \PDO::MYSQL_ATTR_INIT_COMMAND  => 'SET NAMES utf8mb4',
                ]
            );
            $this->info('Conexao com MySQL legado OK');
        } catch (\Throwable $e) {
            $this->error('Falha na conexao com MySQL legado: ' . $e->getMessage());
            return self::FAILURE;
        }

        $supplier = $this->ensureSupplier($depositoId);
        if (!$supplier) {
            $this->error("Deposito {$depositoId} nao encontrado no legado.");
            return self::FAILURE;
        }

        $this->info("Supplier ID no NovoHubAI: {$supplier->id} ({$supplier->company_name})");
        $this->importProducts($depositoId, $supplier, $chunkSize, $force);

        $this->newLine();
        $this->info('=== RESULTADO ===');
        $this->table(
            ['Metrica', 'Valor'],
            [
                ['Deposito legado',   $depositoId],
                ['Supplier ID novo',  $supplier->id],
                ['Criados',           $this->created],
                ['Atualizados',       $this->updated],
                ['Ignorados',         $this->skipped],
                ['Imagens',           $this->images],
                ['Erros',             $this->errors],
            ]
        );

        return self::SUCCESS;
    }

    private function ensureSupplier(int $depositoId): ?Supplier
    {
        $stmt = $this->legacyPdo->prepare(
            'SELECT id, menu_titulo, cep, liberada FROM deposito WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$depositoId]);
        $dep = $stmt->fetch();

        if (!$dep) {
            return null;
        }

        $name = mb_convert_encoding($dep->menu_titulo ?? "Deposito {$dep->id}", 'UTF-8', 'auto');

        $supplier = Supplier::updateOrCreate(
            ['legacy_id' => $depositoId],
            [
                'user_id'      => 1,
                'company_name' => $name,
                'document'     => '',
                'type'         => 'warehouse',
                'is_active'    => (bool) $dep->liberada,
                'zipcode'      => $dep->cep ?? '',
            ]
        );

        $this->info('Supplier ' . ($supplier->wasRecentlyCreated ? 'criado' : 'atualizado') . ": {$name}");

        return $supplier;
    }

    private function importProducts(int $depositoId, Supplier $supplier, int $chunkSize, bool $force): void
    {
        $this->info('');
        $this->info("--- Importando produtos do deposito {$depositoId} ---");

        $countStmt = $this->legacyPdo->prepare('SELECT COUNT(*) FROM sku_pai WHERE id_deposito = ?');
        $countStmt->execute([$depositoId]);
        $total = (int) $countStmt->fetchColumn();

        $this->info("Total no legado: {$total}");

        if ($total === 0) {
            $this->warn("Nenhum produto encontrado para deposito {$depositoId}.");
            return;
        }

        $bar = $this->output->createProgressBar($total);
        $offset = 0;

        $stmt = $this->legacyPdo->prepare(
            'SELECT
                sp.id, sp.sku, sp.descricao, sp.desc_produto,
                sp.custo, sp.custo_curso, sp.estoque,
                sp.peso, sp.largura, sp.altura, sp.comprimento,
                sp.marca, sp.ean, sp.ncm, sp.origem,
                sp.video_url, sp.img,
                sp.meli_atributos, sp.shopee_atributos,
                sp.id_deposito, sp.cor, sp.tamanho, sp.garantia,
                sp.data_add, sp.data_update,
                sp.reg_anatel, sp.reg_anvisa, sp.reg_inmetro
            FROM sku_pai sp
            WHERE sp.id_deposito = ?
            ORDER BY sp.id
            LIMIT ? OFFSET ?'
        );

        do {
            $stmt->bindValue(1, $depositoId, \PDO::PARAM_INT); $stmt->bindValue(2, $chunkSize, \PDO::PARAM_INT); $stmt->bindValue(3, $offset, \PDO::PARAM_INT); $stmt->execute();
            $rows = $stmt->fetchAll();

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $this->processProduct($row, $supplier, $force);
                $bar->advance();
            }

            $this->line("  [chunk offset={$offset}] criados={$this->created} atualizados={$this->updated} skip={$this->skipped} erros={$this->errors}");
            $offset += $chunkSize;

        } while (count($rows) === $chunkSize);

        $bar->finish();
        $this->newLine();
    }

    private function processProduct(object $row, Supplier $supplier, bool $force): void
    {
        try {
            $rawSku = $row->sku ?: "SKU-{$row->id}";
            // MUL-161 FIX: guard anti-prefixo-duplo.
            $depositoPrefix = "D{$row->id_deposito}-";
            $sku = str_starts_with($rawSku, $depositoPrefix)
                ? $rawSku
                : $depositoPrefix . $rawSku;

            if (!$force) {
                $existing = Product::where('legacy_sku_pai_id', $row->id)->first();
                if ($existing) {
                    if ($row->data_update && $existing->updated_at?->gte($row->data_update)) {
                        $this->skipped++;
                        return;
                    }
                }
            }

            $attributes = [];
            if ($row->meli_atributos) {
                $meli = json_decode($row->meli_atributos, true);
                if (is_array($meli)) $attributes['mercadolivre'] = $meli;
            }
            if ($row->shopee_atributos) {
                $shopee = json_decode($row->shopee_atributos, true);
                if (is_array($shopee)) $attributes['shopee'] = $shopee;
            }
            if ($row->cor)         $attributes['cor']         = $row->cor;
            if ($row->tamanho)     $attributes['tamanho']     = $row->tamanho;
            if ($row->reg_anatel)  $attributes['reg_anatel']  = $row->reg_anatel;
            if ($row->reg_anvisa)  $attributes['reg_anvisa']  = $row->reg_anvisa;
            if ($row->reg_inmetro) $attributes['reg_inmetro'] = $row->reg_inmetro;

            $name        = mb_convert_encoding($row->descricao    ?? 'Sem nome', 'UTF-8', 'auto');
            $description = mb_convert_encoding($row->desc_produto ?? '',          'UTF-8', 'auto');

            $product = Product::where('legacy_sku_pai_id', $row->id)->first()
                ?: Product::where('sku', $sku)->first();

            $data = [
                'legacy_sku_pai_id' => $row->id,
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
                'origem'            => $row->origem    ?: null,
                'video_url'         => $row->video_url ?: null,
                'attributes'        => !empty($attributes) ? $attributes : null,
                'is_active'         => true,
            ];

            if ($product) {
                $product->update($data);
                $isNew = false;
            } else {
                $product = Product::create($data);
                $isNew = true;
            }

            if ($isNew) {
                $this->created++;
            } else {
                $this->updated++;
            }

            Inventory::updateOrCreate(
                ['product_id' => $product->id, 'warehouse_id' => $supplier->id],
                [
                    'producer_id' => $supplier->id,
                    'quantity'    => max(0, (int) ($row->estoque ?: 0)),
                ]
            );

            $this->importImages($product, $row->img);

        } catch (\Throwable $e) {
            Log::warning("ImportMEstoredrop: produto legado id={$row->id} falhou: " . $e->getMessage());
            $this->errors++;
        }
    }

    private function importImages(Product $product, ?string $imgField): void
    {
        if (!$imgField) return;

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

        $baseUrl = 'https://goolhub.io/imagens/produtos/';

        foreach ($images as $index => $img) {
            $img = trim($img);
            if (!$img) continue;

            $url = str_starts_with($img, 'http') ? $img : $baseUrl . $img;
            $url = str_replace('www.sistemagrupoonline.com.br', 'goolhub.io', $url);
            $url = str_replace('://sistemagrupoonline.com.br',  '://goolhub.io', $url);

            try {
                $mat = app(ImageDownloadService::class)->ensureLocal(
                    $url,
                    (int) $product->supplier_id,
                    (int) $product->id
                );

                $created = \App\Models\ProductMedia::firstOrCreate(
                    ['product_id' => $product->id, 'url' => $mat['url']],
                    [
                        'type'         => 'image',
                        'position'     => $index,
                        'original_url' => $mat['local'] ? $url : null,
                        'local_path'   => $mat['path'] ?? null,
                    ]
                );

                if ($created->wasRecentlyCreated) {
                    $this->images++;
                }
            } catch (\Throwable $e) {
                Log::debug("ImportMEstoredrop imagem falhou url={$url}: " . $e->getMessage());
            }
        }
    }
}
