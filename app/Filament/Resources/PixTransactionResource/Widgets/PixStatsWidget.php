<?php

namespace App\Filament\Resources\PixTransactionResource\Widgets;

use App\Models\PixTransaction;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

class PixStatsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $pendingTotal = PixTransaction::pending()->sum('amount');
        $paidToday    = PixTransaction::paid()->whereDate('paid_at', today())->sum('amount');
        $expiredToday = PixTransaction::where('status', 'expired')->whereDate('updated_at', today())->count();
        $avgFee       = PixTransaction::paid()->whereDate('paid_at', today())->avg('fee_amount') ?? 0;

        return [
            Stat::make('Pendente (total)', 'R$ ' . number_format($pendingTotal, 2, ',', '.'))
                ->description('Aguardando pagamento')
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning'),

            Stat::make('Pago hoje', 'R$ ' . number_format($paidToday, 2, ',', '.'))
                ->description('Total confirmado hoje')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),

            Stat::make('Expirado hoje', (string) $expiredToday)
                ->description('Transações expiradas')
                ->descriptionIcon('heroicon-m-x-circle')
                ->color('danger'),

            Stat::make('Taxa média hoje', 'R$ ' . number_format($avgFee, 2, ',', '.'))
                ->description('Por transação paga')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('info'),
        ];
    }
}
