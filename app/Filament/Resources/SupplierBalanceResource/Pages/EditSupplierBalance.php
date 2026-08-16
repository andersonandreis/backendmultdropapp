<?php

namespace App\Filament\Resources\SupplierBalanceResource\Pages;

use App\Filament\Resources\SupplierBalanceResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSupplierBalance extends EditRecord
{
    protected static string $resource = SupplierBalanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
