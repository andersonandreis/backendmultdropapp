<?php

namespace App\Filament\Resources\PlanDiscountResource\Pages;

use App\Filament\Resources\PlanDiscountResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPlanDiscounts extends ListRecords
{
    protected static string $resource = PlanDiscountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
