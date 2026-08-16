<?php

namespace App\Notifications;

use App\Services\Events\EventDispatcherService;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notificação de evento entregue via e-mail.
 *
 * Usa EventDispatcherService::labelFor() para obter o nome legível
 * do tipo de evento sem duplicar o mapa de eventos.
 */
class EventEmailNotification extends Notification
{
    public function __construct(
        public readonly string $eventType,
        public readonly array $payload,
    ) {}

    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $label = EventDispatcherService::labelFor($this->eventType);

        // Formata o payload de forma legível para o corpo do e-mail
        $payloadFormatted = json_encode(
            $this->payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return (new MailMessage)
            ->subject("HubAI — {$label}")
            ->greeting("Evento: {$label}")
            ->line("Tipo: `{$this->eventType}`")
            ->line("Data: " . now()->format('d/m/Y H:i'))
            ->line("Detalhes:")
            ->line("```\n{$payloadFormatted}\n```")
            ->action('Acessar Painel', url('/admin'));
    }
}
