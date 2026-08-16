<?php

namespace App\Filament\Resources\SupplierDiscountResource\Pages;

use App\Filament\Resources\SupplierDiscountResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSupplierDiscounts extends ListRecords
{
    protected static string $resource = SupplierDiscountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
