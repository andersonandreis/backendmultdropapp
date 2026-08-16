<?php

namespace App\Filament\Resources\PlatformDiscountResource\Pages;

use App\Filament\Resources\PlatformDiscountResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPlatformDiscounts extends ListRecords
{
    protected static string $resource = PlatformDiscountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
