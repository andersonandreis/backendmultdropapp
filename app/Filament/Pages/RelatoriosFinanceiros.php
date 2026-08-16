<?php

namespace App\Filament\Pages;

use App\Models\Order;
use App\Models\SupplierTransaction;
use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Forms\Components\DatePicker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class RelatoriosFinanceiros extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';
    protected static ?string $navigationGroup = 'Financeiro';
    protected static ?string $navigationLabel = 'Relatórios';
    protected static ?string $title = 'Relatórios Financeiros';
    protected static ?string $slug = 'relatorios-financeiros';
    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.relatorios-financeiros';

    public function getBlocos(): array
    {
        $totalDrop      = Order::where('shipping_mode', 'drop')->sum('total');
        $aguardDrop     = Order::where('shipping_mode', 'drop')->where('status', 'pending')->sum('total');
        $bloqDrop       = Order::where('shipping_mode', 'drop')->where('status', 'cancelled')->sum('total');
        $liberDrop      = Order::where('shipping_mode', 'drop')->where('status', 'paid')->sum('total');

        $totalFull      = Order::where('shipping_mode', '!=', 'drop')->sum('total');
        $aguardFull     = Order::where('shipping_mode', '!=', 'drop')->where('status', 'pending')->sum('total');
        $bloqFull       = Order::where('shipping_mode', '!=', 'drop')->where('status', 'cancelled')->sum('total');
        $liberFull      = Order::where('shipping_mode', '!=', 'drop')->where('status', 'paid')->sum('total');

        $totalPedidos   = Order::count();
        $aguardPedidos  = Order::where('status', 'pending')->count();
        $bloqPedidos    = Order::where('status', 'cancelled')->count();
        $liberPedidos   = Order::where('status', 'paid')->count();

        return [
            [
                'titulo'     => 'Financeiro Drop',
                'total'      => 'R$ ' . number_format((float) $totalDrop, 2, ',', '.'),
                'aguardando' => 'R$ ' . number_format((float) $aguardDrop, 2, ',', '.'),
                'bloqueado'  => 'R$ ' . number_format((float) $bloqDrop, 2, ',', '.'),
                'liberado'   => 'R$ ' . number_format((float) $liberDrop, 2, ',', '.'),
                'cor'        => 'blue',
            ],
            [
                'titulo'     => 'Financeiro Full',
                'total'      => 'R$ ' . number_format((float) $totalFull, 2, ',', '.'),
                'aguardando' => 'R$ ' . number_format((float) $aguardFull, 2, ',', '.'),
                'bloqueado'  => 'R$ ' . number_format((float) $bloqFull, 2, ',', '.'),
                'liberado'   => 'R$ ' . number_format((float) $liberFull, 2, ',', '.'),
                'cor'        => 'green',
            ],
            [
                'titulo'     => 'Pedidos',
                'total'      => $totalPedidos,
                'aguardando' => $aguardPedidos,
                'bloqueado'  => $bloqPedidos,
                'liberado'   => $liberPedidos,
                'cor'        => 'purple',
            ],
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(SupplierTransaction::query()->with('supplier')->latest())
            ->columns([
                TextColumn::make('created_at')
                    ->label('Data')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('supplier.company_name')
                    ->label('Fornecedor')
                    ->searchable(),

                TextColumn::make('description')
                    ->label('Descrição')
                    ->limit(50),

                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn(string $state) => match ($state) {
                        'credit' => 'Crédito',
                        'debit'  => 'Débito',
                        default  => ucfirst($state),
                    })
                    ->color(fn(string $state) => match ($state) {
                        'credit' => 'success',
                        'debit'  => 'danger',
                        default  => 'gray',
                    }),

                TextColumn::make('amount')
                    ->label('Valor')
                    ->money('BRL')
                    ->sortable(),
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
                            ->when($data['data_inicio'], fn($q, $val) => $q->whereDate('created_at', '>=', $val))
                            ->when($data['data_fim'],   fn($q, $val) => $q->whereDate('created_at', '<=', $val));
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
