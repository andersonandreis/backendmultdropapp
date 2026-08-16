<?php

namespace App\Filament\App\Resources\Drop\DropTrafficResource\Pages;

use App\Filament\App\Resources\Drop\DropTrafficResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDropTrackingPixel extends EditRecord
{
    protected static string $resource = DropTrafficResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
