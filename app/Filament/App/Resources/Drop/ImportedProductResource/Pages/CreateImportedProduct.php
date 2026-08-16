<?php

namespace App\Filament\App\Resources\Drop\ImportedProductResource\Pages;

use App\Filament\App\Resources\Drop\ImportedProductResource;
use Filament\Resources\Pages\CreateRecord;

class CreateImportedProduct extends CreateRecord
{
    protected static string $resource = ImportedProductResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();
        if ($user && $user->client) {
            $data['client_id'] = $user->client->id;
        }
        return $data;
    }
}
