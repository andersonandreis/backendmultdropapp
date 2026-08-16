<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ListingQueueStatsWidget extends BaseWidget
{
    protected static ?int $sort = 0;
    protected static bool $isLazy = false;
    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->role === 'super_admin';
    }

    protected function getStats(): array
    {
        $pending    = 0;
        $processing = 0;
        $doneToday  = 0;
        $failToday  = 0;

        try {
            if (Schema::hasTable('product_listing_jobs')) {
                $pending = DB::table('product_listing_jobs')
                    ->where('status', 'pending')
                    ->count();

                $processing = DB::table('product_listing_jobs')
                    ->where('status', 'processing')
                    ->count();

                $doneToday = DB::table('product_listing_jobs')
                    ->where('status', 'done')
                    ->whereDate('updated_at', today())
                    ->count();

                $failToday = DB::table('product_listing_jobs')
                    ->where('status', 'failed')
                    ->whereDate('updated_at', today())
                    ->count();
            }
        } catch (\Throwable $e) {
            // Tabela ainda nao existe — retornar zeros
        }

        return [
            Stat::make('Na Fila', number_format($pending))
                ->description('Aguardando processamento')
                ->color('warning')
                ->icon('heroicon-o-queue-list'),

            Stat::make('Processando', number_format($processing))
                ->description('Em execucao agora')
                ->color($processing > 0 ? 'info' : 'gray')
                ->icon('heroicon-o-arrow-path'),

            Stat::make('Concluidos Hoje', number_format($doneToday))
                ->description(today()->format('d/m/Y'))
                ->color('success')
                ->icon('heroicon-o-check-circle'),

            Stat::make('Falhas Hoje', number_format($failToday))
                ->description('Requerem atencao')
                ->color($failToday > 0 ? 'danger' : 'success')
                ->icon('heroicon-o-x-circle'),
        ];
    }
}
