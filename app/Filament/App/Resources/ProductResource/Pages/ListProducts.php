<?php

namespace App\Filament\App\Resources\ProductResource\Pages;

use App\Filament\App\Resources\ProductResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    public bool $isGridLayout = true;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('toggleGrid')
                ->label(fn() => $this->isGridLayout ? 'Ver em Lista' : 'Ver em Grade')
                ->icon(fn() => $this->isGridLayout ? 'heroicon-o-list-bullet' : 'heroicon-o-squares-2x2')
                ->action(function () {
                    $this->isGridLayout = !$this->isGridLayout;
                }),
            Actions\CreateAction::make(),
        ];
    }
}
