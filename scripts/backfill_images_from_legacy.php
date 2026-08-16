<?php
/**
 * MUL-064 — Backfill product_media a partir do legado sku_pai.img
 * 
 * Busca imagens no legado (conexao "legacy", tabela sku_pai) via legacy_sku_pai_id
 * e insere em product_media para produtos sem mídia.
 * 
 * REGRA: ProductObserver::$disableSync = true para evitar loop de sync.
 * 
 * Uso: php scripts/backfill_images_from_legacy.php [--dry-run] [--limit=N] [--chunk=100]
 */

require_once __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Product;
use App\Models\ProductMedia;
use App\Observers\ProductObserver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// --- CLI args ---
$dryRun  = in_array('--dry-run', $argv ?? []);
$limit   = 0;
$chunk   = 100;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--limit='))  $limit  = (int) substr($arg, 8);
    if (str_starts_with($arg, '--chunk='))  $chunk  = (int) substr($arg, 8);
}

echo "=== MUL-064 Backfill imagens legado -> product_media ===\n";
echo "Modo: " . ($dryRun ? "DRY-RUN (sem inserção)" : "PRODUÇÃO") . "\n";
echo "Chunk: {$chunk} | Limit: " . ($limit ?: "sem limite") . "\n\n";

// --- Desativar observer para evitar loop de sync ---
ProductObserver::$disableSync = true;
echo "[OK] ProductObserver::\$disableSync = true\n";

$inserted  = 0;
$skipped   = 0;
$errors    = 0;
$processed = 0;

$query = Product::where('supplier_id', 1)
    ->whereNotNull('legacy_sku_pai_id')
    ->whereDoesntHave('media')
    ->select('id', 'name', 'sku', 'legacy_sku_pai_id');

if ($limit > 0) {
    $query->limit($limit);
}

$query->chunkById($chunk, function ($products) use (&$inserted, &$skipped, &$errors, &$processed, $dryRun, $limit) {
    $legacyIds = $products->pluck('legacy_sku_pai_id')->filter()->all();

    if (empty($legacyIds)) {
        $skipped += $products->count();
        return;
    }

    // Buscar imagens no legado para o batch inteiro
    $legacyImages = DB::connection('legacy')
        ->table('sku_pai')
        ->whereIn('id', $legacyIds)
        ->whereNotNull('img')
        ->where('img', '!=', '')
        ->whereNotLike('img', 'data:%')   // ignorar base64
        ->get(['id', 'img'])
        ->keyBy('id');

    foreach ($products as $product) {
        $processed++;

        $legacyRow = $legacyImages->get($product->legacy_sku_pai_id);

        if (!$legacyRow || empty($legacyRow->img)) {
            $skipped++;
            continue;
        }

        $imgUrl = trim($legacyRow->img);

        // Normalizar URL relativa
        if (str_starts_with($imgUrl, '/')) {
            $imgUrl = 'https://goolhub.io' . $imgUrl;
        }

        if (!str_starts_with($imgUrl, 'http')) {
            $skipped++;
            continue;
        }

        if ($dryRun) {
            echo "  [DRY] product_id={$product->id} sku={$product->sku} -> {$imgUrl}\n";
            $inserted++;
            continue;
        }

        try {
            $exists = DB::table('product_media')
                ->where('product_id', $product->id)
                ->where('url', $imgUrl)
                ->exists();

            if (!$exists) {
                DB::table('product_media')->insert([
                    'product_id'  => $product->id,
                    'type'        => 'image',
                    'url'         => $imgUrl,
                    'original_url' => $imgUrl,
                    'is_cover'    => 1,
                    'position'    => 0,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
                $inserted++;
            } else {
                $skipped++;
            }
        } catch (\Exception $e) {
            $errors++;
            echo "  [ERR] product_id={$product->id}: " . $e->getMessage() . "\n";
        }
    }

    echo "[progresso] processados={$processed} inseridos={$inserted} pulados={$skipped} erros={$errors}\n";

    // Respeitar limit nos chunks
    if ($limit > 0 && $processed >= $limit) {
        return false; // interrompe chunkById
    }
});

// --- Reativar observer ---
ProductObserver::$disableSync = false;
echo "\n[OK] ProductObserver::\$disableSync = false\n";

echo "\n=== RESULTADO FINAL ===\n";
echo "Processados : {$processed}\n";
echo "Inseridos   : {$inserted}\n";
echo "Pulados     : {$skipped}\n";
echo "Erros       : {$errors}\n";

// Validação final
$totalComMidia = DB::table('product_media')
    ->join('products', 'products.id', '=', 'product_media.product_id')
    ->where('products.supplier_id', 1)
    ->distinct('product_media.product_id')
    ->count('product_media.product_id');

$totalProdutos = DB::table('products')->where('supplier_id', 1)->count();
$pct = $totalProdutos > 0 ? round($totalComMidia / $totalProdutos * 100, 1) : 0;

echo "\n--- Estado atual product_media ---\n";
echo "Total produtos supplier_id=1 : {$totalProdutos}\n";
echo "Com mídia                    : {$totalComMidia}\n";
echo "Cobertura                    : {$pct}%\n";
