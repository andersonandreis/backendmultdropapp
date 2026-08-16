<?php

namespace App\Filament\Resources\RepricingCostConfigResource\Pages;

use App\Filament\Resources\RepricingCostConfigResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRepricingCostConfigs extends ListRecords
{
    protected static string $resource = RepricingCostConfigResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
