<?php

namespace App\Filament\Resources\SupportOperatorResource\Pages;

use App\Filament\Resources\SupportOperatorResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSupportOperators extends ListRecords
{
    protected static string $resource = SupportOperatorResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
