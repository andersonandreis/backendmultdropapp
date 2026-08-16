<?php

namespace App\Filament\Resources\OrderDisputeResource\Pages;

use App\Filament\Resources\OrderDisputeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditOrderDispute extends EditRecord
{
    protected static string $resource = OrderDisputeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
