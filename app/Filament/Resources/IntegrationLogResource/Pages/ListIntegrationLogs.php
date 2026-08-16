<?php

namespace App\Filament\Resources\IntegrationLogResource\Pages;

use App\Filament\Resources\IntegrationLogResource;
use App\Filament\Widgets\IntegrationLogsOverview;
use Filament\Resources\Pages\ListRecords;

class ListIntegrationLogs extends ListRecords
{
    protected static string $resource = IntegrationLogResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            IntegrationLogsOverview::class,
        ];
    }
}
