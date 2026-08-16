<?php

namespace App\Filament\Resources\ProductVariationResource\Pages;

use App\Filament\Resources\ProductVariationResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateProductVariation extends CreateRecord
{
    protected static string $resource = ProductVariationResource::class;
}
