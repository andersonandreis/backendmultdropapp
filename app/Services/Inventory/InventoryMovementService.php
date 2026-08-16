<?php

namespace App\Services\Inventory;

use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * NOV-115 — Service único para alterar estoque com auditoria.
 *
 * Todo write em Inventory.quantity DEVE passar por aqui. O InventoryObserver
 * detecta updates externos (sync_marketplace) e grava como fallback.
 *
 * Idempotência: helpers recordSale/recordReturn checam reference duplicada antes
 * de criar movement (evita duplicar quando webhook reentrega).
 */
class InventoryMovementService
{
    /**
     * Flag estática: quando true, o InventoryObserver NÃO grava sync_marketplace
     * para o update em curso (evita double-logging do mesmo evento).
     */
    public static bool $internalUpdate = false;

    /**
     * Registra movimento + atualiza Inventory atomicamente.
     *
     * @param  array{notes?:string,user_id?:int,reference_type?:string,reference_id?:int,marketplace?:string,variation_id?:int}  $context
     */
    public function record(Inventory $inventory, string $type, int $qtyChange, array $context = []): InventoryMovement
    {
        return DB::transaction(function () use ($inventory, $type, $qtyChange, $context) {
            $inventory->refresh();
            $before = (int) $inventory->quantity;
            $after  = max(0, $before + $qtyChange);

            $movement = InventoryMovement::create([
                'supplier_id'    => $this->resolveSupplierId($inventory),
                'product_id'     => $inventory->product_id,
                'variation_id'   => $context['variation_id'] ?? null,
                'inventory_id'   => $inventory->id,
                'type'           => $type,
                'qty_before'     => $before,
                'qty_change'     => $qtyChange,
                'qty_after'      => $after,
                'reference_type' => $context['reference_type'] ?? null,
                'reference_id'   => $context['reference_id'] ?? null,
                'marketplace'    => $context['marketplace'] ?? null,
                'notes'          => $context['notes'] ?? null,
                'user_id'        => $context['user_id'] ?? null,
            ]);

            self::$internalUpdate = true;
            try {
                $inventory->update(['quantity' => $after]);
            } finally {
                self::$internalUpdate = false;
            }

            return $movement;
        });
    }

    /**
     * Ajuste manual via painel (Filament). Motivo é obrigatório no nível do form.
     */
    public function recordManualAdjust(Inventory $inventory, int $newQty, string $tipo, ?string $motivo, ?int $userId): InventoryMovement
    {
        $inventory->refresh();
        $qtd = abs($newQty);
        $qtyChange = match ($tipo) {
            'entrada' => $qtd,
            'saida'   => -$qtd,
            'ajuste'  => $newQty - (int) $inventory->quantity,
            'zerar'   => -(int) $inventory->quantity,
            default   => 0,
        };

        return $this->record($inventory, $tipo, $qtyChange, [
            'notes'          => $motivo,
            'user_id'        => $userId,
            'reference_type' => 'manual',
        ]);
    }

    /**
     * Baixa de venda — chamado pelo OrderObserver (NOV-117). Idempotente.
     */
    public function recordSale(Order $order, OrderItem $item, ?string $marketplace = null): ?InventoryMovement
    {
        $existing = InventoryMovement::query()
            ->withoutGlobalScopes()
            ->where('reference_type', 'order')
            ->where('reference_id', $order->id)
            ->where('product_id', $item->product_id)
            ->where('type', 'venda')
            ->first();

        if ($existing) {
            return $existing;
        }

        $inventory = Inventory::query()
            ->withoutGlobalScopes()
            ->where('product_id', $item->product_id)
            ->where('producer_id', $order->supplier_id)
            ->first();

        if (!$inventory) {
            Log::warning('[NOV-117] recordSale: inventory não encontrado', [
                'order_id'    => $order->id,
                'product_id'  => $item->product_id,
                'supplier_id' => $order->supplier_id,
            ]);
            return null;
        }

        $qty = (int) $item->quantity;
        $movement = $this->record($inventory, 'venda', -$qty, [
            'reference_type' => 'order',
            'reference_id'   => $order->id,
            'marketplace'    => $marketplace ?? $order->marketplace ?? 'manual',
            'notes'          => "Venda pedido #{$order->id}",
            'variation_id'   => $item->variation_id ?? null,
        ]);

        // NOV-133: se for kit/bundle, debita componentes proporcionalmente
        $this->decrementKitComponents($order, $item, $qty);

        return $movement;
    }

    /**
     * NOV-133 — Quando um kit é vendido, decrementa estoque dos componentes filhos.
     * Idempotente: usa reference_type='kit_component' para evitar duplicação.
     */
    private function decrementKitComponents(Order $order, OrderItem $item, int $qty): void
    {
        try {
            $components = \App\Models\ProductKit::query()
                ->where('product_id', $item->product_id)
                ->get();
            if ($components->isEmpty()) return;

            foreach ($components as $comp) {
                $childInv = Inventory::query()
                    ->withoutGlobalScopes()
                    ->where('product_id', $comp->child_product_id)
                    ->where('producer_id', $order->supplier_id)
                    ->first();
                if (!$childInv) continue;

                $exists = InventoryMovement::query()
                    ->withoutGlobalScopes()
                    ->where('reference_type', 'kit_component')
                    ->where('reference_id', $order->id)
                    ->where('product_id', $comp->child_product_id)
                    ->exists();
                if ($exists) continue;

                $childQty = $qty * (int) $comp->quantity;
                $this->record($childInv, 'venda', -$childQty, [
                    'reference_type' => 'kit_component',
                    'reference_id'   => $order->id,
                    'marketplace'    => $order->marketplace ?? null,
                    'notes'          => "Componente do kit do pedido #{$order->id} (qtd unit: {$comp->quantity})",
                ]);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[NOV-133] Falha decrementKitComponents', [
                'order_id'   => $order->id,
                'product_id' => $item->product_id,
                'err'        => $e->getMessage(),
            ]);
        }
    }

    /**
     * Reposição por devolução (NOV-120). Idempotente.
     */
    public function recordReturn(Order $order, OrderItem $item, int $qty, ?int $userId = null, ?string $motivo = null): ?InventoryMovement
    {
        $existing = InventoryMovement::query()
            ->withoutGlobalScopes()
            ->where('reference_type', 'order_return')
            ->where('reference_id', $order->id)
            ->where('product_id', $item->product_id)
            ->where('type', 'devolucao')
            ->first();

        if ($existing) {
            return $existing;
        }

        $inventory = Inventory::query()
            ->withoutGlobalScopes()
            ->where('product_id', $item->product_id)
            ->where('producer_id', $order->supplier_id)
            ->first();

        if (!$inventory) {
            return null;
        }

        return $this->record($inventory, 'devolucao', abs($qty), [
            'reference_type' => 'order_return',
            'reference_id'   => $order->id,
            'marketplace'    => $order->marketplace ?? null,
            'notes'          => $motivo ? "Devolução: {$motivo}" : 'Devolução de pedido',
            'user_id'        => $userId,
            'variation_id'   => $item->variation_id ?? null,
        ]);
    }

    private function resolveSupplierId(Inventory $inventory): int
    {
        return (int) ($inventory->producer_id ?? $inventory->warehouse_id);
    }
}
