<?php

namespace App\Notifications;

use App\Services\Events\EventDispatcherService;
use Illuminate\Notifications\Notification;

/**
 * Notificação de evento entregue via banco de dados (in-app).
 *
 * Armazena os dados na tabela `notifications` (canal 'database' do Laravel).
 * O frontend pode consumir via API ou polling para exibir alertas no painel.
 */
class EventInAppNotification extends Notification
{
    public function __construct(
        public readonly string $eventType,
        public readonly array $payload,
    ) {}

    public function via(mixed $notifiable): array
    {
        return ['database'];
    }

    /**
     * Dados serializados na coluna `data` da tabela notifications.
     *
     * @return array<string, mixed>
     */
    public function toArray(mixed $notifiable): array
    {
        return [
            'event_type' => $this->eventType,
            'label'      => EventDispatcherService::labelFor($this->eventType),
            'payload'    => $this->payload,
            'timestamp'  => now()->toIso8601String(),
        ];
    }
}
