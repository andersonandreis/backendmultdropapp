<?php

namespace App\Filament\App\Resources\Drop\DropStoreResource\Pages;

use App\Filament\App\Resources\Drop\DropStoreResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDropStore extends EditRecord
{
    protected static string $resource = DropStoreResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
