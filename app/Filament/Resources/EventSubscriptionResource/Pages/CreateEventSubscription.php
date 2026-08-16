<?php

namespace App\Filament\Resources\EventSubscriptionResource\Pages;

use App\Filament\Resources\EventSubscriptionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEventSubscription extends CreateRecord
{
    protected static string $resource = EventSubscriptionResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (!isset($data['webhook_secret'])) {
            $data['webhook_secret'] = \Illuminate\Support\Str::random(64);
        }
        return $data;
    }
}
