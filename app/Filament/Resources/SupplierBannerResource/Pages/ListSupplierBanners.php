<?php

namespace App\Filament\Resources\SupplierBannerResource\Pages;

use App\Filament\Resources\SupplierBannerResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSupplierBanners extends ListRecords
{
    protected static string $resource = SupplierBannerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
