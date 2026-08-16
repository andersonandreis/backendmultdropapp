<?php

namespace App\Filament\Resources\WebhookConfigResource\Pages;

use App\Filament\Resources\WebhookConfigResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateWebhookConfig extends CreateRecord
{
    protected static string $resource = WebhookConfigResource::class;
}
