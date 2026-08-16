<?php

namespace App\Filament\Pages;

use App\Models\Order;
use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Forms\Components\DatePicker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class PedidosEnviados extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-paper-airplane';
    protected static ?string $navigationGroup = 'Pedidos & Logística';
    protected static ?string $navigationLabel = 'Pedidos Enviados';
    protected static ?string $title = 'Pedidos Enviados';
    protected static ?string $slug = 'pedidos-enviados';
    protected static ?int $navigationSort = 6;

    protected static string $view = 'filament.pages.pedidos-enviados';

    public function getMetricas(): array
    {
        $hoje      = Order::whereDate('shipped_at', today())->whereIn('status', ['shipped', 'delivered'])->count();
        $semana    = Order::whereBetween('shipped_at', [now()->startOfWeek(), now()->endOfWeek()])->whereIn('status', ['shipped', 'delivered'])->count();
        $mes       = Order::whereMonth('shipped_at', now()->month)->whereYear('shipped_at', now()->year)->whereIn('status', ['shipped', 'delivered'])->count();
        $valorMes  = Order::whereMonth('shipped_at', now()->month)->whereYear('shipped_at', now()->year)->whereIn('status', ['shipped', 'delivered'])->sum('total');

        return [
            ['label' => 'Enviados Hoje',       'valor' => $hoje,     'cor' => 'blue'],
            ['label' => 'Enviados esta Semana', 'valor' => $semana,   'cor' => 'green'],
            ['label' => 'Enviados este Mês',    'valor' => $mes,      'cor' => 'indigo'],
            ['label' => 'Valor Despachado/Mês', 'valor' => 'R$ ' . number_format((float) $valorMes, 2, ',', '.'), 'cor' => 'emerald'],
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Order::query()
                    ->with(['client'])
                    ->whereIn('status', ['shipped', 'delivered'])
                    ->whereNotNull('shipped_at')
                    ->latest('shipped_at')
            )
            ->columns([
                TextColumn::make('order_number')
                    ->label('Pedido')
                    ->searchable(),

                TextColumn::make('client.company_name')
                    ->label('Seller'),

                TextColumn::make('source')
                    ->label('Canal')
                    ->badge(),

                TextColumn::make('tracking_number')
                    ->label('Rastreio')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('carrier_name')
                    ->label('Transportadora'),

                TextColumn::make('total')
                    ->label('Valor')
                    ->money('BRL')
                    ->sortable(),

                TextColumn::make('shipped_at')
                    ->label('Despachado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn(string $state) => match ($state) {
                        'shipped'   => 'Enviado',
                        'delivered' => 'Entregue',
                        default     => $state,
                    })
                    ->color(fn(string $state) => match ($state) {
                        'shipped'   => 'info',
                        'delivered' => 'success',
                        default     => 'gray',
                    }),
            ])
            ->filters([
                Filter::make('periodo')
                    ->label('Período')
                    ->form([
                        DatePicker::make('data_inicio')->label('De')->displayFormat('d/m/Y'),
                        DatePicker::make('data_fim')->label('Até')->displayFormat('d/m/Y'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['data_inicio'], fn($q, $val) => $q->whereDate('shipped_at', '>=', $val))
                            ->when($data['data_fim'],   fn($q, $val) => $q->whereDate('shipped_at', '<=', $val));
                    }),
            ])
            ->defaultSort('shipped_at', 'desc');
    }
}
