<?php

namespace App\Filament\Resources\AffiliateWithdrawalResource\Pages;

use App\Filament\Resources\AffiliateWithdrawalResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAffiliateWithdrawal extends EditRecord
{
    protected static string $resource = AffiliateWithdrawalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
