<?php

namespace App\Filament\Resources\SupplierPaymentSettingResource\Pages;

use App\Filament\Resources\SupplierPaymentSettingResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSupplierPaymentSetting extends EditRecord
{
    protected static string $resource = SupplierPaymentSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->label('Excluir'),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
