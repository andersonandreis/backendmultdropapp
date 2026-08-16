<?php

namespace App\Filament\Resources\SupplierDiscountResource\Pages;

use App\Filament\Resources\SupplierDiscountResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSupplierDiscount extends EditRecord
{
    protected static string $resource = SupplierDiscountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
