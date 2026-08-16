<?php

namespace App\Filament\App\Resources\Drop\DropTrafficResource\Widgets;

use App\Models\Drop\DropTrackingPixel;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TrafficStatsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $user     = auth()->user();
        $clientId = ($user && $user->role !== 'super_admin') ? $user->client?->id : null;

        $query = DropTrackingPixel::query();
        if ($clientId) {
            $query->where('client_id', $clientId);
        }

        $totalAtivos      = (clone $query)->where('is_active', true)->count();
        $plataformas      = (clone $query)->distinct('platform')->count('platform');
        $totalPixels      = (clone $query)->count();

        return [
            Stat::make('Pixels Ativos', $totalAtivos)
                ->description('De ' . $totalPixels . ' cadastrados')
                ->descriptionIcon('heroicon-m-signal')
                ->color('success'),
            Stat::make('Plataformas Conectadas', $plataformas)
                ->description('Meta, Google, TikTok')
                ->descriptionIcon('heroicon-m-squares-2x2')
                ->color('primary'),
            Stat::make('Total de Pixels', $totalPixels)
                ->description('Incluindo inativos')
                ->descriptionIcon('heroicon-m-tag')
                ->color('gray'),
        ];
    }
}
