<?php

namespace App\Filament\Resources\AffiliateCommissionResource\Pages;

use App\Filament\Resources\AffiliateCommissionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAffiliateCommission extends EditRecord
{
    protected static string $resource = AffiliateCommissionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
