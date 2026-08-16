<?php

namespace App\Filament\Resources\SupplierBrandingResource\Pages;

use App\Filament\Resources\SupplierBrandingResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSupplierBrandings extends ListRecords
{
    protected static string $resource = SupplierBrandingResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
