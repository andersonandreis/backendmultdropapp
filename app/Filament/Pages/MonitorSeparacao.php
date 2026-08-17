<?php

namespace App\Filament\Pages;

use App\Models\Order;
use Filament\Pages\Page;

class MonitorSeparacao extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-tv';
    protected static ?string $navigationGroup = 'Pedidos & Logística';
    protected static ?string $navigationLabel = 'Monitor de Separação';
    protected static ?string $title = 'Monitor de Separação - Modo Painel';
    protected static ?string $slug = 'monitor-separacao';
    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.monitor-separacao';

    public ?int $autoRefreshSeconds = 30; // refresh polling

    public static function canView(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'supplier', 'admin']);
    }

    // MUL-378: filtrava `status='paid'` — 12.450 linhas, das quais 12.445 sem etiqueta
    // e 87 ja enviadas. Agora usa Order::scopeReadyToShip (pago + etiqueta + nao enviado).
    public function getFilaSeparacao()
    {
        return Order::query()
            ->readyToShip()
            ->with(['client', 'supplier', 'items'])
            ->orderBy('paid_at', 'asc')
            ->limit(15)
            ->get();
    }

    public function getStatsAgora(): array
    {
        return [
            'aguardando'     => Order::query()->readyToShip()->count(),
            'separados_hoje' => Order::query()->whereDate('separated_at', today())->count(),
            'enviados_hoje'  => Order::query()->whereDate('shipped_at', today())->count(),
            // atrasado = trabalho real parado ha mais de 48h desde o pagamento
            'atrasados'      => Order::query()->readyToShip()
                ->where('paid_at', '<=', now()->subHours(48))->count(),
        ];
    }
}
