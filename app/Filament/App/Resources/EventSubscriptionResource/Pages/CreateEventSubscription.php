<?php

namespace App\Filament\App\Resources\EventSubscriptionResource\Pages;

use App\Filament\App\Resources\EventSubscriptionResource;
use App\Models\Supplier;
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
        $user       = auth()->user();
        $supplierId = $user->supplier_id;

        if (!$supplierId) {
            $supplier   = Supplier::where('owner_client_id', $user->client_id ?? $user->client?->id)->first();
            $supplierId = $supplier?->id;
        }

        $data['supplier_id']    = $supplierId;
        $data['user_id']        = $user->id;
        $data['webhook_secret'] = \Illuminate\Support\Str::random(64);

        return $data;
    }
}
