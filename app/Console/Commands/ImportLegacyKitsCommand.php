<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductBundle;
use App\Models\ProductBundleMedia;
use App\Models\Supplier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MES-046-E: Importa kits do legado (sku_pai_kit) para product_bundles.
 *
 * Logica:
 * - Cada kit (sku_pai_kit) cria UM ProductBundle "cabecalho" com legacy_kit_id preenchido.
 *   component_product_id fica NULL quando o produto nao esta no banco novo (aguarda sync).
 *   O nome/sku/price/stock/imagens do KIT sao sempre importados.
 * - Regra Ruan: kits com status != 1 no legado NAO importar.
 *   Componentes nao precisam estar no banco novo para o kit ser importado.
 * - Idempotente: usa legacy_kit_id como chave de upsert. Pode rodar N vezes.
 * - A cada rodada, tenta resolver component_product_id que estava NULL.
 */
class ImportLegacyKitsCommand extends Command
{
    protected $signature = 'bundles:import-legacy
        {--deposito=447 : ID do deposito legado (447 = MEStoreDrop)}
        {--supplier= : Supplier ID no banco novo (detectado automaticamente se nao informado)}
        {--dry-run : Exibe o que seria importado sem persistir}
        {--chunk=50 : Tamanho do chunk}';

    protected $description = 'Importa kits do legado (sku_pai_kit) para product_bundles (MES-046-E)';

    private int $imported     = 0;
    private int $updated      = 0;
    private int $skipped      = 0;
    private int $compResolved = 0;
    private int $compMissing  = 0;
    private int $imagens      = 0;

    public function handle(): int
    {
        $deposito  = (int) $this->option('deposito');
        $dryRun    = (bool) $this->option('dry-run');
        $chunk     = (int) $this->option('chunk');

        $this->info("=== IMPORTACAO KITS LEGADO (deposito={$deposito}) ===");
        if ($dryRun) {
            $this->warn('[DRY-RUN] Nenhuma alteracao sera persistida.');
        }

        // ---- Detectar supplier_id no banco novo ----
        $supplierId = $this->option('supplier');
        if (! $supplierId) {
            // Tentar via tenant legado
            $tenant = DB::table('tenants')->where('legacy_empresa_id', $deposito)->first();
            if ($tenant) {
                $ts = DB::table('tenant_supplier')->where('tenant_id', $tenant->id)->first();
                $supplierId = $ts?->supplier_id;
            }
        }

        if (! $supplierId) {
            $this->error("Nao foi possivel determinar supplier_id para deposito={$deposito}. Use --supplier=ID.");
            return Command::FAILURE;
        }

        $supplierId = (int) $supplierId;
        $this->info("Supplier ID: {$supplierId}");

        // ---- Verificar conexao com legado ----
        try {
            DB::connection('legacy')->selectOne('SELECT 1 as ok');
        } catch (\Throwable $e) {
            $this->error('Falha na conexao com banco legado: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // ---- Buscar kits ativos do deposito ----
        $total = DB::connection('legacy')
            ->table('sku_pai_kit')
            ->where('id_deposito', $deposito)
            ->where('status', 1)
            ->count();

        $this->info("Total de kits ativos no legado: {$total}");

        // ---- Construir mapa legacy_sku_pai_id => product.id no banco novo ----
        // (para tentar resolver componentes que estejam no banco novo)
        $this->info('Construindo mapa de produtos (legacy_sku_pai_id => product.id)...');
        $prodMap = Product::where('supplier_id', $supplierId)
            // MUL-145: inclui inativos para resolver componentes de kits
            ->whereNotNull('legacy_sku_pai_id')
            ->pluck('id', 'legacy_sku_pai_id')
            ->toArray();

        $this->info('Produtos ativos mapeados: ' . count($prodMap));

        // ---- Processar kits em chunks ----
        DB::connection('legacy')
            ->table('sku_pai_kit')
            ->where('id_deposito', $deposito)
            ->where('status', 1)
            ->orderBy('id')
            ->chunk($chunk, function ($kits) use ($supplierId, $prodMap, $dryRun) {
                foreach ($kits as $kit) {
                    $this->processKit($kit, $supplierId, $prodMap, $dryRun);
                }
            });

        // ---- Relatorio ----
        $this->newLine();
        $this->info('=== RESULTADO ===');
        $this->table(
            ['Metrica', 'Valor'],
            [
                ['Kits importados (novos)',                    $this->imported],
                ['Kits atualizados (existentes)',               $this->updated],
                ['Kits pulados (sem componentes no legado)',    $this->skipped],
                ['Componentes resolvidos (product_id)',         $this->compResolved],
                ['Componentes sem mapeamento (id NULL, aguarda sync)', $this->compMissing],
                ['Imagens importadas',                          $this->imagens],
            ]
        );

        $totalKits = ProductBundle::where('supplier_id', $supplierId)
            ->whereNotNull('legacy_kit_id')
            ->count('legacy_kit_id');

        $resolved = ProductBundle::where('supplier_id', $supplierId)
            ->whereNotNull('legacy_kit_id')
            ->whereNotNull('component_product_id')
            ->count();

        $unresolved = ProductBundle::where('supplier_id', $supplierId)
            ->whereNotNull('legacy_kit_id')
            ->whereNull('component_product_id')
            ->count();

        $this->info("Total de registros bundle no banco novo: {$totalKits}");
        $this->info("  -> Componentes resolvidos (com product_id): {$resolved}");
        $this->info("  -> Componentes pendentes (sem product_id): {$unresolved}");

        return Command::SUCCESS;
    }

    private function processKit(object $kit, int $supplierId, array $prodMap, bool $dryRun): void
    {
        // Buscar componentes do kit no legado
        $componentes = DB::connection('legacy')
            ->table('sku_pai_kit_item')
            ->where('id_sku_pai_kit', $kit->id)
            ->get();

        if ($componentes->isEmpty()) {
            $this->skipped++;
            return;
        }

        if ($dryRun) {
            $resolved = $componentes->filter(fn ($c) => isset($prodMap[$c->id_sku_pai]))->count();
            $this->line("[DRY] Kit {$kit->id} ({$kit->sku}) - {$componentes->count()} comp(s), {$resolved} resolvidos");
            $this->imported++;
            return;
        }

        // Buscar imagens do kit no legado
        $imagens = DB::connection('legacy')
            ->table('sku_pai_kit_imagens')
            ->where('id_sku_pai_kit', $kit->id)
            ->orderBy('ordem')
            ->get();

        $coverUrl = $imagens->isNotEmpty() ? $imagens->first()->url : ($kit->img ?? null);

        // Dados comuns do bundle (header do kit)
        $bundleBase = [
            'supplier_id'       => $supplierId,
            'name'              => mb_substr($kit->nome ?? '', 0, 500),
            'sku'               => $kit->sku ?? null,
            'ean'               => $kit->ean ?? null,
            'price'             => $kit->preco ?? null,
            'stock'             => (int) ($kit->estoque ?? 0),
            'weight'            => $kit->peso ?? null,
            'description'       => null,
            'cover_image_url'   => $coverUrl,
            'is_active'         => true,
            'legacy_kit_id'     => $kit->id,
            'parent_product_id' => null,
            'qty'               => 1,
        ];

        // Criar/atualizar um registro por componente
        foreach ($componentes as $comp) {
            $prodId = $prodMap[$comp->id_sku_pai] ?? null;

            if ($prodId) {
                $this->compResolved++;
            } else {
                $this->compMissing++;
            }

            // Chave de upsert: legacy_kit_id + id_sku_pai do componente
            // Usamos id_sku_pai (legacy) para identificar o componente mesmo sem product_id
            $existing = ProductBundle::where('legacy_kit_id', $kit->id)
                ->where('supplier_id', $supplierId)
                ->where(function ($q) use ($comp, $prodId) {
                    // Achar por component_product_id se resolvido, senao por posicao impossivel
                    // Na pratica, procuramos pelo registro existente desse par kit+comp
                    if ($prodId) {
                        $q->where('component_product_id', $prodId);
                    } else {
                        // Para componentes nao resolvidos, buscar com component_product_id NULL
                        // (pode haver mais de um NULL — nao e perfeito, mas evita duplicatas na maioria dos casos)
                        $q->whereNull('component_product_id');
                    }
                })
                ->first();

            $rowData = array_merge($bundleBase, [
                'component_product_id' => $prodId,
                'qty'                  => max(1, (int) ($comp->qtd ?? 1)),
            ]);

            if ($existing) {
                $existing->update($rowData);
                $this->updated++;
            } else {
                ProductBundle::create($rowData);
                $this->imported++;
            }
        }

        // Upsert imagens no primeiro registro do kit
        $firstBundle = ProductBundle::where('legacy_kit_id', $kit->id)
            ->where('supplier_id', $supplierId)
            ->first();

        if ($firstBundle && $imagens->isNotEmpty()) {
            ProductBundleMedia::where('product_bundle_id', $firstBundle->id)->delete();
            foreach ($imagens as $img) {
                if (! empty($img->url)) {
                    ProductBundleMedia::create([
                        'product_bundle_id' => $firstBundle->id,
                        'url'               => $img->url,
                        'ordem'             => (int) ($img->ordem ?? 0),
                    ]);
                    $this->imagens++;
                }
            }
        }
    }
}
