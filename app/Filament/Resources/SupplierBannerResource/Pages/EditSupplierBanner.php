<?php

namespace App\Filament\Resources\SupplierBannerResource\Pages;

use App\Filament\Resources\SupplierBannerResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSupplierBanner extends EditRecord
{
    protected static string $resource = SupplierBannerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
