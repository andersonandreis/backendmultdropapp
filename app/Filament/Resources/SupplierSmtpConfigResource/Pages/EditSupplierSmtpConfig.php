<?php

namespace App\Filament\Resources\SupplierSmtpConfigResource\Pages;

use App\Filament\Resources\SupplierSmtpConfigResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSupplierSmtpConfig extends EditRecord
{
    protected static string $resource = SupplierSmtpConfigResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
