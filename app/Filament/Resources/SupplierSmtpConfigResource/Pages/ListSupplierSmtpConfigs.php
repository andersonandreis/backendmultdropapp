<?php

namespace App\Filament\Resources\SupplierSmtpConfigResource\Pages;

use App\Filament\Resources\SupplierSmtpConfigResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSupplierSmtpConfigs extends ListRecords
{
    protected static string $resource = SupplierSmtpConfigResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
