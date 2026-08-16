<?php

namespace App\Console\Commands;

use App\Jobs\SyncInventoryJob;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * NOV-081: Reconciliacao periodica de estoque — equivalente ao auditoria_estoque_zerado.php do legado.
 *
 * Busca produtos cujo effectiveStock (soma de inventory.quantity) == 0 e que ainda tem anuncios
 * ativos no marketplace — dispara SyncInventoryJob que vai pausar o anuncio.
 *
 * Tambem busca produtos com estoque>0 que tem anuncios pausados por 'stock_zero'
 * e dispara SyncInventoryJob para reativar.
 *
 * Executa a cada 30min via schedule (routes/console.php).
 */
class InventoryReconcileCommand extends Command
{
    protected $signature = 'inventory:reconcile {--limit=500 : Maximo de produtos por execucao}';
    protected $description = 'Reconcilia anuncios com divergencia de status de estoque (stock_zero <-> active)';

    public function handle(): int
    {
        // INF-023: guard flag MARKETPLACE_SYNC_INVENTORY_ENABLED
        // Se desligada, SyncInventoryJob nao executa de verdade — nao enfileirar em massa.
        if (!config('marketplace.sync_inventory_enabled', false)) {
            $this->info('[InventoryReconcile] MARKETPLACE_SYNC_INVENTORY_ENABLED=false — skipping dispatch.');
            return 0;
        }

        $limit = (int) $this->option('limit');

        // --- Parte 1: produtos com effectiveStock=0 e anuncios ainda ativos ---
        // effectiveStock = SUM(inventory.quantity) — sem campo estoque direto em products
        // Nota: Product e ClientProduct nao usam SoftDeletes
        $zeroStockProducts = Product::where('is_active', true)
            ->whereHas('clientProducts', function ($q) {
                $q->whereIn('listing_status', ['active', 'published', 'synced'])
                  ->whereNotNull('external_listing_id')
                  ->where('excluido', 0);
            })
            ->whereDoesntHave('inventory', function ($q) {
                // Produto com ALGUMA unidade em estoque — excluir estes
                $q->where('quantity', '>', 0);
            })
            ->limit($limit)
            ->pluck('id');

        $this->info("[InventoryReconcile] {$zeroStockProducts->count()} produtos com estoque=0 e anuncios ativos — despachando pausa");

        foreach ($zeroStockProducts as $productId) {
            SyncInventoryJob::dispatch($productId)->onQueue('inventory');
        }

        // --- Parte 2: produtos com estoque>0 e anuncios pausados por stock_zero ---
        $restoreProducts = Product::where('is_active', true)
            ->whereHas('clientProducts', function ($q) {
                $q->where('listing_status', 'paused')
                  ->where('paused_reason', 'stock_zero')
                  ->whereNotNull('external_listing_id')
                  ->where('excluido', 0);
            })
            ->whereHas('inventory', function ($q) {
                // Produto com estoque positivo
                $q->where('quantity', '>', 0);
            })
            ->limit($limit)
            ->pluck('id');

        $this->info("[InventoryReconcile] {$restoreProducts->count()} produtos com estoque>0 e anuncios pausados por stock_zero — despachando reativacao");

        foreach ($restoreProducts as $productId) {
            SyncInventoryJob::dispatch($productId)->onQueue('inventory');
        }

        $total = $zeroStockProducts->count() + $restoreProducts->count();
        $this->info("[InventoryReconcile] Concluido — {$total} jobs despachados");

        return 0;
    }
}
