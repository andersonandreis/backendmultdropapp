<?php

namespace App\Filament\Resources\SupplierBannerResource\Pages;

use App\Filament\Resources\SupplierBannerResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSupplierBanner extends CreateRecord
{
    protected static string $resource = SupplierBannerResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['supplier_id'])) {
            $data['supplier_id'] = auth()->user()?->supplier?->id;
        }
        return $data;
    }
}
