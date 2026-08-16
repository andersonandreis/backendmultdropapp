<?php

namespace App\Filament\Resources\SupplierBrandingResource\Pages;

use App\Filament\Resources\SupplierBrandingResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSupplierBranding extends CreateRecord
{
    protected static string $resource = SupplierBrandingResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (!isset($data["supplier_id"]) && auth()->user()?->supplier?->id) {
            $data["supplier_id"] = auth()->user()->supplier->id;
        }
        return $data;
    }
}
