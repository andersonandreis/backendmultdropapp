<?php

namespace App\Filament\App\Resources\Drop\ImportedProductResource\Pages;

use App\Filament\App\Resources\Drop\ImportedProductResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditImportedProduct extends EditRecord
{
    protected static string $resource = ImportedProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
