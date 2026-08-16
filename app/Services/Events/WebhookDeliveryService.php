<?php

namespace App\Services\Events;

use App\Models\EventLog;
use App\Models\EventSubscription;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Responsável pela entrega de webhooks para URLs externas.
 *
 * Fluxo:
 * 1. Cria um EventLog com status 'pending'
 * 2. Monta o body JSON + assinatura HMAC-SHA256
 * 3. POST para webhook_url da subscription com timeout 10s
 * 4. Atualiza o log para 'sent' ou 'pending'/'failed' conforme resultado
 *
 * Retry policy: até 3 tentativas; após isso marca como 'failed'.
 * Retries periódicos são feitos pelo RetryFailedWebhooksJob (a cada 15 min).
 */
class WebhookDeliveryService
{
    /** Número máximo de tentativas antes de marcar como falha permanente. */
    private const MAX_ATTEMPTS = 3;

    /**
     * Cria o EventLog e realiza a primeira tentativa de entrega.
     */
    public function deliver(EventSubscription $subscription, string $eventType, array $payload): EventLog
    {
        $log = EventLog::create([
            'event_subscription_id' => $subscription->id,
            'event_type'            => $eventType,
            'channel'               => 'webhook',
            'payload'               => $payload,
            'status'                => 'pending',
            'attempts'              => 0,
        ]);

        return $this->attemptDelivery($log, $subscription);
    }

    /**
     * Tenta (re)entregar um EventLog existente.
     * Usado tanto na primeira entrega quanto nos retries.
     */
    public function attemptDelivery(EventLog $log, ?EventSubscription $subscription = null): EventLog
    {
        $subscription = $subscription ?? $log->eventSubscription;

        if (! $subscription?->webhook_url) {
            $log->update(['status' => 'failed', 'error' => 'No webhook URL configured']);
            return $log;
        }

        $body = json_encode([
            'event'     => $log->event_type,
            'timestamp' => now()->toIso8601String(),
            'data'      => $log->payload,
        ], JSON_UNESCAPED_UNICODE);

        // Assinatura HMAC-SHA256 para validação pelo receptor
        $secret    = $subscription->webhook_secret ?? '';
        $signature = hash_hmac('sha256', $body, $secret);

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Content-Type'       => 'application/json',
                    'X-HubAI-Signature'  => $signature,
                    'X-HubAI-Event'      => $log->event_type,
                    'X-HubAI-Delivery'   => (string) $log->id,
                ])
                ->withBody($body, 'application/json')
                ->post($subscription->webhook_url);

            $log->increment('attempts');
            $log->refresh();

            if ($response->successful()) {
                $log->update(['status' => 'sent', 'sent_at' => now(), 'error' => null]);
            } else {
                $errorMsg = "HTTP {$response->status()}: " . Str::limit($response->body(), 500);

                $log->update([
                    'status' => $log->attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending',
                    'error'  => $errorMsg,
                ]);

                Log::warning('[WebhookDelivery] Resposta não-2xx', [
                    'log_id'  => $log->id,
                    'event'   => $log->event_type,
                    'url'     => $subscription->webhook_url,
                    'status'  => $response->status(),
                    'attempt' => $log->attempts,
                ]);
            }
        } catch (\Exception $e) {
            $log->increment('attempts');
            $log->refresh();

            $log->update([
                'status' => $log->attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending',
                'error'  => $e->getMessage(),
            ]);

            Log::error('[WebhookDelivery] Exceção na entrega', [
                'log_id'    => $log->id,
                'event'     => $log->event_type,
                'url'       => $subscription->webhook_url,
                'exception' => $e->getMessage(),
                'attempt'   => $log->attempts,
            ]);
        }

        return $log->fresh();
    }

    /**
     * Envia um webhook de teste para a subscription (payload com evento "test.ping").
     */
    public function sendTestWebhook(EventSubscription $subscription): EventLog
    {
        return $this->deliver($subscription, 'test.ping', [
            'message'   => 'Este é um webhook de teste do HubAI.',
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
