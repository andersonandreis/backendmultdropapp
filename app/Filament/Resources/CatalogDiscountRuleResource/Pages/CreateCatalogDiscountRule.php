<?php

namespace App\Filament\Resources\CatalogDiscountRuleResource\Pages;

use App\Filament\Resources\CatalogDiscountRuleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCatalogDiscountRule extends CreateRecord
{
    protected static string $resource = CatalogDiscountRuleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['supplier_id'])) {
            $data['supplier_id'] = auth()->user()?->supplier?->id;
        }
        return $data;
    }
}
