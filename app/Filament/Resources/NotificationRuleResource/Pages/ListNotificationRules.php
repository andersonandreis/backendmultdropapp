<?php

namespace App\Filament\Resources\NotificationRuleResource\Pages;

use App\Filament\Resources\NotificationRuleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListNotificationRules extends ListRecords
{
    protected static string $resource = NotificationRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
