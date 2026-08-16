<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use App\Filament\Widgets\ServerHealthWidget;
use App\Filament\Widgets\QueueHealthWidget;
use App\Filament\Widgets\JobStatsWidget;
use App\Filament\Widgets\RoboCadastroMiniWidget;
use App\Filament\Widgets\FinancialSummaryWidget;
use App\Filament\Widgets\MetaAdsWidget;

class CentroComandoPage extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-squares-2x2';
    protected static ?string $title           = 'Centro de Comando';
    protected static ?string $navigationLabel = 'Centro de Comando';
    protected static ?string $navigationGroup = 'Operações';
    protected static ?int    $navigationSort  = 1;
    protected static string  $view            = 'filament.pages.centro-comando';

    public static function canAccess(): bool
    {
        return auth()->user()?->role === 'super_admin';
    }

    public function getWidgets(): array
    {
        return [
            ServerHealthWidget::class,
            QueueHealthWidget::class,
            JobStatsWidget::class,
            RoboCadastroMiniWidget::class,
            FinancialSummaryWidget::class,
            MetaAdsWidget::class,
        ];
    }

    public function getColumns(): int|string|array
    {
        return 2;
    }
}
