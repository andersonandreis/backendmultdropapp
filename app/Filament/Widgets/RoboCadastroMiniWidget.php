<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class RoboCadastroMiniWidget extends BaseWidget
{
    protected static ?int $sort = 4;
    protected static bool $isLazy = false;
    protected ?string $heading = 'Robo de Cadastro';

    public static function canView(): bool
    {
        return auth()->user()?->role === 'super_admin';
    }

    protected function getStats(): array
    {
        $pending     = 0;
        $processing  = 0;
        $doneToday   = 0;
        $failed      = 0;
        $tableExists = false;

        // Tentar product_listing_jobs (NOV-072)
        try {
            if (DB::getSchemaBuilder()->hasTable('product_listing_jobs')) {
                $tableExists = true;
                $pending    = DB::table('product_listing_jobs')->where('status', 'pending')->count();
                $processing = DB::table('product_listing_jobs')->where('status', 'processing')->count();
                $doneToday  = DB::table('product_listing_jobs')
                    ->where('status', 'completed')
                    ->where('updated_at', '>=', now()->startOfDay())
                    ->count();
                $failed     = DB::table('product_listing_jobs')->where('status', 'failed')->count();
            }
        } catch (\Throwable) {}

        // Fallback: auto_listing_queue_items
        if (! $tableExists) {
            try {
                if (DB::getSchemaBuilder()->hasTable('auto_listing_queue_items')) {
                    $tableExists = true;
                    $pending    = DB::table('auto_listing_queue_items')->where('status', 'pending')->count();
                    $processing = DB::table('auto_listing_queue_items')->where('status', 'processing')->count();
                    $doneToday  = DB::table('auto_listing_queue_items')
                        ->where('status', 'done')
                        ->where('updated_at', '>=', now()->startOfDay())
                        ->count();
                    $failed     = DB::table('auto_listing_queue_items')->where('status', 'failed')->count();
                }
            } catch (\Throwable) {}
        }

        if (! $tableExists) {
            return [
                Stat::make('Robo', 'Aguardando NOV-072')
                    ->description('Tabela nao criada ainda')
                    ->color('gray')
                    ->icon('heroicon-o-clock'),
            ];
        }

        return [
            Stat::make('Pendentes', number_format($pending))
                ->description('Aguardando cadastro')
                ->color($pending > 500 ? 'warning' : 'success')
                ->icon('heroicon-o-inbox-stack'),

            Stat::make('Processando', number_format($processing))
                ->description('Em execucao agora')
                ->color($processing > 0 ? 'info' : 'gray')
                ->icon('heroicon-o-cog-6-tooth'),

            Stat::make('Concluidos Hoje', number_format($doneToday))
                ->description($failed > 0 ? "{$failed} falhas" : 'Sem falhas')
                ->color($failed > 0 ? 'warning' : 'success')
                ->icon('heroicon-o-check-circle'),
        ];
    }
}