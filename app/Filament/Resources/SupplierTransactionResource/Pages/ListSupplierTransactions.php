<?php

namespace App\Filament\Resources\SupplierTransactionResource\Pages;

use App\Filament\Resources\SupplierTransactionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSupplierTransactions extends ListRecords
{
    protected static string $resource = SupplierTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
