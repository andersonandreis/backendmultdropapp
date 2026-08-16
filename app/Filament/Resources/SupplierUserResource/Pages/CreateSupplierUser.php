<?php

namespace App\Filament\Resources\SupplierUserResource\Pages;

use App\Filament\Resources\SupplierUserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSupplierUser extends CreateRecord
{
    protected static string $resource = SupplierUserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (!isset($data["supplier_id"]) && auth()->user()?->supplier?->id) {
            $data["supplier_id"] = auth()->user()->supplier->id;
        }
        return $data;
    }
}
