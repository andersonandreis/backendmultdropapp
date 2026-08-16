<?php

namespace App\Filament\App\Resources\Drop\DropStoreResource\Pages;

use App\Filament\App\Resources\Drop\DropStoreResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDropStores extends ListRecords
{
    protected static string $resource = DropStoreResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Nova Loja')
                ->icon('heroicon-o-plus'),
        ];
    }
}
