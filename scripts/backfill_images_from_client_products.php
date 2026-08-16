<?php
/**
 * MUL-064 — Backfill product_media a partir de client_products.image_url
 *
 * Copia image_url de client_products para product_media nos produtos que
 * nao tem nenhuma midia cadastrada mas tem image_url vindo de marketplace
 * (Shopee CDN, ML static, etc.) em importacoes de lojistas.
 *
 * CONCLUSAO DO DIAGNOSTICO (2026-06-28):
 * - 6.345 produtos sem midia no supplier_id=1
 * - sku_pai.img no legado: 0 imagens novas disponíveis (já foram todas sincronizadas)
 * - sku_pai_imagens: idem
 * - client_products.image_url: 16 produtos com URLs Shopee/ML
 *
 * Uso: php scripts/backfill_images_from_client_products.php [--dry-run]
 */

require_once __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Observers\ProductObserver;
use Illuminate\Support\Facades\DB;

$dryRun = in_array('--dry-run', $argv ?? []);

echo "=== MUL-064 Backfill imagens client_products.image_url -> product_media ===\n";
echo "Modo: " . ($dryRun ? "DRY-RUN" : "PRODUÇÃO") . "\n\n";

ProductObserver::$disableSync = true;
echo "[OK] ProductObserver::\$disableSync = true\n\n";

$inserted = 0;
$skipped  = 0;
$errors   = 0;

// Pegar produtos sem midia que tem image_url em client_products
$rows = DB::table('client_products as cp')
    ->join('products as p', 'p.id', '=', 'cp.product_id')
    ->where('p.supplier_id', 1)
    ->whereNotNull('cp.image_url')
    ->where('cp.image_url', '!=', '')
    ->whereNotExists(function ($q) {
        $q->select(DB::raw(1))
          ->from('product_media as pm')
          ->whereColumn('pm.product_id', 'p.id');
    })
    ->select('p.id as product_id', 'p.sku', 'cp.image_url')
    ->distinct()
    ->get();

echo "Produtos encontrados: " . $rows->count() . "\n\n";

foreach ($rows as $row) {
    $imgUrl = trim($row->image_url);

    if (!str_starts_with($imgUrl, 'http')) {
        echo "  [SKIP] product_id={$row->product_id} sku={$row->sku} url inválida: {$imgUrl}\n";
        $skipped++;
        continue;
    }

    if ($dryRun) {
        echo "  [DRY] product_id={$row->product_id} sku={$row->sku} -> {$imgUrl}\n";
        $inserted++;
        continue;
    }

    try {
        $exists = DB::table('product_media')
            ->where('product_id', $row->product_id)
            ->where('url', $imgUrl)
            ->exists();

        if (!$exists) {
            DB::table('product_media')->insert([
                'product_id'   => $row->product_id,
                'type'         => 'image',
                'url'          => $imgUrl,
                'original_url' => $imgUrl,
                'is_cover'     => 1,
                'position'     => 0,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
            echo "  [OK] product_id={$row->product_id} sku={$row->sku}\n";
            $inserted++;
        } else {
            $skipped++;
        }
    } catch (\Exception $e) {
        $errors++;
        echo "  [ERR] product_id={$row->product_id}: " . $e->getMessage() . "\n";
    }
}

ProductObserver::$disableSync = false;
echo "\n[OK] ProductObserver::\$disableSync = false\n";

echo "\n=== RESULTADO ===\n";
echo "Inseridos : {$inserted}\n";
echo "Pulados   : {$skipped}\n";
echo "Erros     : {$errors}\n";

$totalComMidia = DB::table('product_media')
    ->join('products', 'products.id', '=', 'product_media.product_id')
    ->where('products.supplier_id', 1)
    ->distinct('product_media.product_id')
    ->count('product_media.product_id');

$totalProdutos = DB::table('products')->where('supplier_id', 1)->count();
$pct = $totalProdutos > 0 ? round($totalComMidia / $totalProdutos * 100, 1) : 0;

echo "\n--- Estado product_media após backfill ---\n";
echo "Total produtos : {$totalProdutos}\n";
echo "Com mídia      : {$totalComMidia}\n";
echo "Cobertura      : {$pct}%\n";
