<?php

namespace App\Filament\Resources\FederationSyncLogResource\Widgets;

use App\Models\FederationSyncLog;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * FederationSyncStatsWidget -- NOV-171-E
 *
 * Contadores de sincronizacao exibidos no cabecalho do FederationSyncLogResource.
 * Atualizado automaticamente junto com o poll da tabela (30s).
 */
class FederationSyncStatsWidget extends BaseWidget
{
    protected static ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $now = now();
        $since24h = $now->copy()->subHours(24);

        $total24h = FederationSyncLog::where('created_at', '>=', $since24h)->count();
        $erros24h = FederationSyncLog::where('created_at', '>=', $since24h)
            ->where('status', 'failed')
            ->count();
        $sucesso24h = FederationSyncLog::where('created_at', '>=', $since24h)
            ->where('status', 'success')
            ->count();
        $hubToWl24h = FederationSyncLog::where('created_at', '>=', $since24h)
            ->where('direction', 'hub_to_wl')
            ->count();
        $wlToHub24h = FederationSyncLog::where('created_at', '>=', $since24h)
            ->where('direction', 'wl_to_hub')
            ->count();

        return [
            Stat::make('Sincronizacoes (24h)', $total24h)
                ->description('Total de operacoes de federacao nas ultimas 24h')
                ->descriptionIcon('heroicon-m-arrows-right-left')
                ->color('primary'),

            Stat::make('Hub > WL', $hubToWl24h)
                ->description('Produtos e pedidos enviados do hub para WLs')
                ->descriptionIcon('heroicon-m-arrow-right')
                ->color('info'),

            Stat::make('WL > Hub', $wlToHub24h)
                ->description('Atualizacoes recebidas dos WLs no hub')
                ->descriptionIcon('heroicon-m-arrow-left')
                ->color('warning'),

            Stat::make('Erros (24h)', $erros24h)
                ->description($erros24h > 0 ? 'Atencao: existem erros pendentes de revisao' : 'Nenhum erro nas ultimas 24h')
                ->descriptionIcon($erros24h > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($erros24h > 0 ? 'danger' : 'success'),
        ];
    }
}
