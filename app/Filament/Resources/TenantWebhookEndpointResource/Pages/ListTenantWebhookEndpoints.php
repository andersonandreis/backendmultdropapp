<?php

namespace App\Filament\Resources\TenantWebhookEndpointResource\Pages;

use App\Filament\Resources\TenantWebhookEndpointResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTenantWebhookEndpoints extends ListRecords
{
    protected static string $resource = TenantWebhookEndpointResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
