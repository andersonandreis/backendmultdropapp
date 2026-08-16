<?php

namespace App\Filament\Resources\CatalogDiscountRuleResource\Pages;

use App\Filament\Resources\CatalogDiscountRuleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCatalogDiscountRule extends EditRecord
{
    protected static string $resource = CatalogDiscountRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
