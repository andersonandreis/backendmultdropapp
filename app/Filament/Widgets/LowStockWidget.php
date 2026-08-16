<?php

namespace App\Filament\Widgets;

use App\Models\Inventory;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * NOV-121 — Widget de estoque crítico.
 * Mostra top 10 SKUs abaixo do stock_alert_threshold.
 */
class LowStockWidget extends BaseWidget
{
    protected static ?int $sort = 5;
    protected int|string|array $columnSpan = 'full';
    protected static ?string $heading = 'Estoque Crítico';
    protected static ?string $pollingInterval = '120s';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Inventory::query()
                    ->whereNotNull('stock_alert_threshold')
                    ->whereColumn('quantity', '<', 'stock_alert_threshold')
                    ->with(['product:id,sku,name'])
                    ->limit(10)
            )
            ->columns([
                Tables\Columns\TextColumn::make('product.sku')->label('SKU'),
                Tables\Columns\TextColumn::make('product.name')->label('Produto')->limit(50),
                Tables\Columns\TextColumn::make('quantity')->label('Estoque')->badge()->color('danger'),
                Tables\Columns\TextColumn::make('stock_alert_threshold')->label('Limite'),
            ])
            ->paginated(false)
            ->emptyStateHeading('Sem produtos abaixo do limite de alerta')
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
