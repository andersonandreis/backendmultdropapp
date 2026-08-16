<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

class SupplierOrdersChart extends ChartWidget
{
    protected static ?string $heading = 'Status dos Pedidos';
    protected static ?int $sort = 2;

    protected function getData(): array
    {
        $user = auth()->user();
        $supplierId = ($user->profile === 'supplier' && $user->supplier) ? $user->supplier->id : null;

        $query = Order::query()->select('status', DB::raw('count(*) as total'));
        if ($supplierId) {
            $query->where('supplier_id', $supplierId);
        }

        $statuses = $query->groupBy('status')->pluck('total', 'status')->toArray();

        return [
            'datasets' => [
                [
                    'label' => 'Pedidos',
                    'data' => [
                        $statuses['pending'] ?? 0,
                        $statuses['paid'] ?? 0,
                        $statuses['separated'] ?? 0,
                        $statuses['shipped'] ?? 0,
                        $statuses['completed'] ?? 0,
                    ],
                    'backgroundColor' => [
                        '#9ca3af', // Gray (Pending)
                        '#3b82f6', // Blue (Paid)
                        '#f59e0b', // Amber (Separated)
                        '#8b5cf6', // Violet (Shipped)
                        '#10b981', // Emerald (Completed)
                    ]
                ],
            ],
            'labels' => ['Pendente', 'Confirmado', 'Separando', 'Enviado', 'Entregue'],
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
