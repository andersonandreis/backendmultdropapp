<?php

namespace App\Filament\Resources\SupplierWarehouseResource\Pages;

use App\Filament\Resources\SupplierWarehouseResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSupplierWarehouse extends EditRecord
{
    protected static string $resource = SupplierWarehouseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
