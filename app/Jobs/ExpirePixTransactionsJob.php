<?php

namespace App\Jobs;

use App\Models\PixTransaction;
use App\Services\Events\EventDispatcherService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Expira transacoes PIX que passaram do prazo sem confirmacao.
 *
 * Agendado para rodar a cada 5 minutos via routes/console.php.
 * Usa withoutOverlapping() para evitar execucoes concorrentes.
 *
 * Para cada PixTransaction pendente com expires_at no passado:
 *   1. Marca como expirada (status = 'expired')
 *   2. Dispara evento payment.expired para notificacao dos inscritos
 */
class ExpirePixTransactionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(EventDispatcherService $eventDispatcher): void
    {
        $expired = PixTransaction::where('status', 'pending')
            ->where('expires_at', '<', now())
            ->get();

        if ($expired->isEmpty()) {
            return;
        }

        foreach ($expired as $pix) {
            $pix->markAsExpired();

            $eventDispatcher->dispatch('payment.expired', [
                'pix_transaction_id' => $pix->id,
                'order_id'           => $pix->order_id,
                'amount'             => $pix->amount,
                'type'               => $pix->type,
            ], $pix->supplier_id);
        }

        Log::info('[ExpirePixTransactionsJob] PIX expirados processados', [
            'count' => $expired->count(),
        ]);
    }
}
