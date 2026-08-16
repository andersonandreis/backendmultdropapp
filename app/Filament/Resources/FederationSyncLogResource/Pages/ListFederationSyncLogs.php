<?php

namespace App\Filament\Resources\FederationSyncLogResource\Pages;

use App\Filament\Resources\FederationSyncLogResource;
use App\Models\FederationSyncLog;
use Filament\Resources\Pages\ListRecords;
use Filament\Actions;

class ListFederationSyncLogs extends ListRecords
{
    protected static string $resource = FederationSyncLogResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * Stats de cabecalho: total 24h, erros pendentes, ultimas 24h por direcao.
     * Exibidos como subtitulo da pagina para visibilidade rapida.
     */
    protected function getHeaderWidgets(): array
    {
        return [
            FederationSyncLogResource\Widgets\FederationSyncStatsWidget::class,
        ];
    }
}
