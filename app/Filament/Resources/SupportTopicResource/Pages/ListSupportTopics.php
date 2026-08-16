<?php

namespace App\Filament\Resources\SupportTopicResource\Pages;

use App\Filament\Resources\SupportTopicResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSupportTopics extends ListRecords
{
    protected static string $resource = SupportTopicResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
