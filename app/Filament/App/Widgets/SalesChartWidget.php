<?php

namespace App\Filament\App\Widgets;

use App\Models\Order;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;

class SalesChartWidget extends ChartWidget
{
    protected static ?string $heading = 'Vendas dos Ultimos 30 Dias';
    protected static ?int $sort = 1;
    protected static string $color = 'primary';
    protected int | string | array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->role === 'client';
    }

    protected function getData(): array
    {
        $client = auth()->user()?->client;
        $clientId = $client?->id;

        $labels = [];
        $values = [];

        for ($i = 29; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i);
            $labels[] = $date->format('d/m');

            $count = Order::where('client_id', $clientId)
                ->whereDate('created_at', $date->toDateString())
                ->count();

            $values[] = $count;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Pedidos',
                    'data' => $values,
                    'backgroundColor' => 'rgba(99,102,241,0.25)',
                    'borderColor' => 'rgba(99,102,241,1)',
                    'borderWidth' => 2,
                    'borderRadius' => 6,
                    'fill' => true,
                    'tension' => 0.4,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => ['display' => false],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => ['precision' => 0],
                    'grid' => [
                        'color' => 'rgba(255,255,255,0.06)',
                    ],
                ],
                'x' => [
                    'grid' => [
                        'display' => false,
                    ],
                ],
            ],
        ];
    }
}
