<?php

namespace App\Filament\Resources\FiscalNoteResource\Pages;

use App\Filament\Resources\FiscalNoteResource;
use Filament\Resources\Pages\ListRecords;

class ListFiscalNotes extends ListRecords
{
    protected static string $resource = FiscalNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
