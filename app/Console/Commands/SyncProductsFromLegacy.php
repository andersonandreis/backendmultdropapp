<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Observers\ProductObserver;
use App\Services\GoolhubBridgeService;
use App\Services\ImageDownloadService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Consome delta de sku_pai do legado via bridge e aplica em products.
 * Estado de cursor mantido em Cache (chave sync.products.last_cursor).
 */
class SyncProductsFromLegacy extends Command
{
    protected $signature = 'products:sync-from-legacy
                            {--limit=100 : Eventos por execucao}
                            {--deposito=53 : id_deposito do legado (JTDrop=53, default)}
                            {--since= : Sobrescreve cursor (YYYY-MM-DD HH:MM:SS)}
                            {--reset : Reseta cursor pra inicio do dia}';

    protected $description = 'Puxa alteracoes de sku_pai do legado pra products via bridge (cursor data_update)';

    public const DEPOSITO_TO_SUPPLIER = [498 => 1];

    private function cursorKey(int $deposito): string { return "sync.products.cursor.$deposito"; }

    public function handle(GoolhubBridgeService $bridge, ImageDownloadService $imageSvc): int
    {
        $limit     = (int) $this->option('limit');
        $deposito  = (int) $this->option('deposito');
        $supplierId = self::DEPOSITO_TO_SUPPLIER[$deposito] ?? null;
        if (!$supplierId) { $this->error("deposito=$deposito sem mapeamento"); return 1; }

        if ($this->option('reset')) {
            Cache::forget($this->cursorKey($deposito));
            $this->info('Cursor resetado');
        }
        $since = $this->option('since') ?: Cache::get($this->cursorKey($deposito));

        $res = $bridge->popSkuPaiChanges($limit, $deposito, $since);
        if (!$res['success']) { $this->error('Bridge falhou: ' . ($res['error'] ?? '?')); return 1; }

        $events = $res['data'] ?? [];
        $nextCursor = (!empty($res['data']) && is_array($res['data'])) ? end($res['data'])['data_update'] : $since;

        if (!$events) {
            $this->line('Sem eventos novos (cursor ' . ($since ?: 'inicial') . ')');
            return 0;
        }

        $this->info(count($events) . ' eventos a aplicar');
        ProductObserver::$disableSync = true;

        $applied = 0; $errors = 0;
        foreach ($events as $ev) {
            try {
                // action='delete' (tombstone do legado): marca produto como inativo no novo (preserva historico de pedidos)
                if (($ev['action'] ?? 'upsert') === 'delete') {
                    $updated = Product::where('legacy_sku_pai_id', $ev['id_sku_pai'])
                        ->update(['is_active' => false]);
                    $applied++;
                    $this->line("  ✓ DELETE {$ev['sku']} (legacy={$ev['id_sku_pai']}) -> is_active=false ($updated row(s))");
                    continue;
                }
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
                if (!empty($ev['desc_produto'])) $p->description = $ev['desc_produto'];
                $p->cost = $ev['custo'] ?? $p->cost ?? 0;
                $p->price = $ev['custo_curso'] ?? $p->price ?? 0;
                if ($ev['estoque'] !== null)     $p->virtual_stock_qty = (int) $ev['estoque'];
                $p->ean   = $ev['ean']   ?? $p->ean;
                $p->gtin  = $ev['ean']   ?? $p->gtin;
                $p->brand = $ev['marca'] ?? $p->brand;
                if (isset($ev['garantia']) && is_numeric($ev['garantia'])) {
                    $p->warranty_months = (int) $ev['garantia'];
                }
                if ($ev['peso']        !== null) $p->weight_kg = $ev['peso'];
                if ($ev['largura']     !== null) $p->width_cm  = round((float)$ev['largura']     * 100, 2);
                if ($ev['altura']      !== null) $p->height_cm = round((float)$ev['altura']      * 100, 2);
                if ($ev['comprimento'] !== null) $p->length_cm = round((float)$ev['comprimento'] * 100, 2);
                if ($ev['id_categoria_sku'] !== null) $p->category_id = $ev['id_categoria_sku'];
                if (!empty($ev['video_url']))    $p->video_url = $ev['video_url'];

                $attrs = is_array($p->attributes) ? $p->attributes : [];
                if (!empty($ev['ncm']))            $attrs['ncm']           = $ev['ncm'];
                if (!empty($ev['origem']))         $attrs['origem']        = $ev['origem'];
                if (!empty($ev['meli_categoria'])) $attrs['meli_category'] = $ev['meli_categoria'];
                if (!empty($ev['shopee_cat']))     $attrs['shopee_cat']    = $ev['shopee_cat'];
                if ($attrs) $p->attributes = $attrs;

                $p->saveQuietly();

                // Hotfix 2: espelha o estoque real em inventory.quantity (alem de virtual_stock_qty).
                // effectiveStock usa inventory.quantity p/ a protecao de zeragem (<=10 vende 0) reagir near-real-time.
                if ($ev['estoque'] !== null) {
                    \App\Models\Inventory::updateOrCreate(
                        ['product_id' => $p->id, 'warehouse_id' => $supplierId],
                        ['producer_id' => $supplierId, 'quantity' => max(0, (int) $ev['estoque'])]
                    );
                }

                // MUL-092: guard contra perda de fotos.
                // Antes: DELETE incondicional se ev['imagens'] tivesse qualquer item —
                // se todas as URLs normalizadas falhassem, o produto ficava SEM foto.
                // Agora: so faz DELETE se conseguirmos montar pelo menos 1 linha valida.
                if (!empty($ev['imagens'])) {
                    $rows = [];
                    foreach ($ev['imagens'] as $idx => $im) {
                        if (empty($im['img'])) continue;
                        $normalized = $this->normalizeImageUrl($im['img']);
                        if (!$normalized) continue;

                        $mat = $imageSvc->ensureLocal($normalized, $supplierId, (int) $p->id);
                        $rows[] = [
                            'product_id'   => $p->id,
                            'url'          => $mat['url'],
                            'original_url' => $mat['local'] ? $normalized : null,
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
                    } else {
                        Log::warning('[SyncFromLegacy] MUL-092: preservando fotos existentes — nenhuma imagem normalizada com sucesso', [
                            'product_id' => $p->id,
                            'sku'        => $sku,
                            'imagens'    => $ev['imagens'],
                        ]);
                    }
                }

                $applied++;
                $this->line("  ✓ $sku → product #{$p->id} (legacy={$ev['id_sku_pai']})");
            } catch (\Throwable $e) {
                Log::error('[SyncFromLegacy]', ['ev'=>$ev, 'err'=>$e->getMessage()]);
                $this->error("  ✗ {$ev['sku']}: " . $e->getMessage());
                $errors++;
            }
        }

        ProductObserver::$disableSync = false;

        if ($applied > 0 && $errors === 0) {
            Cache::put($this->cursorKey($deposito), $nextCursor, now()->addDays(30));
            $this->info("Cursor → $nextCursor");
        }

        $this->info("OK: aplicados=$applied, erros=$errors");
        return $errors > 0 ? 2 : 0;
    }

    /**
     * Normaliza URL de imagem vinda do bridge legado.
     * Reescreve host historico (www.)sistemagrupoonline.com.br -> goolhub.io,
     * onde os arquivos realmente moram.
     */
    private function normalizeImageUrl(?string $url): ?string
    {
        if (!$url) return null;
        $u = (string) $url;
        $u = str_replace('www.sistemagrupoonline.com.br', 'goolhub.io', $u);
        $u = str_replace('://sistemagrupoonline.com.br', '://goolhub.io', $u);
        return $u;
    }

}
