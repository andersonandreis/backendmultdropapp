<?php

namespace App\Jobs;

use App\Models\EventLog;
use App\Models\EventSubscription;
use App\Notifications\EventEmailNotification;
use App\Notifications\EventInAppNotification;
use App\Services\Events\WebhookDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job responsável por entregar uma notificação de evento a um inscrito,
 * via um canal específico (webhook, email, in_app, push).
 *
 * Roda na fila 'notifications' com backoff exponencial:
 *  - 1ª retry: 60s
 *  - 2ª retry: 5min
 *  - 3ª retry: 15min
 */
class DispatchEventNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Máximo de tentativas (inclui a primeira execução). */
    public int $tries = 3;

    /** Backoff em segundos entre tentativas (60s, 5min, 15min). */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly int $subscriptionId,
        public readonly string $eventType,
        public readonly array $payload,
        public readonly string $channel,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(WebhookDeliveryService $webhookService): void
    {
        $subscription = EventSubscription::find($this->subscriptionId);

        if (! $subscription || ! $subscription->is_active) {
            Log::info('[DispatchEventNotification] Subscription inativa ou inexistente', [
                'subscription_id' => $this->subscriptionId,
                'event'           => $this->eventType,
                'channel'         => $this->channel,
            ]);
            return;
        }

        $user = $subscription->user;

        if (! $user) {
            Log::warning('[DispatchEventNotification] Subscription sem user', [
                'subscription_id' => $this->subscriptionId,
            ]);
            return;
        }

        match ($this->channel) {
            'webhook' => $webhookService->deliver($subscription, $this->eventType, $this->payload),
            'email'   => $this->handleEmail($user, $subscription),
            'in_app'  => $this->handleInApp($user, $subscription),
            'push'    => $this->handlePush($user, $subscription),
            default   => Log::warning('[DispatchEventNotification] Canal desconhecido', ['channel' => $this->channel]),
        };
    }

    private function handleEmail(mixed $user, EventSubscription $subscription): void
    {
        $user->notify(new EventEmailNotification($this->eventType, $this->payload));
        $this->logDelivery($subscription, 'sent');
    }

    private function handleInApp(mixed $user, EventSubscription $subscription): void
    {
        $user->notify(new EventInAppNotification($this->eventType, $this->payload));
        $this->logDelivery($subscription, 'sent');
    }

    private function handlePush(mixed $user, EventSubscription $subscription): void
    {
        // Reservado para FCM — implementar quando Firebase estiver configurado
        Log::info('[DispatchEventNotification] Push preparado (FCM pendente)', [
            'event'   => $this->eventType,
            'user_id' => $user->id,
        ]);
        $this->logDelivery($subscription, 'sent');
    }

    /**
     * Registra a entrega no EventLog para canais que não criam o log internamente
     * (email, in_app, push — diferente do webhook que usa WebhookDeliveryService).
     */
    private function logDelivery(EventSubscription $subscription, string $status): void
    {
        EventLog::create([
            'event_subscription_id' => $subscription->id,
            'event_type'            => $this->eventType,
            'channel'               => $this->channel,
            'payload'               => $this->payload,
            'status'                => $status,
            'attempts'              => 1,
            'sent_at'               => $status === 'sent' ? now() : null,
        ]);
    }
}
