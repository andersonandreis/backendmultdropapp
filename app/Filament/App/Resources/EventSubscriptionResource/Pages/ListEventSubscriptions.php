<?php

namespace App\Filament\App\Resources\EventSubscriptionResource\Pages;

use App\Filament\App\Resources\EventSubscriptionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListEventSubscriptions extends ListRecords
{
    protected static string $resource = EventSubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Nova Inscrição'),
        ];
    }
}
