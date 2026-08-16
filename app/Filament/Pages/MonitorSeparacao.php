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

    public function getFilaSeparacao()
    {
        return Order::query()
            ->with(['client', 'supplier', 'items'])
            ->where('status', 'paid')
            ->whereIn('order_processing_status', ['awaiting_label', 'awaiting_dispatch', 'pending', 'separating'])
            ->whereNotNull('paid_at')
            ->orderBy('paid_at', 'asc')
            ->limit(15)
            ->get();
    }

    public function getStatsAgora(): array
    {
        $base = Order::query();
        return [
            'aguardando'    => (clone $base)->where('status', 'paid')->whereIn('order_processing_status', ['awaiting_label','awaiting_dispatch','pending','separating'])->count(),
            'separados_hoje' => (clone $base)->whereDate('separated_at', today())->count(),
            'enviados_hoje'  => (clone $base)->whereDate('shipped_at', today())->count(),
            'atrasados'      => (clone $base)->where('status', 'paid')->whereNotNull('paid_at')->where('paid_at', '<=', now()->subHours(48))->whereNull('shipped_at')->whereNotIn('order_processing_status', ['cancelled','returned','delivered'])->count(),
        ];
    }
}
