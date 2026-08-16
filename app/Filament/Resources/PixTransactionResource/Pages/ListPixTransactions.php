<?php

namespace App\Filament\Resources\PixTransactionResource\Pages;

use App\Filament\Resources\PixTransactionResource;
use App\Filament\Resources\PixTransactionResource\Widgets\PixStatsWidget;
use Filament\Resources\Pages\ListRecords;

class ListPixTransactions extends ListRecords
{
    protected static string $resource = PixTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            PixStatsWidget::class,
        ];
    }
}
