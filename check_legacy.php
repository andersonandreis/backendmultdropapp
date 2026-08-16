<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

echo "=== Legacy sku_pai check ==="\n;
$lastSync = Cache::get('legacy_catalog_last_sync', now()->subHour()->toDateTimeString());
echo "Last sync cursor from cache: " . $lastSync . "\n\n";

$result = DB::connection('legacy')
    ->table('sku_pai')
    ->join('deposito', 'sku_pai.id_deposito', '=', 'deposito.id')
    ->where('deposito.liberada', 1)
    ->where('sku_pai.data_update', '>', $lastSync)
    ->select('sku_pai.id', 'sku_pai.sku', 'sku_pai.estoque', 'sku_pai.data_update', 'deposito.id as dep_id', 'deposito.menu_titulo')
    ->limit(5)
    ->get();

echo "Updated sku_pai since last sync: " . count($result) . " rows\n";
if (count($result) > 0) {
    foreach ($result as $row) {
        echo "  - SKU: " . $row->sku . " (legacy_id=" . $row->id . ", estoque=" . $row->estoque . ", data_update=" . $row->data_update . ")\n";
    }
}

echo "\n=== Current Suppliers ==="\n";
$suppliers = DB::table('suppliers')->where('is_active', true)->select('id', 'legacy_id', 'display_name')->get();
foreach ($suppliers as $s) {
    echo "  Supplier #" . $s->id . ": " . $s->display_name . " (legacy_id=" . $s->legacy_id . ")\n";
}

echo "\n=== Current Inventory ==="\n";
$inv = DB::table('inventory')->select(DB::raw('COUNT(*) as count, SUM(quantity) as total_qty'))->first();
echo "Total inventory records: " . $inv->count . " (sum qty = " . $inv->total_qty . ")\n";

