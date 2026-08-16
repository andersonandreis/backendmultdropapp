<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * MUL-142-E #15-back - Notifica seller que operador de picking leu a nota de expedicao.
 */
class ExpeditionNoteReadNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Order $order,
        public readonly string $readBy,
    ) {}

    public function via(mixed $notifiable): array
    {
        return ["database"];
    }

    public function toDatabase(mixed $notifiable): array
    {
        return [
            "type"         => "expedition_note_read",
            "order_id"     => $this->order->id,
            "order_number" => $this->order->order_number,
            "message"      => "Nota de expedicao do pedido lida pelo operador.",
            "read_by"      => $this->readBy,
        ];
    }
}

