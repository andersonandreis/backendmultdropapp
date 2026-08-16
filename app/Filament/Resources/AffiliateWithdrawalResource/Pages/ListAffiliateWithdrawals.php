<?php

namespace App\Filament\Resources\AffiliateWithdrawalResource\Pages;

use App\Filament\Resources\AffiliateWithdrawalResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAffiliateWithdrawals extends ListRecords
{
    protected static string $resource = AffiliateWithdrawalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Novo Saque'),
        ];
    }
}
