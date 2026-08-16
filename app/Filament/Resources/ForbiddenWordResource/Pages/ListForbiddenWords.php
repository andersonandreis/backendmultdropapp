<?php

namespace App\Filament\Resources\ForbiddenWordResource\Pages;

use App\Filament\Resources\ForbiddenWordResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListForbiddenWords extends ListRecords
{
    protected static string $resource = ForbiddenWordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
