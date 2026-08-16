<?php

namespace App\Services\Shipment;

use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\Inventory\InventoryService;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ShipmentService
{
    /**
     * Cria uma nova remessa de produtos do produtor para o galpão.
     *
     * Fulfillment flow: Cliente envia produtos DELE para armazenar no galpão do fornecedor.
     * O produtor (producer) envia → o galpão (warehouse) recebe e confere.
     *
     * Status: draft → sent → received → checking → checked
     */
    public function createShipment(
        int $producerId,
        int $warehouseId,
        array $items, // [['product_id' => 1, 'quantity' => 10], ...]
        ?string $notes = null
    ): Shipment {
        return DB::transaction(function () use ($producerId, $warehouseId, $items, $notes) {
            $shipment = Shipment::create([
                'producer_id'     => $producerId,
                'warehouse_id'    => $warehouseId,
                'shipment_number' => 'REM-' . strtoupper(Str::random(8)),
                'status'          => 'draft',
                'notes'           => $notes,
                'total_items'     => collect($items)->sum('quantity'),
            ]);

            foreach ($items as $item) {
                ShipmentItem::create([
                    'shipment_id'       => $shipment->id,
                    'product_id'        => $item['product_id'],
                    'quantity_sent'     => $item['quantity'],
                    'quantity_received' => 0,
                ]);
            }

            Log::info('[Shipment] Remessa criada', [
                'shipment_id' => $shipment->id,
                'number'      => $shipment->shipment_number,
                'producer'    => $producerId,
                'warehouse'   => $warehouseId,
                'items'       => count($items),
            ]);

            return $shipment;
        });
    }

    /**
     * Marca remessa como enviada pelo produtor.
     */
    public function markAsSent(Shipment $shipment): bool
    {
        if ($shipment->status !== 'draft') {
            throw new Exception("Só remessas em rascunho podem ser marcadas como enviadas.");
        }

        $shipment->update([
            'status'  => 'sent',
            'sent_at' => now(),
        ]);

        Log::info('[Shipment] Remessa enviada', ['shipment_id' => $shipment->id]);

        return true;
    }

    /**
     * Marca remessa como recebida pelo galpão. Inicia conferência.
     */
    public function markAsReceived(Shipment $shipment): bool
    {
        if ($shipment->status !== 'sent') {
            throw new Exception("Só remessas enviadas podem ser marcadas como recebidas.");
        }

        $shipment->update([
            'status'      => 'received',
            'received_at' => now(),
        ]);

        Log::info('[Shipment] Remessa recebida no galpão', ['shipment_id' => $shipment->id]);

        return true;
    }

    /**
     * Inicia conferência item a item.
     */
    public function startChecking(Shipment $shipment): bool
    {
        if (!in_array($shipment->status, ['received', 'checking'])) {
            throw new Exception("Só remessas recebidas podem iniciar conferência.");
        }

        $shipment->update(['status' => 'checking']);

        return true;
    }

    /**
     * Registra conferência de um item (scan individual).
     * Incrementa quantity_received no ShipmentItem.
     */
    public function checkItem(Shipment $shipment, int $productId, int $quantity = 1): ShipmentItem
    {
        if ($shipment->status !== 'checking') {
            throw new Exception("Remessa não está em conferência.");
        }

        $item = $shipment->items()->where('product_id', $productId)->first();

        if (!$item) {
            throw new Exception("Produto não encontrado nesta remessa.");
        }

        $newReceived = $item->quantity_received + $quantity;

        if ($newReceived > $item->quantity_sent) {
            throw new Exception("Quantidade conferida ({$newReceived}) excede a enviada ({$item->quantity_sent}).");
        }

        $item->update(['quantity_received' => $newReceived]);

        Log::info('[Shipment] Item conferido', [
            'shipment_id' => $shipment->id,
            'product_id'  => $productId,
            'received'    => $newReceived,
            'sent'        => $item->quantity_sent,
        ]);

        return $item->fresh();
    }

    /**
     * Galpão bateu o scanner indicando conferência total dos itens da Remessa.
     * Incrementa estoque no inventário.
     */
    public function finishShipmentChecking(Shipment $shipment): bool
    {
        if ($shipment->status !== 'checking') {
            throw new Exception("Can only finalize shipments currently in 'checking' status.");
        }

        return DB::transaction(function () use ($shipment) {
            $inventoryService = app(InventoryService::class);
            $totalReceived = 0;

            foreach ($shipment->items as $item) {
                if ($item->quantity_received > 0 && $item->product) {
                    $inventoryService->incrementFromShipment(
                        $item->product,
                        $shipment->warehouse_id,
                        $shipment->producer_id,
                        $item->quantity_received
                    );
                }
                $totalReceived += $item->quantity_received;
            }

            $shipment->update([
                'status'        => 'checked',
                'checked_at'    => now(),
                'total_checked' => $totalReceived,
            ]);

            Log::info('[Shipment] Conferência finalizada', [
                'shipment_id'    => $shipment->id,
                'total_received' => $totalReceived,
                'total_sent'     => $shipment->total_items,
            ]);

            return true;
        });
    }

    /**
     * Retorna resumo de progresso da conferência.
     */
    public function getCheckingProgress(Shipment $shipment): array
    {
        $items = $shipment->items;
        $totalSent = $items->sum('quantity_sent');
        $totalReceived = $items->sum('quantity_received');
        $pending = $items->filter(fn($i) => $i->quantity_received < $i->quantity_sent);

        return [
            'total_items'    => $items->count(),
            'total_sent'     => $totalSent,
            'total_received' => $totalReceived,
            'progress'       => $totalSent > 0 ? round(($totalReceived / $totalSent) * 100, 1) : 0,
            'pending_items'  => $pending->count(),
            'is_complete'    => $totalReceived >= $totalSent,
        ];
    }
}
