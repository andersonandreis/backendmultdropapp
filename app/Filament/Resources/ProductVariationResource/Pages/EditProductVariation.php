<?php

namespace App\Filament\Resources\ProductVariationResource\Pages;

use App\Filament\Resources\ProductVariationResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProductVariation extends EditRecord
{
    protected static string $resource = ProductVariationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
