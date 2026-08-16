<?php

namespace App\Filament\Resources\WebhookConfigResource\Pages;

use App\Filament\Resources\WebhookConfigResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditWebhookConfig extends EditRecord
{
    protected static string $resource = WebhookConfigResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
