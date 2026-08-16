<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

class ShippingModeChart extends ChartWidget
{
    protected static ?string $heading = 'Modalidades Logísticas';
    protected static ?int $sort = 7;

    protected function getData(): array
    {
        $user = auth()->user();
        $supplierId = ($user->profile === 'supplier' && $user->supplier) ? $user->supplier->id : null;

        $query = Order::query()
            ->select('shipping_mode', DB::raw('count(*) as total'))
            ->whereNotNull('shipping_mode')
            ->whereIn('status', ['paid', 'separated', 'shipped', 'completed']);

        if ($supplierId) {
            $query->where('supplier_id', $supplierId);
        }

        $distribution = $query->groupBy('shipping_mode')->pluck('total', 'shipping_mode')->toArray();

        $labels = [];
        $data = [];
        // Preset de cores geradas dinamicamente caso tenhamos varios nomes de modalidade
        $defaultColors = ['#f87171', '#fbbf24', '#34d399', '#60a5fa', '#a78bfa', '#f472b6'];
        $backgroundColors = [];

        $i = 0;
        foreach ($distribution as $mode => $total) {
            $labels[] = strtoupper(str_replace('_', ' ', $mode));
            $data[] = $total;
            $backgroundColors[] = $defaultColors[$i % count($defaultColors)];
            $i++;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Uso Logístico',
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
