<?php

namespace App\Filament\App\Resources\SubscriptionResource\Pages;

use App\Filament\App\Resources\SubscriptionResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateSubscription extends CreateRecord
{
    protected static string $resource = SubscriptionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['client_id'] = auth()->user()->client->id;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
