<?php

namespace App\Filament\Resources\EventLogResource\Pages;

use App\Filament\Resources\EventLogResource;
use Filament\Resources\Pages\ListRecords;

class ListEventLogs extends ListRecords
{
    protected static string $resource = EventLogResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
