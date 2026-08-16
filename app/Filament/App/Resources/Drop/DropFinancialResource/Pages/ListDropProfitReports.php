<?php

namespace App\Filament\App\Resources\Drop\DropFinancialResource\Pages;

use App\Filament\App\Resources\Drop\DropFinancialResource;
use App\Filament\App\Resources\Drop\DropFinancialResource\Widgets\FinancialStatsWidget;
use Filament\Resources\Pages\ListRecords;

class ListDropProfitReports extends ListRecords
{
    protected static string $resource = DropFinancialResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            FinancialStatsWidget::class,
        ];
    }
}
