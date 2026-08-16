<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use App\Models\Inventory;
use App\Models\OrderItem;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class SupplierStatsOverview extends BaseWidget
{
    protected static ?int $sort = 1;
    // Removendo columnSpan para usar o layout padrao de 3 cols se precisarmos ou full

    protected function getStats(): array
    {
        $user = auth()->user();

        $supplierId = ($user->profile === 'supplier' && $user->supplier) ? $user->supplier->id : null;

        $startOfMonth = Carbon::now()->startOfMonth();

        // 1. Faturamento do Mês
        $ordersQuery = Order::query()
            ->whereIn('status', ['paid', 'separated', 'shipped', 'completed'])
            ->where('created_at', '>=', $startOfMonth);

        if ($supplierId) {
            $ordersQuery->where('supplier_id', $supplierId);
        }

        $totalRevenue = (clone $ordersQuery)->sum('subtotal');
        $ordersCount = (clone $ordersQuery)->count();
        $ticketMedio = $ordersCount > 0 ? ($totalRevenue / $ordersCount) : 0;

        // 2. Lucro Líquido (Unit Price - Cost) * Quantity
        $itemsQuery = OrderItem::query()
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('product_variations', 'order_items.product_variation_id', '=', 'product_variations.id')
            ->where('orders.created_at', '>=', $startOfMonth)
            ->whereIn('orders.status', ['paid', 'separated', 'shipped', 'completed']);

        if ($supplierId) {
            $itemsQuery->where('orders.supplier_id', $supplierId);
        }

        $netProfit = $itemsQuery->sum(DB::raw('(order_items.unit_price - coalesce(product_variations.cost, 0)) * order_items.quantity'));

        // 3. Tempo Médio de Postagem Pós-Pagamento (SLA em Dias/Horas)
        $shippedOrdersQuery = Order::query()
            ->whereNotNull('shipped_at')
            ->whereNotNull('paid_at')
            ->where('created_at', '>=', $startOfMonth);

        if ($supplierId) {
            $shippedOrdersQuery->where('supplier_id', $supplierId);
        }

        $avgDispatchHoursRaw = (clone $shippedOrdersQuery)->avg(DB::raw('TIMESTAMPDIFF(HOUR, paid_at, shipped_at)'));
        $avgDispatchHours = round((float) $avgDispatchHoursRaw, 1);

        $dispatchLabel = $avgDispatchHours > 0 ? "{$avgDispatchHours}h em média" : "—";
        $dispatchColor = $avgDispatchHours <= 24 ? 'success' : ($avgDispatchHours <= 48 ? 'warning' : 'danger');

        return [
            Stat::make('Faturamento Mês Atual', 'R$ ' . number_format((float) $totalRevenue, 2, ',', '.'))
                ->description("Ticket Médio: R$ " . number_format((float) $ticketMedio, 2, ',', '.'))
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),

            Stat::make('Lucro Líquido Real (Mês)', 'R$ ' . number_format((float) $netProfit, 2, ',', '.'))
                ->description('Receita gerada menos custos de fabricação')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('primary'),

            Stat::make('Tempo de Envio', $dispatchLabel)
                ->description($avgDispatchHours > 0 ? 'Tempo médio de envio após o pagamento' : 'Ainda não há envios registrados')
                ->descriptionIcon('heroicon-m-truck')
                ->color($avgDispatchHours > 0 ? $dispatchColor : 'gray'),
        ];
    }
}
