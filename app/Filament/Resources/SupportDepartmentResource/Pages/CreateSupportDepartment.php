<?php

namespace App\Filament\Resources\SupportDepartmentResource\Pages;

use App\Filament\Resources\SupportDepartmentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSupportDepartment extends CreateRecord
{
    protected static string $resource = SupportDepartmentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (!isset($data['supplier_id']) && auth()->user()?->supplier?->id) {
            $data['supplier_id'] = auth()->user()->supplier->id;
        }
        return $data;
    }
}
