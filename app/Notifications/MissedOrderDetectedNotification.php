<?php

namespace App\Notifications;

use App\Models\MissedOrderAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notificação disparada quando o MissedOrderDetectionService detecta
 * uma venda que não chegou pelo webhook do marketplace.
 *
 * Canais:
 *  - database  → sempre (in-app, sino Filament)
 *  - mail      → apenas para planos Pro/Scale (não Start)
 *
 * Texto propositalmente não acusatório: "possível venda" em vez de
 * "venda perdida" ou "erro", pois pode ser falso positivo.
 */
class MissedOrderDetectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly MissedOrderAlert $alert,
    ) {}

    // -------------------------------------------------------------------------
    // Channels
    // -------------------------------------------------------------------------

    public function via(mixed $notifiable): array
    {
        // Start plan → somente in-app; Pro/Scale → in-app + e-mail
        $channels = ['database'];

        $client = $this->alert->client;
        if ($client && ! $client->isStartPlan()) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    // -------------------------------------------------------------------------
    // Mail
    // -------------------------------------------------------------------------

    public function toMail(mixed $notifiable): MailMessage
    {
        $marketplace = ucfirst($this->alert->marketplace ?? 'marketplace');
        $amount      = number_format($this->alert->amount_cents / 100, 2, ',', '.');
        $orderId     = $this->alert->marketplace_order_id ?? '—';
        $buyer       = $this->alert->buyer_name ?? 'Comprador não identificado';
        $url         = url('/app/missed-orders');

        return (new MailMessage)
            ->subject("Identificamos uma possível venda no {$marketplace}")
            ->greeting('Olá!')
            ->line("Identificamos uma **possível** venda que não chegou pelo sistema.")
            ->line("**Marketplace:** {$marketplace}")
            ->line("**Pedido:** {$orderId}")
            ->line("**Comprador:** {$buyer}")
            ->line("**Valor:** R\$ {$amount}")
            ->line('Acesse o painel para verificar e, se necessário, registrar o pedido manualmente.')
            ->action('Ver alerta', $url)
            ->line('Se este pedido já foi processado normalmente, você pode dispensar o alerta na plataforma.');
    }

    // -------------------------------------------------------------------------
    // Database (in-app / sino Filament)
    // -------------------------------------------------------------------------

    public function toDatabase(mixed $notifiable): array
    {
        return $this->toArray($notifiable);
    }

    public function toArray(mixed $notifiable): array
    {
        return [
            'alert_id'             => $this->alert->id,
            'marketplace'          => $this->alert->marketplace,
            'marketplace_order_id' => $this->alert->marketplace_order_id,
            'amount_cents'         => $this->alert->amount_cents,
            'detected_at'          => $this->alert->detected_at?->toIso8601String(),
        ];
    }
}
