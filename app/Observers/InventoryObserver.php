<?php

namespace App\Observers;

use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Shipment;
use App\Enums\ShipmentStatus;
use App\Services\Inventory\InventoryMovementService;
use App\Observers\Concerns\PreventsLegacyLoop;

class InventoryObserver
{
    use PreventsLegacyLoop;

    public function created(Inventory $inventory): void
    {
        // Ex: Validar com Warehouse/Fornecedor sobre inicio de monitoramento
    }

    /**
     * Handle the Inventory updated event.
     * - Dispara sync de estoque pro marketplace quando quantidade muda.
     * - NOV-115: grava InventoryMovement type=sync_marketplace para updates externos
     *   (que nao vieram do InventoryMovementService, ex: SyncInventoryJob/BlingProductSync).
     */
    public function updated(Inventory $inventory): void
    {
        // Guard anti-loop via trait PreventsLegacyLoop.
        // Se ProductObserver desabilitou o sync (ex: SyncProductToLegacy
        // atualizando inventario), nao re-despacha SyncProductToLegacy novamente.
        if ($this->isLegacySyncInProgress()) {
            return;
        }

        if (!$inventory->isDirty('quantity')) {
            return;
        }

        // NOV-115: log de auditoria quando o update NAO veio do service.
        if (!InventoryMovementService::$internalUpdate) {
            $this->logExternalUpdate($inventory);
        }

        $product = $inventory->product;

        if (!$product || !$product->is_active) {
            return;
        }

        $hasListings = $product->clientProducts()
            ->whereNotNull('external_listing_id')
            ->exists();

        // INF-023: guard flag MARKETPLACE_SYNC_INVENTORY_ENABLED — nao despachar
        // SyncInventoryJob quando a feature flag esta desligada (evita acumulo de 100k+ jobs noop)
        if ($hasListings && config('marketplace.sync_inventory_enabled', false)) {
            \App\Jobs\SyncInventoryJob::dispatch($product->id)
                ->onQueue('inventory')
                ->delay(now()->addSeconds(10));
        }

        if (config('app.legacy_sync_enabled')) { \App\Jobs\SyncProductToLegacy::dispatch($product->id); } // HUB-425
    }

    public function deleted(Inventory $inventory): void
    {
        // Remove tracking references
    }

    private function logExternalUpdate(Inventory $inventory): void
    {
        try {
            $before = (int) $inventory->getOriginal('quantity');
            $after  = (int) $inventory->quantity;
            $delta  = $after - $before;
            $supplierId = (int) ($inventory->producer_id ?? $inventory->warehouse_id);

            if ($supplierId <= 0) {
                return;
            }

            InventoryMovement::create([
                'supplier_id'    => $supplierId,
                'product_id'     => $inventory->product_id,
                'inventory_id'   => $inventory->id,
                'type'           => 'sync_marketplace',
                'qty_before'     => $before,
                'qty_change'     => $delta,
                'qty_after'      => $after,
                'reference_type' => 'observer',
                'notes'          => 'Alteracao externa (nao rastreada via service)',
            ]);
        } catch (\Throwable $e) {
            \Log::warning('[NOV-115] InventoryObserver logExternalUpdate falhou: '.$e->getMessage(), [
                'inventory_id' => $inventory->id,
            ]);
        }
    }
}