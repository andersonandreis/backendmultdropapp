<?php

namespace App\Services\Financial;

use App\Models\Order;
use App\Models\SupplierTransaction;
use App\Models\SupplierBalance;
use Illuminate\Support\Facades\DB;

class ReconciliationService
{
    /**
     * Efetua o crédito do custo de venda (Supplier's Cost) na conta do Produtor
     * Assim que o Seller (Lojista) concluir um pedido 'Paid' e despachado.
     */
    public function creditSale(Order $order): void
    {
        DB::transaction(function () use ($order) {
            foreach ($order->items as $item) {
                // Se foi vendido do estoque compartilhado (Produto de Galpão)
                if ($item->product && $item->product->supplier_id) {

                    // FOR-054: guard idempotencia — nao credita 2x mesmo pedido/produto
                    $alreadyCredited = SupplierTransaction::where('order_id', $order->id)
                        ->where('type', 'sale')
                        ->where('producer_id', $item->product->supplier_id)
                        ->exists();
                    if ($alreadyCredited) continue;

                    // O warehouse (Galpão que enviou) vs Producer (Dono do produto)
                    $producerId = $item->product->supplier_id;
                    $warehouseId = $order->supplier_id; // De qual Galpao a remessa saiu?
                    // FOR-047: usar CUSTO do fornecedor (supplier_unit_cost) e nao
                    // unit_price (venda ML). Antes: mock que creditava venda.
                    // Fornecedor recebe o CUSTO que o lojista pagou — que e o mesmo
                    // valor cobrado via FOR-045 (supplier_total).
                    $unitCost = (float) ($item->supplier_unit_cost ?? $item->unit_price);
                    $amountToCredit = $unitCost * $item->quantity;
                    if ($amountToCredit <= 0) {
                        continue; // Nao credita valor zero/invalido
                    }

                    // 1. Transaction Log (Ledger)
                    SupplierTransaction::create([
                        'producer_id' => $producerId,
                        'warehouse_id' => $warehouseId,
                        'type' => 'sale',
                        'amount' => $amountToCredit,
                        'description' => "Order #{$order->order_number} Item {$item->sku} Sale Credit",
                        'order_id' => $order->id,
                    ]);

                    // 2. Aumentar o Saldo Visual (Balance)
                    $balance = SupplierBalance::firstOrCreate(
                        ['producer_id' => $producerId, 'warehouse_id' => $warehouseId],
                        ['balance' => 0, 'total_earned' => 0, 'total_withdrawn' => 0]
                    );

                    $balance->increment('balance', $amountToCredit);
                    $balance->increment('total_earned', $amountToCredit);
                }
            }
        });
    }

    /**
     * Estorna creditos de venda em caso de chargeback, devolucao ou cancelamento.
     *
     * Para cada transacao 'sale' associada ao pedido, cria uma transacao de
     * ajuste negativo e decrementa o saldo e total_earned do fornecedor.
     */
    public function debitChargeback(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $salesTransactions = SupplierTransaction::where('order_id', $order->id)
                ->where('type', 'sale')
                ->get();

            foreach ($salesTransactions as $saleTx) {
                // 1. Criar transacao de estorno no ledger
                SupplierTransaction::create([
                    'producer_id'  => $saleTx->producer_id,
                    'warehouse_id' => $saleTx->warehouse_id,
                    'type'         => 'adjustment',
                    'amount'       => -abs($saleTx->amount),
                    'description'  => "Chargeback/estorno pedido #{$order->id}",
                    'order_id'     => $order->id,
                ]);

                // 2. Decrementar saldo visual do fornecedor
                $balance = SupplierBalance::where('producer_id', $saleTx->producer_id)
                    ->where('warehouse_id', $saleTx->warehouse_id)
                    ->first();

                if ($balance) {
                    $deductible = min((float) $balance->balance, abs($saleTx->amount));
                    $balance->decrement('balance', $deductible);
                    $balance->decrement('total_earned', abs($saleTx->amount));
                }
            }
        });
    }
}
