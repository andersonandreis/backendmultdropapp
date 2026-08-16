<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

class MarketplaceDistributionChart extends ChartWidget
{
    protected static ?string $heading = 'Distribuição por Marketplace';
    protected static ?int $sort = 6;

    protected function getData(): array
    {
        $user = auth()->user();
        $supplierId = ($user->profile === 'supplier' && $user->supplier) ? $user->supplier->id : null;

        $query = Order::query()
            ->select('source', DB::raw('count(*) as total'))
            ->whereIn('status', ['paid', 'separated', 'shipped', 'completed']);

        if ($supplierId) {
            $query->where('supplier_id', $supplierId);
        }

        $distribution = $query->groupBy('source')->pluck('total', 'source')->toArray();

        // Mapeamento de cores
        $colors = [
            'mercadolivre' => '#ffe600', // ML Yellow
            'shopee' => '#ee4d2d', // Shopee Orange
            'bling' => '#1890ff', // Bling Blue
            'app' => '#10b981', // App Green
        ];

        $labels = [];
        $data = [];
        $backgroundColors = [];

        foreach ($distribution as $source => $total) {
            $labels[] = ucfirst($source ?: 'Manual');
            $data[] = $total;
            $backgroundColors[] = $colors[$source] ?? '#6b7280'; // Gray fallback
        }

        return [
            'datasets' => [
                [
                    'label' => 'Canais de Venda',
                    'data' => $data,
                    'backgroundColor' => $backgroundColors,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
