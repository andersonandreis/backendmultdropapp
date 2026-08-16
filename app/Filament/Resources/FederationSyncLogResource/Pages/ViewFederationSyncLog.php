<?php

namespace App\Filament\Resources\FederationSyncLogResource\Pages;

use App\Filament\Resources\FederationSyncLogResource;
use Filament\Resources\Pages\ViewRecord;
use Filament\Actions;

class ViewFederationSyncLog extends ViewRecord
{
    protected static string $resource = FederationSyncLogResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
