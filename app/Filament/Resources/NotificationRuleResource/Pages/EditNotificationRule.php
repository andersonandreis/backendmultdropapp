<?php

namespace App\Filament\Resources\NotificationRuleResource\Pages;

use App\Filament\Resources\NotificationRuleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditNotificationRule extends EditRecord
{
    protected static string $resource = NotificationRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->after(fn () => NotificationRuleResource::auditChange($this->record, 'delete')),
        ];
    }

    protected function afterSave(): void
    {
        NotificationRuleResource::auditChange($this->record, 'update');
    }
}
