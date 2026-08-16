<?php

namespace App\Filament\App\Resources\Drop\DropStoreResource\Pages;

use App\Filament\App\Resources\Drop\DropStoreResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDropStore extends CreateRecord
{
    protected static string $resource = DropStoreResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();
        if ($user && $user->client) {
            $data['client_id'] = $user->client->id;
        }
        return $data;
    }
}
