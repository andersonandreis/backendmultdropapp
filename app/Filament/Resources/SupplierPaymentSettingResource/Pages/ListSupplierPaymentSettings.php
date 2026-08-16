<?php

namespace App\Filament\Resources\SupplierPaymentSettingResource\Pages;

use App\Filament\Resources\SupplierPaymentSettingResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSupplierPaymentSettings extends ListRecords
{
    protected static string $resource = SupplierPaymentSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Nova Configuração'),
        ];
    }
}
