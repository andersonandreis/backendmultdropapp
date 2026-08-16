<?php

namespace App\Filament\App\Resources\Drop\DropTrafficResource\Pages;

use App\Filament\App\Resources\Drop\DropTrafficResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDropTrackingPixel extends CreateRecord
{
    protected static string $resource = DropTrafficResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();
        if ($user && $user->role !== 'super_admin' && $user->client) {
            $data['client_id'] = $user->client->id;
        }
        return $data;
    }
}
