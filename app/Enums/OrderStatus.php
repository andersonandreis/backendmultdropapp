<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum OrderStatus: string implements HasLabel
{
    case PENDING_PAYMENT = 'pending_payment';
    case PAID = 'paid';
    case PREPARING = 'preparing';
    case SEPARATED = 'separated';
    case SHIPPED = 'shipped';
    case DELIVERED = 'delivered';
    case CANCELLED = 'cancelled';
    case RETURNED = 'returned';
    // INF-036: pedido que a API do marketplace nao reconhece (error_not_found).
    // Nao e cancelamento de negocio — e casca de import invalida. Oculto no frontend.
    case NOT_FOUND = 'not_found';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::PENDING_PAYMENT => 'Aguardando Pagamento',
            self::PAID => 'Confirmado',
            self::PREPARING => 'Preparando',
            self::SEPARATED => 'Em Separação',
            self::SHIPPED => 'Enviado',
            self::DELIVERED => 'Entregue',
            self::CANCELLED => 'Cancelado',
            self::RETURNED => 'Devolvido',
            self::NOT_FOUND => 'Inexistente no Marketplace',
        };
    }
}
