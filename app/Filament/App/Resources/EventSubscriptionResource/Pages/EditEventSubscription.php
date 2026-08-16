<?php

namespace App\Filament\App\Resources\EventSubscriptionResource\Pages;

use App\Filament\App\Resources\EventSubscriptionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditEventSubscription extends EditRecord
{
    protected static string $resource = EventSubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->label('Excluir'),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
