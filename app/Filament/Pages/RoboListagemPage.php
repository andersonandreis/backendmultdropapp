<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\ListingQueueStatsWidget;
use App\Filament\Widgets\ListingControlsWidget;
use App\Filament\Widgets\ListingQueueTableWidget;
use App\Filament\Widgets\ListingJobLogWidget;
use Filament\Pages\Page;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;

class RoboListagemPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';
    protected static ?string $title = 'Robo de Cadastro';
    protected static ?string $navigationLabel = 'Robo de Cadastro';
    protected static ?string $navigationGroup = 'Operações';
    protected static ?int $navigationSort = 10;
    protected static ?string $slug = 'robo-cadastro';
    protected static string $view = 'filament.pages.robo-listagem';

    public static function canAccess(): bool
    {
        return auth()->user()?->role === 'super_admin';
    }

    /**
     * @return array<class-string<Widget> | WidgetConfiguration>
     */
    public function getWidgets(): array
    {
        return [
            ListingQueueStatsWidget::class,
            ListingControlsWidget::class,
            ListingQueueTableWidget::class,
            ListingJobLogWidget::class,
        ];
    }

    /**
     * @return array<class-string<Widget> | WidgetConfiguration>
     */
    public function getVisibleWidgets(): array
    {
        return $this->filterVisibleWidgets($this->getWidgets());
    }

    public function getColumns(): int|string|array
    {
        return 1;
    }
}
