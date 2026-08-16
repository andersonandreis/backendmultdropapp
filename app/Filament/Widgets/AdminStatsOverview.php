<?php

namespace App\Filament\Widgets;

use App\Models\Client;
use App\Models\Supplier;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AdminStatsOverview extends BaseWidget
{
    protected static ?int $sort = 0;
    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return auth()->user()?->role === 'super_admin';
    }

    protected function getStats(): array
    {
        $totalClients    = User::where('role', 'client')->count();
        $totalSuppliers  = Supplier::count(); // NOV-169: conta tabela suppliers, nao role=supplier
        $semPlano        = Client::whereDoesntHave(
            'subscriptions',
            fn ($q) => $q->whereIn('status', ['active', 'trialing'])
        )->count();

        return [
            Stat::make('Total de Lojistas', $totalClients)
                ->description('Sellers cadastrados')
                ->color('success')
                ->icon('heroicon-o-users'),

            Stat::make('Contas de Marketplace', $totalSuppliers)
                ->description('Fornecedores ativos')
                ->color('warning')
                ->icon('heroicon-o-truck'),

            Stat::make('Lojistas sem Plano', $semPlano)
                ->description('Requerem atenção')
                ->color($semPlano > 0 ? 'danger' : 'success')
                ->icon('heroicon-o-exclamation-triangle'),
        ];
    }
}
