<?php

namespace App\Filament\App\Resources\Drop\DropTrafficResource\Pages;

use App\Filament\App\Resources\Drop\DropTrafficResource;
use App\Filament\App\Resources\Drop\DropTrafficResource\Widgets\TrafficStatsWidget;
use Filament\Resources\Pages\ListRecords;

class ListDropTrackingPixels extends ListRecords
{
    protected static string $resource = DropTrafficResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            TrafficStatsWidget::class,
        ];
    }
}
