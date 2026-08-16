<?php

namespace App\Filament\Resources\SupplierWarehouseResource\Pages;

use App\Filament\Resources\SupplierWarehouseResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSupplierWarehouse extends CreateRecord
{
    protected static string $resource = SupplierWarehouseResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['supplier_id'])) {
            $data['supplier_id'] = auth()->user()?->supplier?->id;
        }
        return $data;
    }
}
