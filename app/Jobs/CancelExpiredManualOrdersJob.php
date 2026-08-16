<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PixTransaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Cancela pedidos manuais com PIX expirado a cada 5 minutos.
 *
 * Critério: source='manual', status='pending_payment',
 *           PixTransaction associada com expires_at < now()
 *
 * Registrado em routes/console.php:
 *   Schedule::job(new CancelExpiredManualOrdersJob)->everyFiveMinutes()->withoutOverlapping();
 */
class CancelExpiredManualOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        // Busca pedidos manuais pendentes cujo PIX já expirou
        $expiredOrders = Order::query()
            ->where('source', 'manual')
            ->where('status', 'pending_payment')
            ->whereHas('payments', function ($q) {
                $q->where('method', 'pix')
                  ->whereHas('pixTransaction', function ($q2) {
                      $q2->where('status', 'pending')
                         ->where('expires_at', '<', now());
                  });
            })
            ->with(['payments.pixTransaction'])
            ->get();

        if ($expiredOrders->isEmpty()) {
            return;
        }

        $count = 0;

        foreach ($expiredOrders as $order) {
            DB::transaction(function () use ($order, &$count) {
                // Marcar PixTransactions relacionadas como expired
                foreach ($order->payments as $payment) {
                    if ($payment->method === 'pix' && $payment->pixTransaction) {
                        $pix = $payment->pixTransaction;
                        if ($pix->status === 'pending' && $pix->expires_at && $pix->expires_at->isPast()) {
                            $pix->update(['status' => 'expired']);
                            $payment->update(['status' => 'failed']);
                        }
                    }
                }

                $order->update([
                    'status'       => 'cancelled',
                    'cancelled_at' => now(),
                    'cancel_reason' => 'PIX expirado — pedido manual cancelado automaticamente.',
                ]);

                $count++;
            });
        }

        Log::info('[CancelExpiredManualOrdersJob] Pedidos manuais cancelados por PIX expirado', [
            'count'     => $count,
            'order_ids' => $expiredOrders->pluck('id')->toArray(),
            'ran_at'    => now()->toIso8601String(),
        ]);
    }
}
