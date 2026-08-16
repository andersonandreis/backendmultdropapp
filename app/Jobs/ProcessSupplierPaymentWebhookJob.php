<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\PixTransaction;
use App\Models\Supplier;
use App\Services\Events\EventDispatcherService;
use App\Services\Financial\OrderPaymentService;
use App\Services\Financial\RefundService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Processa webhooks de pagamento recebidos por fornecedor (Fase 4).
 *
 * Suporta: Asaas, Shipay, Pagarme
 *
 * Fluxo:
 *  1. Detecta o tipo de evento baseado no gateway e payload
 *  2. Localiza a PixTransaction pelo external_id
 *  3. Executa a ação correspondente (confirmar, falhar, estornar, expirar)
 *  4. Dispara evento interno via EventDispatcherService
 */
class ProcessSupplierPaymentWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var int[] */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly array $payload,
        public readonly int $supplierId,
        public readonly string $gateway,
    ) {
        $this->onQueue('webhooks');
    }

    public function handle(
        OrderPaymentService $paymentService,
        EventDispatcherService $eventDispatcher,
    ): void {
        $supplier = Supplier::with('paymentSetting')->find($this->supplierId);

        if (! $supplier) {
            Log::warning('[SupplierWebhookJob] Supplier não encontrado, abortando', [
                'supplier_id' => $this->supplierId,
            ]);
            return;
        }

        $eventType  = $this->detectEventType();
        $externalId = $this->extractExternalId();

        Log::info('[SupplierWebhookJob] Processando', [
            'supplier_id' => $this->supplierId,
            'gateway'     => $this->gateway,
            'event_type'  => $eventType,
            'external_id' => $externalId,
        ]);

        match ($eventType) {
            'payment_confirmed' => $this->handlePaymentConfirmed($paymentService, $eventDispatcher, $externalId),
            'payment_failed'    => $this->handlePaymentFailed($eventDispatcher, $externalId),
            'payment_refunded'  => $this->handlePaymentRefunded($eventDispatcher, $externalId),
            'payment_cancelled',
            'payment_expired'   => $this->handlePaymentExpired($eventDispatcher, $externalId),
            default             => Log::info('[SupplierWebhookJob] Evento não tratado', [
                'type'    => $eventType,
                'gateway' => $this->gateway,
            ]),
        };
    }

    // -------------------------------------------------------------------------
    // Detecção de evento por gateway
    // -------------------------------------------------------------------------

    private function detectEventType(): string
    {
        return match ($this->gateway) {
            'asaas'   => $this->detectAsaasEvent(),
            'shipay'  => $this->detectShipayEvent(),
            'pagarme' => $this->detectPagarmeEvent(),
            default   => 'unknown',
        };
    }

    private function detectAsaasEvent(): string
    {
        $event = $this->payload['event'] ?? '';

        return match ($event) {
            'PAYMENT_CONFIRMED', 'PAYMENT_RECEIVED' => 'payment_confirmed',
            'PAYMENT_OVERDUE'                        => 'payment_expired',
            'PAYMENT_REFUNDED'                       => 'payment_refunded',
            'PAYMENT_DELETED'                        => 'payment_cancelled',
            default                                  => $event,
        };
    }

    private function detectShipayEvent(): string
    {
        $status = $this->payload['status'] ?? '';

        return match ($status) {
            'approved'                     => 'payment_confirmed',
            'cancelled', 'expired'         => 'payment_expired',
            'refund_pending', 'refunded'   => 'payment_refunded',
            default                        => $status,
        };
    }

    private function detectPagarmeEvent(): string
    {
        $type = $this->payload['type'] ?? '';

        return match ($type) {
            'charge.paid'                        => 'payment_confirmed',
            'charge.payment_failed'              => 'payment_failed',
            'charge.refunded'                    => 'payment_refunded',
            'charge.underpaid', 'charge.overpaid' => 'payment_failed',
            default                              => $type,
        };
    }

    // -------------------------------------------------------------------------
    // Extração de external_id por gateway
    // -------------------------------------------------------------------------

    private function extractExternalId(): ?string
    {
        return match ($this->gateway) {
            'asaas'   => $this->payload['payment']['id'] ?? $this->payload['id'] ?? null,
            'shipay'  => $this->payload['order_id'] ?? null,
            'pagarme' => $this->payload['data']['id'] ?? $this->payload['id'] ?? null,
            default   => null,
        };
    }

    // -------------------------------------------------------------------------
    // Handlers de evento
    // -------------------------------------------------------------------------

    private function handlePaymentConfirmed(
        OrderPaymentService $paymentService,
        EventDispatcherService $eventDispatcher,
        ?string $externalId,
    ): void {
        if (! $externalId) {
            Log::warning('[SupplierWebhookJob] payment_confirmed sem external_id', [
                'supplier_id' => $this->supplierId,
                'gateway'     => $this->gateway,
            ]);
            return;
        }

        // INF-049: aceitar status=paid alem de pending para cobrir race condition
        // onde polling (PixService) marca o pix como paid antes do webhook chegar.
        // confirmOrderPix e idempotente: so grava wallet_paid_at se NULL.
        $pixTx = PixTransaction::where('external_id', $externalId)
            ->where('supplier_id', $this->supplierId)
            ->whereIn('status', ['pending', 'paid'])
            ->first();

        if (! $pixTx) {
            Log::warning('[SupplierWebhookJob] PixTransaction não encontrada para confirmação', [
                'external_id' => $externalId,
                'supplier_id' => $this->supplierId,
            ]);
            return;
        }

        $paymentService->confirmOrderPix($pixTx);

        Log::info('[SupplierWebhookJob] PIX confirmado', [
            'pix_transaction_id' => $pixTx->id,
            'order_id'           => $pixTx->order_id,
            'status'             => $pixTx->status,
        ]);
    }

    private function handlePaymentFailed(
        EventDispatcherService $eventDispatcher,
        ?string $externalId,
    ): void {
        if ($externalId) {
            PixTransaction::where('external_id', $externalId)
                ->where('supplier_id', $this->supplierId)
                ->update(['status' => 'failed']);
        }

        $eventDispatcher->dispatch('payment.failed', [
            'external_id' => $externalId,
            'supplier_id' => $this->supplierId,
            'gateway'     => $this->gateway,
        ], $this->supplierId);
    }

    private function handlePaymentRefunded(
        EventDispatcherService $eventDispatcher,
        ?string $externalId,
    ): void {
        $pixTx = null;

        if ($externalId) {
            $pixTx = PixTransaction::where('external_id', $externalId)
                ->where('supplier_id', $this->supplierId)
                ->first();
        }

        if ($pixTx) {
            $pixTx->update(['status' => 'refunded']);

            // Se há pedido associado, acionar RefundService com a política do fornecedor
            if ($pixTx->order_id) {
                $order = Order::find($pixTx->order_id);
                if ($order) {
                    app(RefundService::class)->processRefund(
                        $order,
                        (float) $pixTx->amount,
                        'Estorno via webhook do gateway',
                    );
                }
            }
        }

        $eventDispatcher->dispatch('payment.refunded', [
            'external_id' => $externalId,
            'amount'      => $pixTx?->amount,
            'order_id'    => $pixTx?->order_id,
            'supplier_id' => $this->supplierId,
        ], $this->supplierId);
    }

    private function handlePaymentExpired(
        EventDispatcherService $eventDispatcher,
        ?string $externalId,
    ): void {
        $pixTx = null;

        if ($externalId) {
            $pixTx = PixTransaction::where('external_id', $externalId)
                ->where('supplier_id', $this->supplierId)
                ->where('status', 'pending')
                ->first();
        }

        if ($pixTx) {
            $pixTx->markAsExpired();
        }

        $eventDispatcher->dispatch('payment.expired', [
            'external_id' => $externalId,
            'order_id'    => $pixTx?->order_id,
            'supplier_id' => $this->supplierId,
        ], $this->supplierId);
    }
}
