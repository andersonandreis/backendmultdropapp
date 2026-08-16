<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Supplier;
use App\Observers\ProductObserver;
use App\Services\GoolhubBridgeService;
use App\Services\ImageDownloadService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Backfill global de produtos a partir do legado.
 *
 * Reusa o bridge `produto_changes_pop.php` forcando since=2020-01-01,
 * o que faz ele retornar TODOS os sku_pai do deposito em ordem cronologica.
 * Em cada chunk, aplica TODOS os campos (descricao, custo, estoque, NCM, EAN,
 * peso, dimensoes, categoria, video, atributos) + galeria de imagens.
 *
 * NAO mexe no cursor do sync regular (rodar isso nao quebra o everyMinute).
 * Idempotente: rodar 2x produz o mesmo resultado.
 *
 * Uso:
 *   php artisan products:backfill-from-legacy                 # todos os suppliers
 *   php artisan products:backfill-from-legacy --supplier=8    # só supplier#8
 *   php artisan products:backfill-from-legacy --deposito=11   # só dep legado 11
 *   php artisan products:backfill-from-legacy --since=2020-01-01 --limit=500
 */
class BackfillProductsFromLegacy extends Command
{
    protected $signature = 'products:backfill-from-legacy
                            {--supplier= : Filtra por supplier_id do novo (opcional)}
                            {--deposito= : Filtra por legacy_empresa_id (opcional)}
                            {--since=2020-01-01 00:00:00 : Cursor inicial (since)}
                            {--limit=500 : Eventos por chunk}
                            {--dry-run : Nao aplica nada, so conta}';

    protected $description = 'Backfill global: re-importa TODOS os campos + galeria de imagens dos produtos do legado';

    public function handle(GoolhubBridgeService $bridge): int
    {
        $since         = (string) $this->option('since');
        $limit         = (int) $this->option('limit');
        $supplierFlt   = $this->option('supplier') !== null ? (int) $this->option('supplier') : null;
        $depositoFlt   = $this->option('deposito') !== null ? (int) $this->option('deposito') : null;
        $dry           = (bool) $this->option('dry-run');

        // Listar suppliers a processar
        $q = Supplier::query()->whereNotNull('legacy_empresa_id');
        if ($supplierFlt) $q->where('id', $supplierFlt);
        if ($depositoFlt) $q->where('legacy_empresa_id', $depositoFlt);
        $suppliers = $q->orderBy('id')->get();

        if ($suppliers->isEmpty()) {
            $this->warn('Nenhum supplier elegivel (precisa legacy_empresa_id IS NOT NULL).');
            return 0;
        }

        $this->info('Backfill em ' . $suppliers->count() . ' supplier(s). since=' . $since . ' limit=' . $limit . ($dry ? ' DRY-RUN' : ''));
        $totalApplied = 0; $totalImgs = 0; $totalErr = 0;

        ProductObserver::$disableSync = true;

        foreach ($suppliers as $supplier) {
            $deposito  = (int) $supplier->legacy_empresa_id;
            $supplierId = (int) $supplier->id;
            $this->newLine();
            $this->info(sprintf('[supplier#%d %s | dep=%d]', $supplierId, $supplier->company_name, $deposito));

            $cursor = $since;
            $page = 0;
            do {
                $page++;
                $res = $bridge->popSkuPaiChanges($limit, $deposito, $cursor);
                if (!($res['success'] ?? false)) {
                    $this->error('  bridge falhou: ' . ($res['error'] ?? '?'));
                    $totalErr++;
                    break;
                }
                $events = $res['data'] ?? [];
                if (empty($events)) {
                    $this->line('  fim (page=' . $page . ', sem eventos)');
                    break;
                }
                $nextCursor = $res['next_cursor'] ?? $cursor;
                $this->line(sprintf('  page=%d eventos=%d cursor=%s', $page, count($events), $nextCursor));

                if (!$dry) {
                    foreach ($events as $ev) {
                        try {
                            if (($ev['action'] ?? 'upsert') === 'delete') continue;
                            $this->applyUpsert($supplierId, $ev, $totalImgs);
                            $totalApplied++;
                        } catch (\Throwable $e) {
                            $totalErr++;
                            Log::error('[BackfillFromLegacy]', ['ev'=>$ev, 'err'=>$e->getMessage()]);
                        }
                    }
                }

                if ($nextCursor === $cursor) break;
                $cursor = $nextCursor;
                // safety: limita 200 pages por supplier
                if ($page > 200) { $this->warn('  >200 pages, parando'); break; }
            } while (true);
        }

        ProductObserver::$disableSync = false;

        $this->newLine();
        $this->info("Backfill completo: produtos aplicados=$totalApplied, imagens inseridas=$totalImgs, erros=$totalErr");
        return $totalErr > 0 ? 2 : 0;
    }

    /**
     * Mesma logica do SyncProductsFromLegacy::handle, extraida pra reuso.
     */
    private function applyUpsert(int $supplierId, array $ev, int &$totalImgs): void
    {
        $sku = $ev['sku'];
        $p = Product::where('supplier_id', $supplierId)->where('sku', $sku)->first()
          ?? Product::where('legacy_sku_pai_id', $ev['id_sku_pai'])->first()
          ?? new Product([
              'supplier_id' => $supplierId,
              'sku'         => $sku,
              'is_active'   => true,
          ]);

        $p->legacy_sku_pai_id = $ev['id_sku_pai'];
        $p->supplier_id       = $supplierId;
        $p->sku               = $sku;
        $p->name = !empty($ev['descricao']) ? $ev['descricao'] : ($p->name ?: $ev['sku']);
        if (!empty($ev['desc_produto']))      $p->description = $ev['desc_produto'];
        $p->cost  = $ev['custo']       ?? $p->cost  ?? 0;
        $p->price = $ev['custo_curso'] ?? $p->price ?? 0;
        if ($ev['estoque'] !== null)     $p->virtual_stock_qty = (int) $ev['estoque'];
        $p->ean   = $ev['ean']   ?? $p->ean;
        $p->gtin  = $ev['ean']   ?? $p->gtin;
        $p->brand = $ev['marca'] ?? $p->brand;
        if (isset($ev['garantia']) && is_numeric($ev['garantia'])) {
            $p->warranty_months = (int) $ev['garantia'];
        }
        if ($ev['peso']        !== null) $p->weight_kg = $ev['peso'];
        if ($ev['largura']     !== null) $p->width_cm  = round((float) $ev['largura']     * 100, 2);
        if ($ev['altura']      !== null) $p->height_cm = round((float) $ev['altura']      * 100, 2);
        if ($ev['comprimento'] !== null) $p->length_cm = round((float) $ev['comprimento'] * 100, 2);
        if ($ev['id_categoria_sku'] !== null) $p->category_id = $ev['id_categoria_sku'];
        if (!empty($ev['video_url']))    $p->video_url = $ev['video_url'];

        $attrs = is_array($p->attributes) ? $p->attributes : [];
        if (!empty($ev['ncm']))            $attrs['ncm']           = $ev['ncm'];
        if (!empty($ev['origem']))         $attrs['origem']        = $ev['origem'];
        if (!empty($ev['meli_categoria'])) $attrs['meli_category'] = $ev['meli_categoria'];
        if (!empty($ev['shopee_cat']))     $attrs['shopee_cat']    = $ev['shopee_cat'];
        if ($attrs) $p->attributes = $attrs;

        $p->saveQuietly();

        if ($ev['estoque'] !== null) {
            \App\Models\Inventory::updateOrCreate(
                ['product_id' => $p->id, 'warehouse_id' => $supplierId],
                ['producer_id' => $supplierId, 'quantity' => max(0, (int) $ev['estoque'])]
            );
        }

        // MUL-092: guard contra perda de fotos.
        // Antes: DELETE incondicional se ev['imagens'] tivesse qualquer item —
        // se todas as URLs falhassem (host morto, S3 expirado, etc), produto ficava sem foto.
        // Agora: so faz DELETE se conseguirmos montar pelo menos 1 linha valida.
        if (!empty($ev['imagens'])) {
            $imageSvc = app(ImageDownloadService::class);
            $rows = [];
            foreach ($ev['imagens'] as $idx => $im) {
                if (empty($im['img'])) continue;
                // FOR-007: normaliza host legado + baixa pro storage local
                $url = str_replace('www.sistemagrupoonline.com.br', 'goolhub.io', (string) $im['img']);
                $url = str_replace('://sistemagrupoonline.com.br', '://goolhub.io', $url);
                $mat = $imageSvc->ensureLocal($url, $supplierId, (int) $p->id);
                $rows[] = [
                    'product_id'   => $p->id,
                    'url'          => $mat['url'],
                    'original_url' => $mat['local'] ? $url : null,
                    'local_path'   => $mat['path'],
                    'type'         => 'image',
                    'is_cover'     => $idx === 0 ? 1 : 0,
                    'position'     => (int) ($im['posicao'] ?? $idx),
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ];
            }
            if ($rows) {
                DB::table('product_media')->where('product_id', $p->id)->delete();
                DB::table('product_media')->insert($rows);
                $totalImgs += count($rows);
            } else {
                \Illuminate\Support\Facades\Log::warning('[BackfillFromLegacy] MUL-092: preservando fotos existentes — nenhuma imagem normalizada com sucesso', [
                    'product_id' => $p->id,
                    'imagens'    => $ev['imagens'],
                ]);
            }
        }
    }
}
