<?php

namespace App\Filament\Resources\MarketplaceFeeResource\Pages;

use App\Filament\Resources\MarketplaceFeeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMarketplaceFee extends EditRecord
{
    protected static string $resource = MarketplaceFeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
