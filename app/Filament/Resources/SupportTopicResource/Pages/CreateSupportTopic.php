<?php

namespace App\Filament\Resources\SupportTopicResource\Pages;

use App\Filament\Resources\SupportTopicResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSupportTopic extends CreateRecord
{
    protected static string $resource = SupportTopicResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (!isset($data['supplier_id']) && auth()->user()?->supplier?->id) {
            $data['supplier_id'] = auth()->user()->supplier->id;
        }
        return $data;
    }
}
