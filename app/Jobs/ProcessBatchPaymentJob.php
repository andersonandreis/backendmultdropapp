<?php

namespace App\Jobs;

use App\Services\Events\EventDispatcherService;
use App\Services\Financial\OrderPaymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Processa o pagamento de multiplos pedidos de forma assincrona.
 *
 * Cada pedido e tentado independentemente — um erro em um pedido nao
 * cancela os demais. O job nao faz retry automatico (tries = 1) para
 * evitar pagamentos duplicados em caso de falha parcial.
 *
 * Uso:
 *   ProcessBatchPaymentJob::dispatch($orderIds, $supplierId, $clientId);
 */
class ProcessBatchPaymentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Sem retry automatico para evitar debitos duplicados em caso de falha parcial.
     */
    public int $tries = 1;

    public function __construct(
        public readonly array $orderIds,
        public readonly int $supplierId,
        public readonly int $clientId,
    ) {
        $this->onQueue('payments');
    }

    public function handle(OrderPaymentService $paymentService, EventDispatcherService $eventDispatcher): void
    {
        Log::info('[ProcessBatchPaymentJob] Iniciando batch', [
            'client_id'   => $this->clientId,
            'supplier_id' => $this->supplierId,
            'order_count' => count($this->orderIds),
        ]);

        $results = $paymentService->payOrdersBatch($this->orderIds, $this->supplierId, $this->clientId);

        Log::info('[ProcessBatchPaymentJob] Batch concluido', [
            'client_id'    => $this->clientId,
            'supplier_id'  => $this->supplierId,
            'paid'         => count($results['paid']),
            'pix_required' => count($results['pix_required']),
            'errors'       => count($results['errors']),
        ]);

        $eventDispatcher->dispatch('payment.batch_completed', [
            'client_id'   => $this->clientId,
            'paid_count'  => count($results['paid']),
            'pix_count'   => count($results['pix_required']),
            'error_count' => count($results['errors']),
        ], $this->supplierId);
    }
}
