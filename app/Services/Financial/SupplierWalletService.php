<?php

namespace App\Services\Financial;

use App\Models\Order;
use App\Models\SupplierBalance;
use App\Models\SupplierTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Gerencia o saldo do fornecedor (supplier_balances / supplier_transactions).
 *
 * Modelo de dados:
 *   - producer_id  = supplier dono do produto (item->product->supplier_id)
 *   - warehouse_id = supplier que despachou o pedido (order->supplier_id)
 *   - Quando o supplier vende seus proprios produtos, producer_id == warehouse_id.
 *
 * NOV-157: criado para creditar o fornecedor apos debito do lojista no OrderObserver.
 */
class SupplierWalletService
{
    /**
     * Credita o fornecedor pelo valor de venda de um pedido pago.
     *
     * Percorre os itens do pedido para identificar o producer_id correto de cada
     * item. Caso os itens nao estejam carregados ou nao tenham product_id, usa
     * o order->supplier_id como producer_id tambem (fallback seguro).
     *
     * Idempotencia: verifica se ja existe SupplierTransaction do tipo "sale"
     * para o order_id antes de inserir para evitar credito duplicado.
     *
     * @param  int    $warehouseId  supplier_id do pedido (galpao que despachou)
     * @param  float  $amount       valor total a creditar (supplier_total do pedido)
     * @param  Order  $order        pedido que originou o credito
     */
    public function creditOrderSale(int $warehouseId, float $amount, Order $order): void
    {
        if ($amount <= 0) {
            Log::warning('[SupplierWalletService] creditOrderSale ignorado: amount <= 0', [
                'order_id'     => $order->id,
                'order_number' => $order->order_number,
                'amount'       => $amount,
            ]);
            return;
        }

        // Idempotencia: nao creditar duas vezes o mesmo pedido
        $alreadyCredited = SupplierTransaction::where('order_id', $order->id)
            ->where('type', 'sale')
            ->exists();

        if ($alreadyCredited) {
            Log::info('[SupplierWalletService] creditOrderSale ignorado: ja creditado', [
                'order_id' => $order->id,
            ]);
            return;
        }

        DB::transaction(function () use ($warehouseId, $amount, $order) {
            $items = $order->relationLoaded('items') ? $order->items : $order->items()->with('product')->get();

            if ($items->isNotEmpty()) {
                // Agrupar por producer_id para lancar uma transaction por produtor
                $grouped = $items->groupBy(function ($item) use ($warehouseId) {
                    return $item->product?->supplier_id ?? $warehouseId;
                });

                foreach ($grouped as $producerId => $groupItems) {
                    $groupAmount = $groupItems->sum(function ($item) {
                        return (float) ($item->supplier_total_cost ?? $item->unit_price * $item->quantity);
                    });

                    if ($groupAmount <= 0) {
                        continue;
                    }

                    $this->creditProducerWarehouse(
                        (int) $producerId,
                        $warehouseId,
                        $groupAmount,
                        $order
                    );
                }
            } else {
                // Fallback: sem itens carregados, credita tudo no proprio supplier
                $this->creditProducerWarehouse(
                    $warehouseId,
                    $warehouseId,
                    $amount,
                    $order
                );
            }
        });

        Log::info('[SupplierWalletService] creditOrderSale concluido', [
            'order_id'     => $order->id,
            'order_number' => $order->order_number,
            'warehouse_id' => $warehouseId,
            'amount'       => $amount,
        ]);
    }

    /**
     * Estorna o credito de venda de um pedido (cancelamento / devolucao).
     *
     * Encontra todas as SupplierTransactions do tipo "sale" para o pedido
     * e cria entradas de "adjustment" negativo correspondentes.
     */
    public function debitOrderChargeback(Order $order): void
    {
        $sales = SupplierTransaction::where('order_id', $order->id)
            ->where('type', 'sale')
            ->get();

        if ($sales->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($sales, $order) {
            foreach ($sales as $saleTx) {
                SupplierTransaction::create([
                    'producer_id'  => $saleTx->producer_id,
                    'warehouse_id' => $saleTx->warehouse_id,
                    'type'         => 'adjustment',
                    'amount'       => -abs((float) $saleTx->amount),
                    'description'  => "Estorno pedido #{$order->order_number} (status: {$order->status})",
                    'order_id'     => $order->id,
                ]);

                $balance = SupplierBalance::where('producer_id', $saleTx->producer_id)
                    ->where('warehouse_id', $saleTx->warehouse_id)
                    ->first();

                if ($balance) {
                    $deductible = min((float) $balance->balance, abs((float) $saleTx->amount));
                    $balance->decrement('balance', $deductible);
                    $balance->decrement('total_earned', abs((float) $saleTx->amount));
                }
            }
        });

        Log::info('[SupplierWalletService] debitOrderChargeback concluido', [
            'order_id'     => $order->id,
            'order_number' => $order->order_number,
            'sales_count'  => $sales->count(),
        ]);
    }

    /**
     * Credita um par producer/warehouse no saldo do fornecedor.
     */
    private function creditProducerWarehouse(
        int $producerId,
        int $warehouseId,
        float $amount,
        Order $order
    ): void {
        SupplierTransaction::create([
            'producer_id'  => $producerId,
            'warehouse_id' => $warehouseId,
            'type'         => 'sale',
            'amount'       => $amount,
            'description'  => "Venda pedido #{$order->order_number}",
            'order_id'     => $order->id,
        ]);

        $balance = SupplierBalance::firstOrCreate(
            ['producer_id' => $producerId, 'warehouse_id' => $warehouseId],
            ['balance' => 0, 'total_earned' => 0, 'total_withdrawn' => 0]
        );

        $balance->increment('balance', $amount);
        $balance->increment('total_earned', $amount);
    }
}
