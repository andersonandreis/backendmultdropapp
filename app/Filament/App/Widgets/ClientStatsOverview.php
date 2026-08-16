<?php

namespace App\Filament\App\Widgets;

use App\Models\Order;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ClientStatsOverview extends BaseWidget
{
    protected static ?int $sort = 0;
    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return auth()->user()?->role === 'client';
    }

    protected function getStats(): array
    {
        $client = auth()->user()?->client;

        $totalPedidos     = Order::where('client_id', $client?->id)->count();
        $pedidosPendentes = Order::where('client_id', $client?->id)->where('status', 'pending')->count();

        $sub = $client?->subscriptions()
            ->whereIn('status', ['active', 'trialing'])
            ->with('plan')
            ->latest()
            ->first();

        $planoNome = $sub?->plan?->name ?? 'Sem plano ativo';
        $planoDesc = $sub
            ? ($sub->ends_at ? 'Ativo ate ' . \Carbon\Carbon::parse($sub->ends_at)->format('d/m/Y') : 'Ativo')
            : 'Entre em contato para ativar';

        return [
            Stat::make('Total de Pedidos', $totalPedidos)
                ->icon('heroicon-o-shopping-cart')
                ->color('primary'),

            Stat::make('Pedidos Pendentes', $pedidosPendentes)
                ->icon('heroicon-o-clock')
                ->color($pedidosPendentes > 0 ? 'warning' : 'success'),

            Stat::make('Plano Atual', $planoNome)
                ->description($planoDesc)
                ->icon('heroicon-o-credit-card')
                ->color($sub ? 'success' : 'danger'),
        ];
    }
}
