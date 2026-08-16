<?php

namespace App\Filament\Resources\PlatformDiscountResource\Pages;

use App\Filament\Resources\PlatformDiscountResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPlatformDiscount extends EditRecord
{
    protected static string $resource = PlatformDiscountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
