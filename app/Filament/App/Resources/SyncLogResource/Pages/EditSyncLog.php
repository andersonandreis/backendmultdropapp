<?php

namespace App\Filament\App\Resources\SyncLogResource\Pages;

use App\Filament\App\Resources\SyncLogResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSyncLog extends EditRecord
{
    protected static string $resource = SyncLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
