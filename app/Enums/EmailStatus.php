<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EmailStatus: string implements HasLabel, HasColor
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Opened = 'opened';
    case Clicked = 'clicked';
    case Failed = 'failed';
    case Bounced = 'bounced';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Queued    => 'Na Fila',
            self::Sent      => 'Enviado',
            self::Delivered => 'Entregue',
            self::Opened    => 'Aberto',
            self::Clicked   => 'Clicado',
            self::Failed    => 'Falhou',
            self::Bounced   => 'Rejeitado',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Queued    => 'gray',
            self::Sent      => 'info',
            self::Delivered => 'primary',
            self::Opened    => 'success',
            self::Clicked   => 'success',
            self::Failed    => 'danger',
            self::Bounced   => 'warning',
        };
    }
}
