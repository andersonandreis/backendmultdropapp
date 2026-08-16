<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

use App\Models\OrderLabelQueue;
use App\Models\Order;
use Illuminate\Support\Facades\Log;

/**
 * Job de FALLBACK para etiquetas — roda a cada 30 minutos.
 *
 * O fluxo principal é webhook-driven (marketplace notifica quando etiqueta está pronta).
 * Este job pega pedidos que ficaram "stuck" — pagos há mais de 2 horas sem etiqueta
 * e sem webhook recebido. Max 5 tentativas com backoff exponencial.
 */
class CheckLabelAvailabilityJob implements ShouldQueue
{
    use Queueable;

    /**
     * Backoff exponencial: 30min, 1h, 2h, 4h, 4h (max 5 tentativas = ~12h total)
     */
    private const BACKOFF_MINUTES = [30, 60, 120, 240, 240];
    private const MAX_ATTEMPTS = 20; // MUL-205: mais tolerante pra ML handling prolongado

    public function handle(): void
    {
        // Busca pedidos stuck: pagos há >2h, sem etiqueta, com label queue pendente
        $queues = OrderLabelQueue::with('order')
            ->whereIn('status', ['pending', 'checking'])
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where(function ($query) {
                $query->whereNull('next_check_at')
                      ->orWhere('next_check_at', '<=', now());
            })
            ->get();

        $dispatched = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($queues as $queue) {
            $order = $queue->order;

            if (!$order) {
                $queue->update(['status' => 'failed', 'error_log' => 'Order not found']);
                $failed++;
                continue;
            }

            // MUL-242: etiqueta baixa ANTES do pagamento (NOV-207) — só cancelado bloqueia
            if ($order->canonical_status === 'cancelled' || strtolower((string) $order->status) === 'cancelled') {
                $skipped++;
                continue;
            }

            // Já tem etiqueta (webhook chegou enquanto esperava)
            if ($order->label_url && !str_contains($order->label_url, 'mock')) {
                $queue->update(['status' => 'available']);
                $skipped++;
                continue;
            }

            // Incrementa tentativa
            $attempt = $queue->attempts + 1;
            $queue->update(['status' => 'checking', 'attempts' => $attempt]);

            // Despacha FetchShippingLabelJob como fallback
            FetchShippingLabelJob::dispatch($order->id, 'fallback')
                ->delay(now()->addSeconds(30)); // delay 30s pra evitar race condition

            // Calcula próximo check com backoff exponencial
            $backoffIndex = min($attempt, count(self::BACKOFF_MINUTES)) - 1;
            $nextDelay = self::BACKOFF_MINUTES[$backoffIndex];

            $queue->update([
                'status'        => 'pending',
                'next_check_at' => now()->addMinutes($nextDelay),
            ]);

            $dispatched++;

            // Se atingiu max tentativas, marca como stuck
            if ($attempt >= self::MAX_ATTEMPTS) {
                // MUL-ETIQUETA-STUCK (16/08): aqui gravava 'stuck', que NAO existe no
                // enum da coluna (pending|checking|available|failed). O banco recusava
                // ("Data truncated for column 'status'", 56x so hoje), a excecao abortava
                // o item ja com attempts=20, e a consulta deste job filtra attempts<20 —
                // entao a linha nunca mais era lida e o pedido ficava "aguardando
                // etiqueta" PARA SEMPRE. Eram 380 pedidos assim quando eu medi.
                // 'failed' ja existe no enum e ja e lido pelo painel e pelo
                // labels:requeue-stuck — nao precisa mexer no enum de uma tabela viva.
                $queue->update([
                    'status'    => 'failed',
                    'error_log' => "Max {$attempt} tentativas atingido. Verificar manualmente.",
                ]);
                $failed++;

                Log::warning('[LabelFallback] Pedido stuck após max tentativas', [
                    'order_id' => $order->id,
                    'attempts' => $attempt,
                ]);
            }
        }

        if ($dispatched > 0 || $failed > 0) {
            Log::info('[LabelFallback] Ciclo concluído', [
                'dispatched' => $dispatched,
                'skipped'    => $skipped,
                'failed'     => $failed,
            ]);
        }
    }
}
