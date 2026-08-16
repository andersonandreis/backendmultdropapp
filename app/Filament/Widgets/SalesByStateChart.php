<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use App\Models\Order;

class SalesByStateChart extends ChartWidget
{
    protected static ?string $heading = 'Mapa de Calor: Vendas por Estado';
    protected static ?int $sort = 5;

    protected function getData(): array
    {
        $user = auth()->user();
        $supplierId = ($user->profile === 'supplier' && $user->supplier) ? $user->supplier->id : null;

        $ordersQuery = Order::query()->whereIn('status', ['paid', 'separated', 'shipped', 'completed']);

        if ($supplierId) {
            $ordersQuery->where('supplier_id', $supplierId);
        }

        $orders = $ordersQuery->get(['customer_address']);

        $statesCount = [];

        foreach ($orders as $order) {
            $address = $order->customer_address;
            if (is_array($address) && isset($address['state'])) {
                $state = strtoupper(substr($address['state'], 0, 2)); // Pega a UF ex: SP, RJ
                if (!isset($statesCount[$state])) {
                    $statesCount[$state] = 0;
                }
                $statesCount[$state]++;
            } else {
                // Estado desconhecido
                $statesCount['N/I'] = ($statesCount['N/I'] ?? 0) + 1;
            }
        }

        arsort($statesCount); // Ordena do maior para o menor

        // Pega os top 10 estados
        $statesCount = array_slice($statesCount, 0, 10);

        return [
            'datasets' => [
                [
                    'label' => 'Total de Pedidos',
                    'data' => array_values($statesCount),
                    'backgroundColor' => '#f59e0b', // Amber
                ],
            ],
            'labels' => array_keys($statesCount),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
