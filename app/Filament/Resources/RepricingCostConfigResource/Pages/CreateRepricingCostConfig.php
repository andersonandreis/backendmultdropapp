<?php

namespace App\Filament\Resources\RepricingCostConfigResource\Pages;

use App\Filament\Resources\RepricingCostConfigResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRepricingCostConfig extends CreateRecord
{
    protected static string $resource = RepricingCostConfigResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (!isset($data['supplier_id']) && auth()->user()?->supplier?->id) {
            $data['supplier_id'] = auth()->user()->supplier->id;
        }
        return $data;
    }
}
