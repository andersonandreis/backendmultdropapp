<?php

namespace App\Filament\App\Resources\Drop\ImportedProductResource\Pages;

use App\Filament\App\Resources\Drop\ImportedProductResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListImportedProducts extends ListRecords
{
    protected static string $resource = ImportedProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Importar Produto')
                ->icon('heroicon-o-plus'),
        ];
    }
}
