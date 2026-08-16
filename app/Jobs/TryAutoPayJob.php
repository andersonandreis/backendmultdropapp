<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Financial\AutoPayService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * MUL-363 (eventos únicos — decisão do Ruan 11/08): o autopay dispara num ÚNICO
 * ponto interno — o evento "ficou pagável" (etiqueta disponível / enviado) no
 * OrderObserver. Este job é o executor assíncrono; a idempotência dura mora no
 * núcleo (idempotency_key auto_pay:order:<id> + checagem wallet_paid_at).
 */
class TryAutoPayJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 60;

    public function __construct(public readonly int $orderId) {}

    public function handle(): void
    {
        $order = Order::find($this->orderId);
        if (! $order || $order->wallet_paid_at !== null) {
            return;
        }
        app(AutoPayService::class)->tryAutoPay($order);
    }
}
