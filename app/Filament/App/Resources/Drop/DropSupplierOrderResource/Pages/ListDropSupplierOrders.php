<?php

namespace App\Filament\App\Resources\Drop\DropSupplierOrderResource\Pages;

use App\Filament\App\Resources\Drop\DropSupplierOrderResource;
use Filament\Resources\Pages\ListRecords;

class ListDropSupplierOrders extends ListRecords
{
    protected static string $resource = DropSupplierOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
