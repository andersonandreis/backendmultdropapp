<?php

namespace App\Filament\App\Widgets;

use App\Models\Order;
use App\Models\OrderItem;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class OrderStatsWidget extends BaseWidget
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
        $clientId = $client?->id;

        $since30 = Carbon::now()->subDays(30);

        $base = Order::where('client_id', $clientId);

        $total30 = (clone $base)->where('created_at', '>=', $since30)->count();
        $aguardando = (clone $base)->where('status', 'pending')->count();
        $pagos = (clone $base)->whereIn('status', ['paid', 'separated', 'shipped', 'completed'])->where('created_at', '>=', $since30)->count();
        $enviados = (clone $base)->whereIn('status', ['shipped', 'completed'])->where('created_at', '>=', $since30)->count();

        // Margem media: (subtotal - supplier_total) / subtotal * 100
        $paidOrders = (clone $base)
            ->whereIn('status', ['paid', 'separated', 'shipped', 'completed'])
            ->where('created_at', '>=', $since30)
            ->whereNotNull('subtotal')
            ->where('subtotal', '>', 0)
            ->get(['subtotal', 'supplier_total']);

        $totalSubtotal = $paidOrders->sum('subtotal');
        $totalCusto = $paidOrders->sum('supplier_total');
        $margem = $totalSubtotal > 0
            ? round((($totalSubtotal - $totalCusto) / $totalSubtotal) * 100, 1)
            : 0;

        $margemColor = $margem >= 20 ? 'success' : ($margem >= 10 ? 'warning' : 'danger');

        return [
            Stat::make('Pedidos (30 dias)', $total30)
                ->description('Novos pedidos no periodo')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->icon('heroicon-o-shopping-bag')
                ->color('primary'),

            Stat::make('Aguardando Pagamento', $aguardando)
                ->description('Pedidos pendentes de confirmacao')
                ->descriptionIcon('heroicon-m-clock')
                ->icon('heroicon-o-clock')
                ->color($aguardando > 0 ? 'warning' : 'success'),

            Stat::make('Confirmados (30 dias)', $pagos)
                ->description('Pedidos confirmados e processando')
                ->descriptionIcon('heroicon-m-check-circle')
                ->icon('heroicon-o-check-circle')
                ->color('success'),

            Stat::make('Enviados (30 dias)', $enviados)
                ->description('Pedidos despachados ao cliente')
                ->descriptionIcon('heroicon-m-truck')
                ->icon('heroicon-o-truck')
                ->color('info'),

            Stat::make('Margem Media', $margem . '%')
                ->description('Lucro / faturamento dos ultimos 30 dias')
                ->descriptionIcon($margem >= 20 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->icon('heroicon-o-chart-bar')
                ->color($margemColor),
        ];
    }
}
