<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Services\ClientWalletService;
use App\Services\Inventory\InventoryMovementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * NOV-120 — Registra devolução de itens, repõe estoque automaticamente
 * e atualiza status do Order quando todos os itens forem devolvidos.
 *
 * NOV-125 — Quando o pedido tem pagamento confirmado (status in [paid, shipped, delivered]),
 * credita o valor proporcional do retorno na wallet do cliente (ClientSupplierBalance).
 */
class OrderReturnService
{
    public function __construct(
        private InventoryMovementService $movements,
        private ClientWalletService $wallet,
    ) {}

    /**
     * @param  array<array{order_item_id:int,qty_returned:int}>  $items
     */
    public function register(Order $order, array $items, ?string $reason, ?int $userId = null): array
    {
        if (empty($items)) {
            throw new \InvalidArgumentException('Sem itens para devolver.');
        }

        return DB::transaction(function () use ($order, $items, $reason, $userId) {
            $records = [];
            $itemsTotal = $order->items()->count();
            $itemsReturnedNow = 0;
            $creditAccumulated = 0.0;

            foreach ($items as $payload) {
                $itemId = (int) ($payload['order_item_id'] ?? 0);
                $qty    = (int) ($payload['qty_returned'] ?? 0);
                if ($itemId <= 0 || $qty <= 0) {
                    continue;
                }

                /** @var OrderItem|null $item */
                $item = OrderItem::where('order_id', $order->id)->where('id', $itemId)->first();
                if (!$item) {
                    continue;
                }

                $maxQty = (int) $item->quantity;
                $qty    = min($qty, $maxQty);

                $return = OrderReturn::create([
                    'order_id'      => $order->id,
                    'order_item_id' => $item->id,
                    'qty_returned'  => $qty,
                    'reason'        => $reason,
                    'user_id'       => $userId,
                ]);

                $this->movements->recordReturn($order, $item, $qty, $userId, $reason);
                $records[] = $return;
                $itemsReturnedNow++;

                // NOV-125: acumula crédito proporcional pelo item devolvido
                $unitPrice = (float) ($item->supplier_unit_price ?? $item->unit_price ?? 0);
                $creditAccumulated += $unitPrice * $qty;
            }

            // Se houve devolução de TODOS os itens (count agregado), marcar como returned.
            $totalReturned = OrderReturn::where('order_id', $order->id)
                ->distinct('order_item_id')
                ->count('order_item_id');

            if ($totalReturned >= $itemsTotal) {
                $order->status      = 'returned';
                $order->returned_at = now();
                $order->return_reason = $reason;
                $order->has_partial_return = false;
                $order->save();
            } else {
                $order->has_partial_return = true;
                $order->saveQuietly();
            }

            // NOV-125: crédito na wallet — somente se pedido foi pago de verdade
            if ($creditAccumulated > 0 && $order->client_id && $order->supplier_id
                && in_array($order->getOriginal('status'), ['paid', 'shipped', 'delivered', 'completed'])) {
                try {
                    $tx = $this->wallet->credit(
                        $order->client_id,
                        $order->supplier_id,
                        round($creditAccumulated, 2),
                        'Crédito de devolução pedido #'.$order->order_number.' ('.($reason ?: 'sem motivo').')',
                        $order->id,
                        'order_return:'.$order->id
                    );
                    Log::info('[NOV-125] Crédito devolução emitido', [
                        'order_id'    => $order->id,
                        'client_id'   => $order->client_id,
                        'supplier_id' => $order->supplier_id,
                        'amount'      => $creditAccumulated,
                        'tx_id'       => $tx->id,
                    ]);
                } catch (\Throwable $e) {
                    // Não falhar a devolução por causa do crédito — apenas logar.
                    Log::error('[NOV-125] Falha ao creditar wallet em devolução', [
                        'order_id' => $order->id,
                        'err'      => $e->getMessage(),
                    ]);
                }
            }

            Log::info('[NOV-120] Devolução registrada', [
                'order_id' => $order->id,
                'items_returned_now' => $itemsReturnedNow,
                'total_distinct_returned' => $totalReturned,
                'items_total' => $itemsTotal,
                'status_after' => $order->status,
                'credit_emitted' => $creditAccumulated,
            ]);

            return $records;
        });
    }
}
