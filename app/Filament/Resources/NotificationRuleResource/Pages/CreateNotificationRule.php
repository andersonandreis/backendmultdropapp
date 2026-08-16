<?php

namespace App\Filament\Resources\NotificationRuleResource\Pages;

use App\Filament\Resources\NotificationRuleResource;
use App\Models\NotificationRule;
use Filament\Resources\Pages\CreateRecord;

class CreateNotificationRule extends CreateRecord
{
    protected static string $resource = NotificationRuleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();

        if ($user?->role === 'supplier' && $user->supplier) {
            $data['supplier_id'] = $user->supplier->id;
        }

        $data['created_by'] = $user?->id;

        return $data;
    }

    protected function afterCreate(): void
    {
        NotificationRuleResource::auditChange($this->record, 'create');
    }
}
