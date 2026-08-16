<?php

namespace App\Filament\Resources\EmailLogResource\Pages;

use App\Filament\Resources\EmailLogResource;
use App\Filament\Widgets\EmailMetricsWidget;
use Filament\Resources\Pages\ListRecords;

class ListEmailLogs extends ListRecords
{
    protected static string $resource = EmailLogResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            EmailMetricsWidget::class,
        ];
    }
}
