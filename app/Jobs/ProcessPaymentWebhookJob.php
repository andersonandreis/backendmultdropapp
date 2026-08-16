<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\Order;
use App\Models\Supplier;
use App\Services\ClientWalletService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Processa webhooks de pagamento (Asaas/Shipay) para detectar recarga PIX na wallet.
 *
 * Fluxo:
 *  1. Recebe payload do gateway
 *  2. Identifica se e uma recarga de wallet (via externalReference com prefixo "wallet_")
 *  3. Credita o saldo do lojista via ClientWalletService
 */
class ProcessPaymentWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    protected array $payload;
    protected string $gateway; // 'asaas' ou 'shipay'

    public function __construct(array $payload, string $gateway = 'asaas')
    {
        $this->payload = $payload;
        $this->gateway = $gateway;
    }

    public function handle(ClientWalletService $walletService): void
    {
        Log::info("[ProcessPaymentWebhook] Iniciando processamento ({$this->gateway})", $this->payload);

        if ($this->gateway === 'asaas') {
            $this->handleAsaas($walletService);
        } elseif ($this->gateway === 'shipay') {
            $this->handleShipay($walletService);
        } else {
            Log::warning("[ProcessPaymentWebhook] Gateway desconhecido: {$this->gateway}");
        }
    }

    /**
     * Processa webhook Asaas para recarga PIX wallet.
     *
     * Asaas envia:
     * {
     *   "event": "PAYMENT_RECEIVED",
     *   "payment": {
     *     "id": "pay_xxx",
     *     "billingType": "PIX",
     *     "value": 100.00,
     *     "externalReference": "wallet_<client_id>_<supplier_id>",
     *     ...
     *   }
     * }
     */
    protected function handleAsaas(ClientWalletService $walletService): void
    {
        $event = $this->payload['event'] ?? null;
        $payment = $this->payload['payment'] ?? [];

        // Somente processa pagamentos confirmados/recebidos
        if (!in_array($event, ['PAYMENT_RECEIVED', 'PAYMENT_CONFIRMED'])) {
            Log::info("[ProcessPaymentWebhook] Evento Asaas ignorado: {$event}");
            return;
        }

        $billingType = $payment['billingType'] ?? null;
        $externalRef = $payment['externalReference'] ?? '';
        $value = (float) ($payment['value'] ?? 0);
        $paymentId = $payment['id'] ?? 'unknown';

        // Detecta se e recarga de wallet pelo prefixo "wallet_"
        if (!str_starts_with($externalRef, 'wallet_')) {
            Log::info("[ProcessPaymentWebhook] Pagamento Asaas nao e recarga wallet, ignorando", [
                'external_reference' => $externalRef,
            ]);
            return;
        }

        // Parse: wallet_{clientId}_{supplierId}
        $parts = explode('_', $externalRef);
        if (count($parts) < 3) {
            Log::error("[ProcessPaymentWebhook] ExternalReference invalido: {$externalRef}");
            return;
        }

        $clientId = (int) $parts[1];
        $supplierId = (int) $parts[2];

        // Validar existencia do client e supplier
        $client = Client::find($clientId);
        $supplier = Supplier::find($supplierId);

        if (!$client || !$supplier) {
            Log::error("[ProcessPaymentWebhook] Client #{$clientId} ou Supplier #{$supplierId} nao encontrado");
            return;
        }

        if ($value <= 0) {
            Log::warning("[ProcessPaymentWebhook] Valor zero ou negativo, ignorando", ['value' => $value]);
            return;
        }

        // Evita duplicidade: verifica se ja creditamos este payment_id
        $exists = \App\Models\ClientSupplierTransaction::where('client_id', $clientId)
            ->where('supplier_id', $supplierId)
            ->where('description', 'like', "%{$paymentId}%")
            ->where('type', 'credit')
            ->exists();

        if ($exists) {
            Log::info("[ProcessPaymentWebhook] Pagamento {$paymentId} ja processado, ignorando duplicata");
            return;
        }

        try {
            $transaction = $walletService->credit(
                $clientId,
                $supplierId,
                $value,
                "Recarga PIX via {$billingType} - Asaas #{$paymentId}",
                null, // sem order_id (e recarga, nao pedido)
                $paymentId
            );

            Log::info("[ProcessPaymentWebhook] Wallet creditada com sucesso", [
                'client_id' => $clientId,
                'supplier_id' => $supplierId,
                'amount' => $value,
                'payment_id' => $paymentId,
                'transaction_id' => $transaction->id,
            ]);
        } catch (\Exception $e) {
            Log::error("[ProcessPaymentWebhook] Erro ao creditar wallet", [
                'error' => $e->getMessage(),
                'client_id' => $clientId,
                'supplier_id' => $supplierId,
                'payment_id' => $paymentId,
            ]);
            throw $e; // Re-throw para retry da queue
        }
    }

    /**
     * Processa webhook Shipay para recarga PIX wallet.
     *
     * Shipay envia:
     * {
     *   "order_ref": "wallet_<client_id>_<supplier_id>",
     *   "status": "approved",
     *   "total": 100.00,
     *   "order_id": "shipay_xxx",
     *   ...
     * }
     */
    protected function handleShipay(ClientWalletService $walletService): void
    {
        $status = $this->payload['status'] ?? '';
        $orderRef = $this->payload['order_ref'] ?? '';
        $value = (float) ($this->payload['total'] ?? 0);
        $shipayOrderId = $this->payload['order_id'] ?? 'unknown';

        if ($status !== 'approved') {
            Log::info("[ProcessPaymentWebhook] Status Shipay ignorado: {$status}");
            return;
        }

        if (!str_starts_with($orderRef, 'wallet_')) {
            Log::info("[ProcessPaymentWebhook] Pagamento Shipay nao e recarga wallet, ignorando");
            return;
        }

        $parts = explode('_', $orderRef);
        if (count($parts) < 3) {
            Log::error("[ProcessPaymentWebhook] order_ref invalido: {$orderRef}");
            return;
        }

        $clientId = (int) $parts[1];
        $supplierId = (int) $parts[2];

        $client = Client::find($clientId);
        $supplier = Supplier::find($supplierId);

        if (!$client || !$supplier) {
            Log::error("[ProcessPaymentWebhook] Client #{$clientId} ou Supplier #{$supplierId} nao encontrado");
            return;
        }

        if ($value <= 0) {
            return;
        }

        // Evita duplicidade
        $exists = \App\Models\ClientSupplierTransaction::where('client_id', $clientId)
            ->where('supplier_id', $supplierId)
            ->where('description', 'like', "%{$shipayOrderId}%")
            ->where('type', 'credit')
            ->exists();

        if ($exists) {
            Log::info("[ProcessPaymentWebhook] Pagamento Shipay {$shipayOrderId} ja processado");
            return;
        }

        try {
            $transaction = $walletService->credit(
                $clientId,
                $supplierId,
                $value,
                "Recarga PIX via Shipay #{$shipayOrderId}",
                null,
                $shipayOrderId
            );

            Log::info("[ProcessPaymentWebhook] Wallet creditada via Shipay", [
                'client_id' => $clientId,
                'supplier_id' => $supplierId,
                'amount' => $value,
                'transaction_id' => $transaction->id,
            ]);
        } catch (\Exception $e) {
            Log::error("[ProcessPaymentWebhook] Erro Shipay ao creditar wallet", [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
