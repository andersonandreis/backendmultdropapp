<?php

namespace App\Filament\Resources\SupplierUserResource\Pages;

use App\Filament\Resources\SupplierUserResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSupplierUsers extends ListRecords
{
    protected static string $resource = SupplierUserResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
