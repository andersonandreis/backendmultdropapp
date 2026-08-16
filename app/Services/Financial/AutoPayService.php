<?php

namespace App\Services\Financial;

use App\Models\ClientSupplierBalance;
use App\Models\ClientSupplierTransaction;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AutoPayService — debita automaticamente o saldo da carteira do lojista
 * quando um pedido é criado com status 'paid' e o cliente tem auto_pay ativo.
 *
 * Garante idempotência via wallet_paid_at: se já foi pago pela wallet, retorna false.
 * Usa lockForUpdate para evitar race condition em múltiplos workers.
 */
class AutoPayService
{
    /**
     * Tenta pagar o pedido automaticamente com o saldo da carteira.
     *
     * @return bool true se o débito foi efetuado, false caso contrário
     */
    public function tryAutoPay(Order $order): bool
    {
        // Idempotência: já foi pago pela wallet
        if ($order->wallet_paid_at !== null) {
            Log::info('[AutoPay] Pedido já pago pela wallet, ignorando.', [
                'order_id'              => $order->id,
                'wallet_transaction_id' => $order->wallet_transaction_id,
            ]);
            return false;
        }

        // Recarrega o client com lock-free (lock está no balance)
        $client = $order->client;

        if (! $client) {
            Log::warning('[AutoPay] Client não encontrado para o pedido.', ['order_id' => $order->id]);
            return false;
        }

        // Cliente tem auto-pay habilitado?
        if (! $client->auto_pay_from_wallet) {
            return false;
        }

        $supplierId    = $order->supplier_id;
        $supplierTotal = (float) $order->supplier_total;
        $minBalance    = (float) $client->auto_pay_min_balance;

        if ($supplierTotal <= 0) {
            Log::info('[AutoPay] supplier_total zerado, nada a debitar.', ['order_id' => $order->id]);
            return false;
        }

        // MUL-363 evento unico (decisao Ruan 11/08): autopay so cobra pedido PAGAVEL —
        // mesma regra do painel/lote: etiqueta disponivel, ou enviado/entregue, ou
        // Amazon Fulfillment. A POLITICA mora aqui (um lugar so); o DISPARO mora no
        // OrderObserver (evento "ficou pagavel"). Pedido sem etiqueta aguarda o evento.
        $pagavel = $order->label_url
            || $order->manual_label_path
            || in_array($order->status, ['shipped', 'delivered'], true)
            || preg_match('/fulfillment|fba/i', trim(($order->carrier_name ?? '') . ' ' . ($order->shipping_mode ?? '') . ' ' . ($order->channel_name ?? '')));
        if (! $pagavel) {
            Log::info('[AutoPay] Pedido ainda nao pagavel (sem etiqueta) — aguardando evento.', ['order_id' => $order->id]);
            return false;
        }

        return DB::transaction(function () use ($order, $client, $supplierId, $supplierTotal, $minBalance) {
            // Lock exclusivo no saldo para evitar race condition
            $balance = ClientSupplierBalance::where('client_id', $client->id)
                ->where('supplier_id', $supplierId)
                ->lockForUpdate()
                ->first();

            if (! $balance) {
                Log::info('[AutoPay] Sem saldo cadastrado para este cliente/fornecedor.', [
                    'client_id'   => $client->id,
                    'supplier_id' => $supplierId,
                ]);
                return false;
            }

            $available = (float) $balance->balance - $minBalance;

            if ($available < $supplierTotal) {
                Log::info('[AutoPay] Saldo insuficiente para auto-pay.', [
                    'order_id'       => $order->id,
                    'balance'        => $balance->balance,
                    'min_balance'    => $minBalance,
                    'available'      => $available,
                    'supplier_total' => $supplierTotal,
                ]);
                return false;
            }

            // MUL-363 Fase 1: debito via nucleo canonico — idempotencia dura por pedido
            // (retry de job/observer nao debita 2x; replay devolve a tx original),
            // payment_events logado, actor/origin gravados. A checagem de saldo minimo
            // (acima) e regra DESTE fluxo; o nucleo garante o resto.
            try {
                $transaction = app(\App\Services\Financial\Ledger\WalletLedger::class)->debit(
                    $client->id,
                    $supplierId,
                    $supplierTotal,
                    new \App\Services\Financial\Ledger\LedgerEntryMeta(
                        type: 'auto_pay',
                        description: 'Auto-pay pedido #' . $order->order_number,
                        orderId: $order->id,
                        actor: 'system:AutoPayService',
                        idempotencyKey: 'auto_pay:order:' . $order->id,
                        reference: 'auto_pay',
                    )
                );
            } catch (\App\Services\Financial\Ledger\InsufficientBalanceException $e) {
                // saldo mudou entre a checagem e o lock do nucleo
                Log::info('[AutoPay] Saldo insuficiente no momento do debito.', [
                    'order_id' => $order->id, 'error' => $e->getMessage(),
                ]);
                return false;
            }
            $newBalance = (float) $transaction->running_balance;

            // Marca o pedido como pago pela wallet
            $order->update([
                'wallet_paid_at'        => now(),
                'wallet_transaction_id' => $transaction->id,
            ]);

            Log::info('[AutoPay] Débito efetuado com sucesso.', [
                'order_id'        => $order->id,
                'order_number'    => $order->order_number,
                'client_id'       => $client->id,
                'supplier_id'     => $supplierId,
                'debited'         => $supplierTotal,
                'new_balance'     => $newBalance,
                'transaction_id'  => $transaction->id,
            ]);

            return true;
        });
    }
}
