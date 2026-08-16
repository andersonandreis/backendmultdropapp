<?php

namespace App\Filament\Resources\SupportOperatorResource\Pages;

use App\Filament\Resources\SupportOperatorResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSupportOperator extends CreateRecord
{
    protected static string $resource = SupportOperatorResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (!isset($data['supplier_id']) && auth()->user()?->supplier?->id) {
            $data['supplier_id'] = auth()->user()->supplier->id;
        }
        return $data;
    }
}
