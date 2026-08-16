<?php

namespace App\Filament\Resources\SupportQuickReplyResource\Pages;

use App\Filament\Resources\SupportQuickReplyResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSupportQuickReply extends CreateRecord
{
    protected static string $resource = SupportQuickReplyResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (!isset($data['supplier_id']) && auth()->user()?->supplier?->id) {
            $data['supplier_id'] = auth()->user()->supplier->id;
        }
        return $data;
    }
}
