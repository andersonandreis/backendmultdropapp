<?php

namespace App\Jobs;

use App\Models\EventLog;
use App\Services\Events\WebhookDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job agendado a cada 15 minutos para retentar webhooks com status 'pending'.
 *
 * Critérios de elegibilidade:
 * - Canal = webhook
 * - Status = pending
 * - Menos de 3 tentativas registradas
 * - Criado nas últimas 24h (evita tentar logs muito antigos)
 * - Subscription ainda ativa
 *
 * Processa em chunks de 50 para evitar pressão de memória.
 */
class RetryFailedWebhooksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(WebhookDeliveryService $webhookService): void
    {
        $count = 0;

        EventLog::where('channel', 'webhook')
            ->where('status', 'pending')
            ->where('attempts', '<', 3)
            ->where('created_at', '>=', now()->subDay())
            ->with('eventSubscription')
            ->chunk(50, function ($logs) use ($webhookService, &$count) {
                foreach ($logs as $log) {
                    $subscription = $log->eventSubscription;

                    if (! $subscription || ! $subscription->is_active) {
                        continue;
                    }

                    $webhookService->attemptDelivery($log, $subscription);
                    $count++;
                }
            });

        Log::info("[RetryFailedWebhooksJob] Retentativas processadas: {$count}");
    }
}
