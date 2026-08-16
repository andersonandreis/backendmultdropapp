<?php

namespace App\Console\Commands;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\Supplier;
use App\Services\ImageDownloadService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ImportLegacyCatalog extends Command
{
    protected $signature = 'sync:import-legacy
        {--supplier=498 : Legacy deposito ID (498=MultDrop, default)}
        {--force : Force re-import even if already synced}
        {--chunk=500 : Chunk size for processing}
        {--confirmar-legado-desligado : MUL-320 — roda mesmo com legacy_sync_enabled=false}';

    protected $description = 'Importa fornecedores, catalogo e estoque do MySQL legado (tudoonline_production)';

    private int $created = 0;
    private int $updated = 0;
    private int $images = 0;
    private int $skipped = 0;

    public function handle(): void
    {
        // MUL-320: mesma trava do SyncLegacyCatalogJob. Este comando e quem monta o prefixo
        // de deposito (D498-, D773-) na linha ~221; rodar com o legado desligado repovoa o
        // catalogo com SKU duplicado. --confirmar-legado-desligado libera execucao pontual.
        if (! config('app.legacy_sync_enabled') && ! $this->option('confirmar-legado-desligado')) {
            $this->error('legacy_sync_enabled esta DESLIGADO. Importar agora repovoa o catalogo.');
            $this->line('Se e mesmo o que voce quer, rode com --confirmar-legado-desligado.');
            return;
        }

        $this->info('=== SYNC LEGADO -> NOVO HUBAI ===');

        // Test connection
        try {
            $test = DB::connection('legacy')->selectOne('SELECT 1 as ok');
            $this->info('Conexao com MySQL legado OK');
        } catch (\Throwable $e) {
            $this->error('Falha na conexao com MySQL legado: ' . $e->getMessage());
            return;
        }

        // Phase 1: Suppliers
        $this->importSuppliers();

        // Phase 2: Products + Images
        $this->importProducts();

        // Phase 3: Summary
        $this->newLine();
        $this->info('=== RESULTADO ===');
        $this->table(
            ['Metrica', 'Valor'],
            [
                ['Fornecedores', Supplier::whereNotNull('legacy_id')->count()],
                ['Produtos criados', $this->created],
                ['Produtos atualizados', $this->updated],
                ['Produtos ignorados', $this->skipped],
                ['Imagens importadas', $this->images],
                ['Estoque registros', Inventory::count()],
            ]
        );
    }

    private function importSuppliers(): void
    {
        $this->info('');
        $this->info('--- FASE 1: Fornecedores ---');

        $onlySupplier = $this->option('supplier');

        $query = DB::connection('legacy')
            ->table('deposito')
            ->select(['id', 'menu_titulo', 'cep', 'liberada', 'aceitar_integracao', 'taxa_pix', 'tipo_pagamento']);

        // MUL-219: --supplier explicito importa mesmo deposito nao-liberado
        // (ex: dep 773 Multdrop Filial tem liberada=0 / liberada_manual=1).
        if ($onlySupplier) {
            $query->where('id', $onlySupplier);
        } else {
            $query->where('liberada', 1);
        }

        $depositos = $query->get();
        $this->info("Encontrados {$depositos->count()} depositos liberados");

        $bar = $this->output->createProgressBar($depositos->count());
        foreach ($depositos as $dep) {
            $name = mb_convert_encoding($dep->menu_titulo ?? "Deposito {$dep->id}", 'UTF-8', 'UTF-8');

            Supplier::updateOrCreate(
                ['legacy_id' => $dep->id],
                [
                    'user_id' => 1, // admin owner
                    'company_name' => $name,
                    'document' => '',
                    'type' => 'warehouse',
                    'is_active' => true,
                    'zipcode' => $dep->cep ?? '',
                ]
            );
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
        $this->info("Fornecedores sincronizados: {$depositos->count()}");
    }

    private function importProducts(): void
    {
        $this->info('');
        $this->info('--- FASE 2: Produtos + Imagens + Estoque ---');

        $onlySupplier = $this->option('supplier');
        $chunkSize = (int) $this->option('chunk');
        $force = $this->option('force');

        // Build query
        $query = DB::connection('legacy')
            ->table('sku_pai')
            ->join('deposito', 'sku_pai.id_deposito', '=', 'deposito.id')
            ->select([
                'sku_pai.id',
                'sku_pai.sku',
                'sku_pai.descricao',
                'sku_pai.desc_produto',
                'sku_pai.custo',
                'sku_pai.custo_curso',
                'sku_pai.estoque',
                'sku_pai.peso',
                'sku_pai.largura',
                'sku_pai.altura',
                'sku_pai.comprimento',
                'sku_pai.marca',
                'sku_pai.ean',
                'sku_pai.ncm',
                'sku_pai.origem',
                'sku_pai.video_url',
                'sku_pai.img',
                'sku_pai.meli_categoria',
                'sku_pai.meli_atributos',
                'sku_pai.shopee_cat',
                'sku_pai.shopee_atributos',
                'sku_pai.id_deposito',
                'sku_pai.cor',
                'sku_pai.tamanho',
                'sku_pai.garantia',
                'sku_pai.data_add',
                'sku_pai.data_update',
                'sku_pai.reg_anatel',
                'sku_pai.reg_anvisa',
                'sku_pai.reg_inmetro',
            ])
            ->orderBy('sku_pai.id');

        if ($onlySupplier) {
            $query->where('sku_pai.id_deposito', $onlySupplier);
        } else {
            $query->where('deposito.liberada', 1);
        }

        $total = (clone $query)->count();
        $this->info("Total de produtos a processar: {$total}");

        $bar = $this->output->createProgressBar($total);

        $query->chunk($chunkSize, function ($rows) use ($bar, $force) {
            foreach ($rows as $row) {
                $this->processProduct($row, $force);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
    }

    private function processProduct(object $row, bool $force): void
    {
        // Find supplier by legacy_id
        $supplier = Supplier::where('legacy_id', $row->id_deposito)->first();
        if (!$supplier) {
            $this->skipped++;
            return;
        }

        // Check if already exists (skip if not forcing)
        if (!$force) {
            $existing = Product::where('legacy_sku_pai_id', $row->id)->first();
            if ($existing && $existing->updated_at && $row->data_update) {
                // Skip if legacy hasn't been updated since last import
                if ($existing->updated_at->gte($row->data_update)) {
                    $this->skipped++;
                    return;
                }
            }
        }

        // Build attributes JSON
        $attributes = [];
        if ($row->meli_atributos) {
            $meli = json_decode($row->meli_atributos, true);
            if (is_array($meli)) $attributes['mercadolivre'] = $meli;
        }
        if ($row->shopee_atributos) {
            $shopee = json_decode($row->shopee_atributos, true);
            if (is_array($shopee)) $attributes['shopee'] = $shopee;
        }
        if ($row->cor) $attributes['cor'] = $row->cor;
        if ($row->tamanho) $attributes['tamanho'] = $row->tamanho;
        if ($row->reg_anatel) $attributes['reg_anatel'] = $row->reg_anatel;
        if ($row->reg_anvisa) $attributes['reg_anvisa'] = $row->reg_anvisa;
        if ($row->reg_inmetro) $attributes['reg_inmetro'] = $row->reg_inmetro;

        $name = mb_convert_encoding($row->descricao ?? 'Sem nome', 'UTF-8', 'UTF-8');
        $description = mb_convert_encoding($row->desc_produto ?? '', 'UTF-8', 'UTF-8');

        try {
            $rawSku = $row->sku ?: "SKU-{$row->id}";
            // MUL-161 FIX: guard anti-prefixo-duplo.
            // Se o SKU do legado já começa com o prefixo do depósito (ex: sku_pai.sku = 'D773-...')
            // NÃO adicionar novamente, senão gera 'D773-D773-...'.
            $depositoPrefix = "D{$row->id_deposito}-";
            $sku = str_starts_with($rawSku, $depositoPrefix)
                ? $rawSku
                : $depositoPrefix . $rawSku;

            // Match by legacy_sku_pai_id first, then by prefixed SKU
            $product = Product::where('legacy_sku_pai_id', $row->id)->first()
                ?: Product::where('sku', $sku)->first();

            $data = [
                    'legacy_sku_pai_id' => $row->id,
                    'supplier_id' => $supplier->id,
                    'sku' => $sku,
                    'name' => $name,
                    'description' => $description,
                    'price' => (float) ($row->custo_curso ?: $row->custo ?: 0),
                    'cost' => (float) ($row->custo ?: 0),
                    'ean' => $row->ean ?: null,
                    'brand' => $row->marca ? mb_convert_encoding($row->marca, 'UTF-8', 'UTF-8') : null,
                    'weight_kg' => (float) ($row->peso ?: 0),
                    'height_cm' => (float) ($row->altura ?: 0),
                    'width_cm' => (float) ($row->largura ?: 0),
                    'length_cm' => (float) ($row->comprimento ?: 0),
                    'video_url' => $row->video_url ?: null,
                    'attributes' => !empty($attributes) ? $attributes : null,
                    'is_active' => true,
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

            // Inventory
            Inventory::updateOrCreate(
                ['product_id' => $product->id, 'warehouse_id' => $supplier->id],
                [
                    'producer_id' => $supplier->id,
                    'quantity' => max(0, (int) ($row->estoque ?: 0)),
                ]
            );

            // Images
            $this->importImages($product, $row->img);

        } catch (\Throwable $e) {
            Log::warning("Import product {$row->id} failed: " . $e->getMessage());
            $this->skipped++;
        }
    }

    private function importImages(Product $product, ?string $imgField): void
    {
        if (!$imgField) return;

        // Parse image field (can be JSON array, pipe-separated, or single path)
        $images = [];
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

            // Build full URL if not already absolute
            $url = str_starts_with($img, 'http') ? $img : $baseUrl . $img;
            // Host historico sistemagrupoonline.com.br -> goolhub.io
            $url = str_replace('www.sistemagrupoonline.com.br', 'goolhub.io', $url);
            $url = str_replace('://sistemagrupoonline.com.br', '://goolhub.io', $url);

            $mat = app(ImageDownloadService::class)->ensureLocal(
                $url,
                (int) $product->supplier_id,
                (int) $product->id
            );

            $created = ProductMedia::firstOrCreate(
                ['product_id' => $product->id, 'url' => $mat['url']],
                [
                    'type'         => 'image',
                    'position'     => $index,
                    'original_url' => $mat['local'] ? $url : null,
                    'local_path'   => $mat['path'],
                ]
            );

            if ($created->wasRecentlyCreated) {
                $this->images++;
            }
        }
    }
}
