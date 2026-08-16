<?php

namespace App\Services\Inventory;

use App\Models\Inventory;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Exception;

class InventoryService
{
    /**
     * Deduz a quantidade reservada (Quando um pedido é aprovado) e efetiva a Baixa.
     */
    public function decrementInventory(Product $product, int $warehouseId, int $qtyToDeduct): bool
    {
        return DB::transaction(function () use ($product, $warehouseId, $qtyToDeduct) {
            $inventory = Inventory::where('product_id', $product->id)
                ->where('warehouse_id', $warehouseId)
                ->lockForUpdate() // Concurrency Lock Check
                ->first();

            if (!$inventory || ($inventory->quantity - $inventory->reserved) < $qtyToDeduct) {
                throw new Exception("Insufficient stock for Product ID {$product->id} in Warehouse {$warehouseId}.");
            }

            // Ex: o pedido foi entregue e finalizado, deduz estoque e reserva.
            $inventory->decrement('quantity', $qtyToDeduct);

            return true;
        });
    }

    /**
     * Aumenta inventário via nova remessa (Conferência de Galpão)
     */
    public function incrementFromShipment(Product $product, int $warehouseId, int $producerId, int $qtyReceived): void
    {
        $inventory = Inventory::firstOrCreate(
            ['product_id' => $product->id, 'warehouse_id' => $warehouseId],
            ['producer_id' => $producerId, 'quantity' => 0, 'reserved' => 0]
        );

        $inventory->increment('quantity', $qtyReceived);
    }
}
