<?php

namespace App\Filament\Resources\TenantWebhookEndpointResource\Pages;

use App\Filament\Resources\TenantWebhookEndpointResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTenantWebhookEndpoint extends EditRecord
{
    protected static string $resource = TenantWebhookEndpointResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
