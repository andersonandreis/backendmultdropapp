<?php

namespace App\Filament\Resources\SupportQuickReplyResource\Pages;

use App\Filament\Resources\SupportQuickReplyResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSupportQuickReplies extends ListRecords
{
    protected static string $resource = SupportQuickReplyResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
