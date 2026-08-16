<?php

namespace App\Services\Financial;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PixTransaction;
use App\Models\Supplier;
use App\Services\ClientWalletService;
use App\Services\Events\EventDispatcherService;
use App\Services\Integrations\Factories\PaymentGatewayFactory;
use Illuminate\Support\Facades\Log;

/**
 * Orquestra o reembolso de pedidos no HubAI.
 *
 * Política por fornecedor (SupplierPaymentSetting::refund_policy):
 *   wallet_credit  → credita de volta na wallet do cliente (padrão)
 *   manual_estorno → tenta estorno via gateway; se falhar, loga para ação manual
 *
 * Após o reembolso bem-sucedido:
 *   - Atualiza o Payment (status = refunded, refunded_at, refund_amount, refund_reason)
 *   - Reverte no ledger do fornecedor via ReconciliationService::debitChargeback()
 *   - Dispara evento payment.refunded via EventDispatcherService
 */
class RefundService
{
    public function __construct(
        private ClientWalletService $walletService,
        private ReconciliationService $reconciliationService,
        private EventDispatcherService $eventDispatcher,
    ) {}

    /**
     * Processa o reembolso de um pedido.
     *
     * @param Order  $order   Pedido a ser reembolsado
     * @param float  $amount  Valor a reembolsar (pode ser parcial)
     * @param string $reason  Motivo do estorno (para auditoria)
     */
    public function processRefund(Order $order, float $amount, string $reason): bool
    {
        // Buscar o pagamento pago mais recente deste pedido
        $payment = Payment::where('order_id', $order->id)
            ->where('status', 'paid')
            ->latest()
            ->first();

        if (! $payment) {
            Log::warning('[RefundService] Nenhum pagamento pago encontrado para reembolso', [
                'order_id' => $order->id,
                'amount'   => $amount,
            ]);
            return false;
        }

        // Determinar política do fornecedor
        $supplier = Supplier::with('paymentSetting')->find($payment->supplier_id);
        $policy   = $supplier?->paymentSetting?->refund_policy ?? 'wallet_credit';

        Log::info('[RefundService] Iniciando reembolso', [
            'order_id'   => $order->id,
            'amount'     => $amount,
            'policy'     => $policy,
            'supplier_id'=> $payment->supplier_id,
        ]);

        $success = match ($policy) {
            'manual_estorno' => $this->refundViaGateway($payment, $amount, $reason),
            default          => $this->refundToWallet($order, $payment, $amount, $reason),
        };

        // Se estorno via gateway falhou, logar para ação manual — sem fallback automático
        // para não creditar wallet sem autorização explícita do fornecedor
        if (! $success && $policy === 'manual_estorno') {
            Log::warning('[RefundService] Estorno via gateway falhou — ação manual necessária', [
                'order_id'   => $order->id,
                'payment_id' => $payment->id,
                'amount'     => $amount,
                'gateway'    => $payment->gateway,
            ]);
        }

        if ($success) {
            // Atualizar Payment
            $payment->update([
                'status'        => 'refunded',
                'refunded_at'   => now(),
                'refund_amount' => $amount,
                'refund_reason' => $reason,
            ]);

            // Reverter no ledger do fornecedor
            $this->reconciliationService->debitChargeback($order);

            // Disparar evento interno
            $this->eventDispatcher->dispatch('payment.refunded', [
                'order_id'   => $order->id,
                'payment_id' => $payment->id,
                'amount'     => $amount,
                'reason'     => $reason,
                'method'     => $policy,
            ], $payment->supplier_id);
        }

        return $success;
    }

    // -------------------------------------------------------------------------
    // Estratégias de reembolso
    // -------------------------------------------------------------------------

    /**
     * Reembolsa creditando de volta na wallet do cliente.
     */
    private function refundToWallet(Order $order, Payment $payment, float $amount, string $reason): bool
    {
        try {
            $this->walletService->creditRefund(
                $order->client_id,
                $payment->supplier_id,
                $amount,
                $order,
                $reason,
            );

            Log::info('[RefundService] Crédito na wallet realizado', [
                'order_id'    => $order->id,
                'client_id'   => $order->client_id,
                'supplier_id' => $payment->supplier_id,
                'amount'      => $amount,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('[RefundService] Falha ao creditar wallet', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Tenta estorno diretamente no gateway (Asaas / Pagarme).
     * Shipay não suporta estorno via API — retorna false imediatamente.
     */
    private function refundViaGateway(Payment $payment, float $amount, string $reason): bool
    {
        // Shipay não suporta estorno via API
        if ($payment->gateway === 'shipay') {
            Log::info('[RefundService] Shipay não suporta estorno via API, requer ação manual', [
                'payment_id' => $payment->id,
            ]);
            return false;
        }

        try {
            $supplier = Supplier::with('paymentSetting')->find($payment->supplier_id);

            if (! $supplier?->paymentSetting) {
                Log::warning('[RefundService] Supplier sem configuração de gateway para estorno', [
                    'payment_id'  => $payment->id,
                    'supplier_id' => $payment->supplier_id,
                ]);
                return false;
            }

            $gateway = PaymentGatewayFactory::makeForSupplier($supplier);

            // Resolver external_id do pagamento
            $externalId = $payment->external_id;

            if (! $externalId && $payment->pix_transaction_id) {
                $pixTx      = PixTransaction::find($payment->pix_transaction_id);
                $externalId = $pixTx?->external_id;
            }

            if (! $externalId) {
                Log::warning('[RefundService] Sem external_id para estorno via gateway', [
                    'payment_id' => $payment->id,
                ]);
                return false;
            }

            $result = $gateway->refundPayment($externalId, $amount, $reason);

            Log::info('[RefundService] Estorno via gateway concluído', [
                'payment_id'  => $payment->id,
                'external_id' => $externalId,
                'amount'      => $amount,
                'success'     => $result,
            ]);

            return $result;
        } catch (\Throwable $e) {
            Log::error('[RefundService] Exceção durante estorno via gateway', [
                'payment_id' => $payment->id,
                'error'      => $e->getMessage(),
            ]);

            return false;
        }
    }
}
