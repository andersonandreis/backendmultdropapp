<?php

namespace App\Jobs;

use App\Models\ClientSupplierBalance;
use App\Models\ClientSupplierTransaction;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Auditoria financeira: detecta pedidos que passaram para status paid/accepted/shipped
 * sem que o fornecedor tenha sido pago (wallet_paid_at IS NULL + supplier_total > 0).
 *
 * Para cada pedido encontrado:
 *   - Se nao enviado (paid/accepted/in_fulfillment): registra divida negativa no saldo
 *   - Se enviado (shipped/delivered/completed): registra divida negativa (produto ja saiu)
 *
 * Em ambos os casos, wallet_paid_at é preenchido para sair da fila de "nao pagos",
 * mas a transaction_type = 'pending_debt' ou 'shipped_debt' marca que é divida.
 * O saldo fica negativo — ao proximo topup, debt recovery em webhookShipay cobre.
 *
 * Roda a cada 4 horas via schedule. Idempotente (verifica wallet_paid_at antes de agir).
 */
class AuditUnpaidOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 120;

    public function handle(): void
    {
        // Guard MES-048: desligado por padrao (AUDIT_UNPAID_ORDERS_ENABLED=false).
        // Evita cobranca dupla enquanto legado e fonte de verdade para pagamentos.
        if (!config("audit.unpaid_orders_enabled", false)) {
            \Illuminate\Support\Facades\Log::info("[AuditUnpaidOrders] desabilitado via AUDIT_UNPAID_ORDERS_ENABLED.");
            return;
        }

        $orders = Order::whereNull('wallet_paid_at')
            ->whereRaw('IFNULL(supplier_total, 0) > 0')
            // MUL-379: shipped/delivered/completed SAIRAM daqui. Pedido enviado sem
            // pagamento e pedido que o lojista despachou por conta propria — virar
            // 'shipped_debt' cobraria produto que nunca foi nosso. O job segue
            // desligado por padrao (MES-048), mas nao pode nascer errado se ligarem.
            ->whereIn('canonical_status', [
                'paid', 'accepted', 'in_fulfillment',
            ])
            ->whereNotNull('supplier_id')
            // MUL-269 fase 2: clients.company_name foi removido; nome vem do user via accessor.
            ->with(['client:id,user_id,auto_pay_from_wallet,auto_pay_min_balance', 'client.user:id,name,full_name'])
            ->orderBy('id')
            ->limit(200)
            ->get();

        if ($orders->isEmpty()) {
            return;
        }

        Log::warning('[AuditUnpaidOrders] Encontrados pedidos nao pagos', [
            'count' => $orders->count(),
            'ids'   => $orders->pluck('id')->toArray(),
        ]);

        $registered = 0;

        foreach ($orders as $order) {
            try {
                $this->registerDebt($order);
                $registered++;
            } catch (\Throwable $e) {
                Log::error('[AuditUnpaidOrders] Erro ao registrar divida', [
                    'order_id' => $order->id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        Log::warning('[AuditUnpaidOrders] Dividas registradas', [
            'registered' => $registered,
            'total'      => $orders->count(),
        ]);
    }

    private function registerDebt(Order $order): void
    {
        $supplierId    = $order->supplier_id;
        $clientId      = $order->client_id;
        $supplierTotal = (float) $order->supplier_total;
        $shipped       = in_array($order->canonical_status, ['shipped', 'delivered', 'completed']);
        $type          = $shipped ? 'shipped_debt' : 'pending_debt';

        DB::transaction(function () use ($order, $supplierId, $clientId, $supplierTotal, $shipped, $type) {
            // Re-check dentro da transação para evitar race condition
            $fresh = Order::lockForUpdate()->find($order->id);
            if (!$fresh || $fresh->wallet_paid_at !== null) {
                return;
            }

            // MUL-363 Fase 3: debito de divida via nucleo canonico. allowOverdraft=true
            // (divida negativa e o proposito). Idempotencia dura por pedido: o retry do
            // job NAO registra a divida 2x (era o vilao da MUL-283/286).
            $tx = app(\App\Services\Financial\Ledger\WalletLedger::class)->debit(
                $clientId,
                $supplierId,
                $supplierTotal,
                new \App\Services\Financial\Ledger\LedgerEntryMeta(
                    type: $type,
                    description: ($shipped ? '[ENVIADO SEM PAGAR] ' : '[PENDENTE NAO PAGO] ') . 'Pedido #' . $order->order_number,
                    orderId: $order->id,
                    actor: 'system:AuditUnpaidOrdersJob',
                    idempotencyKey: "audit_debt:order:{$order->id}",
                    reference: 'audit_debt',
                ),
                true
            );

            $fresh->update([
                'wallet_paid_at'        => now(),
                'wallet_transaction_id' => $tx->id,
            ]);

            Log::warning('[AuditUnpaidOrders] Divida registrada', [
                'order_id'       => $order->id,
                'order_number'   => $order->order_number,
                'client_id'      => $clientId,
                'supplier_id'    => $supplierId,
                'amount'         => $supplierTotal,
                'new_balance'    => $newBalance,
                'shipped'        => $shipped,
                'type'           => $type,
            ]);
        });
    }
}
