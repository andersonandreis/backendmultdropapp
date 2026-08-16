<?php

namespace App\Filament\Resources\TenantApiCredentialResource\Pages;

use App\Filament\Resources\TenantApiCredentialResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTenantApiCredentials extends ListRecords
{
    protected static string $resource = TenantApiCredentialResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
