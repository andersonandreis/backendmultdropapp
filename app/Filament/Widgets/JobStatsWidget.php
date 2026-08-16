<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class JobStatsWidget extends BaseWidget
{
    protected static ?int $sort = 3;
    protected static bool $isLazy = false;
    protected ?string $heading = 'Estatisticas de Jobs (24h)';

    public static function canView(): bool
    {
        return auth()->user()?->role === 'super_admin';
    }

    protected function getStats(): array
    {
        try {
            // Tentamos usar job_batches ou app_logs para stats de sucesso
            // Fallback: contar jobs atuais vs failed
            $failedToday = 0;
            $processedToday = 0;

            try {
                $failedToday = DB::table('failed_jobs')
                    ->where('failed_at', '>=', now()->subDay())
                    ->count();
            } catch (\Throwable) {}

            try {
                // Se existir tabela job_batches do Laravel
                if (DB::getSchemaBuilder()->hasTable('job_batches')) {
                    $processedToday = (int) DB::table('job_batches')
                        ->where('created_at', '>=', now()->subDay())
                        ->sum('processed_jobs');
                }
            } catch (\Throwable) {}

            try {
                // Tentar app_logs com event contendo 'job'
                if (DB::getSchemaBuilder()->hasTable('app_logs')) {
                    $processedToday = DB::table('app_logs')
                        ->where('level', 'info')
                        ->where('created_at', '>=', now()->subDay())
                        ->count();
                }
            } catch (\Throwable) {}

            $total = $processedToday + $failedToday;
            $successRate = $total > 0 ? round(($processedToday / $total) * 100, 1) : 100;

            $pendingNow = 0;
            try {
                $pendingNow = DB::table('jobs')->count();
            } catch (\Throwable) {}

            return [
                Stat::make('Fila Atual', $pendingNow)
                    ->description('Jobs aguardando processamento')
                    ->color($pendingNow > 1000 ? 'warning' : 'success')
                    ->icon('heroicon-o-queue-list'),

                Stat::make('Falhas 24h', $failedToday)
                    ->description('Jobs com erro')
                    ->color($failedToday > 0 ? 'danger' : 'success')
                    ->icon('heroicon-o-exclamation-circle'),

                Stat::make('Taxa de Sucesso', $successRate . '%')
                    ->description('Processados com sucesso')
                    ->color($successRate >= 95 ? 'success' : ($successRate >= 80 ? 'warning' : 'danger'))
                    ->icon('heroicon-o-check-badge'),
            ];
        } catch (\Throwable) {
            return [
                Stat::make('Fila Atual', '?')->color('gray'),
                Stat::make('Falhas 24h', '?')->color('gray'),
                Stat::make('Taxa de Sucesso', '?')->color('gray'),
            ];
        }
    }
}
