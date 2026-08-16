<?php

namespace App\Filament\App\Resources\Drop\DropOrderResource\Pages;

use App\Filament\App\Resources\Drop\DropOrderResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewDropOrder extends ViewRecord
{
    protected static string $resource = DropOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
