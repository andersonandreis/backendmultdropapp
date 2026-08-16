<?php

namespace App\Filament\Resources\SupplierSmtpConfigResource\Pages;

use App\Filament\Resources\SupplierSmtpConfigResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSupplierSmtpConfig extends CreateRecord
{
    protected static string $resource = SupplierSmtpConfigResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['supplier_id'])) {
            $data['supplier_id'] = auth()->user()?->supplier?->id;
        }
        return $data;
    }
}
