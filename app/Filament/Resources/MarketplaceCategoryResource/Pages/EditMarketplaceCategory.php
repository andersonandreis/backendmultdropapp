<?php

namespace App\Filament\Resources\MarketplaceCategoryResource\Pages;

use App\Filament\Resources\MarketplaceCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMarketplaceCategory extends EditRecord
{
    protected static string $resource = MarketplaceCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
