<?php

namespace App\Filament\Resources\SupplierBillingResource\Pages;

use App\Filament\Resources\SupplierBillingResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSupplierBilling extends ListRecords
{
    protected static string $resource = SupplierBillingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->visible(fn () => auth()->user()?->role === 'super_admin'),
        ];
    }
}
