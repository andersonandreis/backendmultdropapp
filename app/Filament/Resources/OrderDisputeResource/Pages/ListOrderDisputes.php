<?php

namespace App\Filament\Resources\OrderDisputeResource\Pages;

use App\Filament\Resources\OrderDisputeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListOrderDisputes extends ListRecords
{
    protected static string $resource = OrderDisputeResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
