<?php

namespace App\Filament\Resources\ErpAccountResource\Pages;

use App\Filament\Resources\ErpAccountResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditErpAccount extends EditRecord
{
    protected static string $resource = ErpAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
