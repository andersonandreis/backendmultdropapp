<?php

namespace App\Filament\Resources\SupplierPaymentSettingResource\Pages;

use App\Filament\Resources\SupplierPaymentSettingResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSupplierPaymentSetting extends CreateRecord
{
    protected static string $resource = SupplierPaymentSettingResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
