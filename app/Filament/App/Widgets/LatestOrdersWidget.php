<?php

namespace App\Filament\App\Widgets;

use App\Models\Order;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class LatestOrdersWidget extends BaseWidget
{
    protected static ?string $heading = 'Ultimas Vendas';
    protected static ?int $sort = 2;
    protected int | string | array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->role === 'client';
    }

    public function table(Table $table): Table
    {
        $client = auth()->user()?->client;

        return $table
            ->query(
                Order::query()
                    ->where('client_id', $client?->id)
                    ->latest()
                    ->limit(10)
            )
            ->columns([
                Tables\Columns\TextColumn::make('order_number')
                    ->label('Pedido')
                    ->searchable()
                    ->weight('bold')
                    ->color('primary'),

                Tables\Columns\TextColumn::make('source')
                    ->label('Canal')
                    ->badge()
                    ->formatStateUsing(fn ($state) => strtoupper($state ?? 'N/A')),

                Tables\Columns\TextColumn::make('subtotal')
                    ->label('Venda')
                    ->money('BRL')
                    ->color('success'),

                Tables\Columns\TextColumn::make('supplier_total')
                    ->label('Custo')
                    ->money('BRL')
                    ->color('danger'),

                Tables\Columns\TextColumn::make('lucro')
                    ->label('Lucro')
                    ->state(function (Order $record): string {
                        $lucro = ($record->subtotal ?? 0) - ($record->supplier_total ?? 0);
                        return 'R$ ' . number_format($lucro, 2, ',', '.');
                    })
                    ->color(function (Order $record): string {
                        $lucro = ($record->subtotal ?? 0) - ($record->supplier_total ?? 0);
                        return $lucro >= 0 ? 'success' : 'danger';
                    }),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'pending'   => 'Pendente',
                        'paid'      => 'Confirmado',
                        'separated' => 'Separado',
                        'shipped'   => 'Enviado',
                        'completed' => 'Concluido',
                        'cancelled' => 'Cancelado',
                        default     => ucfirst($state ?? 'N/A'),
                    })
                    ->color(fn ($state) => match ($state) {
                        'pending'   => 'warning',
                        'paid'      => 'info',
                        'separated' => 'primary',
                        'shipped'   => 'success',
                        'completed' => 'success',
                        'cancelled' => 'danger',
                        default     => 'gray',
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Data')
                    ->dateTime('d/m/Y H:i')
                    ->color('gray'),
            ])
            ->paginated(false);
    }
}
