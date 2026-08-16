<?php

namespace App\Filament\App\Resources\Drop\DropOrderResource\Pages;

use App\Filament\App\Resources\Drop\DropOrderResource;
use Filament\Resources\Pages\ListRecords;

class ListDropOrders extends ListRecords
{
    protected static string $resource = DropOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
