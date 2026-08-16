<?php

namespace App\Filament\Pages;

use App\Models\Order;
use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Actions\Action;
use Illuminate\Database\Eloquent\Builder;

class RetornoPedidos extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-uturn-left';
    protected static ?string $navigationGroup = 'Pedidos & Logística';
    protected static ?string $navigationLabel = 'Retorno de Pedidos';
    protected static ?string $title = 'Retorno de Pedidos';
    protected static ?string $slug = 'retorno-pedidos';
    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.retorno-pedidos';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Order::query()
                    ->with(['client', 'items.product'])
                    ->whereIn('status', ['returned', 'cancelled'])
                    ->latest()
            )
            ->columns([
                TextColumn::make('order_number')
                    ->label('Pedido')
                    ->searchable(),

                TextColumn::make('customer_name')
                    ->label('Cliente')
                    ->searchable(),

                TextColumn::make('tracking_number')
                    ->label('Rastreio')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('invoice_number')
                    ->label('NF')
                    ->searchable(),

                TextColumn::make('client.company_name')
                    ->label('Seller'),

                TextColumn::make('status')
                    ->label('Situação')
                    ->badge()
                    ->formatStateUsing(fn(string $state) => match ($state) {
                        'returned'  => 'Devolvido',
                        'cancelled' => 'Cancelado',
                        default     => $state,
                    })
                    ->color(fn(string $state) => 'danger'),

                TextColumn::make('total')
                    ->label('Valor')
                    ->money('BRL')
                    ->sortable(),

                TextColumn::make('cancelled_at')
                    ->label('Data Retorno')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->searchable()
            ->defaultSort('cancelled_at', 'desc');
    }
}
