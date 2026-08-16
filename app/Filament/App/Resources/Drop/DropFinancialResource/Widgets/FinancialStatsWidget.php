<?php

namespace App\Filament\App\Resources\Drop\DropFinancialResource\Widgets;

use App\Models\Drop\DropProfitReport;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FinancialStatsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $user     = auth()->user();
        $clientId = ($user && $user->role !== 'super_admin') ? $user->client?->id : null;

        $since = now()->subDays(30);

        $query = DropProfitReport::query()
            ->where('period_start', '>=', $since);

        if ($clientId) {
            $query->where('client_id', $clientId);
        }

        $reports = $query->get();

        $totalReceita       = $reports->sum('total_revenue');
        $lucroLiquido       = $reports->sum('net_profit');
        $pedidosLucro       = $reports->sum('profitable_orders');
        $pedidosPrejuizo    = $reports->sum('loss_orders');

        return [
            Stat::make('Receita Total (30 dias)', '$ ' . number_format($totalReceita, 2))
                ->description('Soma dos relatorios do periodo')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('primary'),
            Stat::make('Lucro Liquido (30 dias)', '$ ' . number_format($lucroLiquido, 2))
                ->description($lucroLiquido >= 0 ? 'Positivo' : 'Negativo')
                ->descriptionIcon($lucroLiquido >= 0 ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-circle')
                ->color($lucroLiquido >= 0 ? 'success' : 'danger'),
            Stat::make('Pedidos Lucro vs Prejuizo', $pedidosLucro . ' / ' . $pedidosPrejuizo)
                ->description('Com lucro / Com prejuizo')
                ->descriptionIcon('heroicon-m-scale')
                ->color('warning'),
        ];
    }
}
