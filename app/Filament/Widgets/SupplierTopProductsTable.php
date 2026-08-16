<?php

namespace App\Filament\Widgets;

use App\Models\OrderItem;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\DB;

class SupplierTopProductsTable extends BaseWidget
{
    protected static ?int $sort = 4;
    protected int|string|array $columnSpan = 'full';
    protected static ?string $heading = 'Curva ABC: Top Produtos Mais Vendidos';

    public function getTableRecordKey(\Illuminate\Database\Eloquent\Model $record): string
    {
        return $record->sku ?? ($record->name ?? (string) $record->getKey());
    }

    public function table(Table $table): Table
    {
        $user = auth()->user();
        $supplierId = ($user->profile === 'supplier' && $user->supplier) ? $user->supplier->id : null;

        $query = OrderItem::query()
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->leftJoin('product_variations', 'order_items.product_variation_id', '=', 'product_variations.id')
            ->select(
                'order_items.name',
                'order_items.sku',
                DB::raw('SUM(order_items.quantity) as total_quantity'),
                DB::raw('SUM((order_items.unit_price - coalesce(product_variations.cost, 0)) * order_items.quantity) as net_profit'),
                DB::raw('SUM(order_items.unit_price * order_items.quantity) as gross_revenue')
            )
            ->whereIn('orders.status', ['paid', 'separated', 'shipped', 'completed'])
            ->groupBy('order_items.name', 'order_items.sku')
            ->orderByDesc('total_quantity')
            ->limit(10);

        if ($supplierId) {
            $query->where('orders.supplier_id', $supplierId);
        }

        return $table
            ->query($query)
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Produto')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('sku')
                    ->label('SKU')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('total_quantity')
                    ->label('Volume Vendido')
                    ->sortable()
                    ->badge()
                    ->color('primary'),
                Tables\Columns\TextColumn::make('gross_revenue')
                    ->label('Faturamento Bruto')
                    ->money('BRL')
                    ->sortable(),
                Tables\Columns\TextColumn::make('net_profit')
                    ->label('Lucro Líquido Estimado')
                    ->money('BRL')
                    ->color('success')
                    ->sortable(),
            ])
            ->paginated(false);
    }
}
