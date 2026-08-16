<?php

namespace App\Filament\Resources\PlanDiscountResource\Pages;

use App\Filament\Resources\PlanDiscountResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPlanDiscount extends EditRecord
{
    protected static string $resource = PlanDiscountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
