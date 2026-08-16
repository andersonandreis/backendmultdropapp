<?php

namespace App\Filament\Pages;

use App\Models\Order;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class TopClientesPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-trophy';
    protected static ?string $navigationGroup = 'Relatórios';
    protected static ?string $navigationLabel = 'Top Clientes';
    protected static ?string $title = 'Top Clientes';
    protected static ?string $slug = 'top-clientes';
    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.top-clientes';

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'supplier']);
    }

    public function getTableRecordKey(\Illuminate\Database\Eloquent\Model $record): string
    {
        return (string) ($record->client_id ?? $record->getKey() ?? uniqid());
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function () {
                $query = Order::query()
                    ->select(
                        'client_id',
                        DB::raw('COUNT(*) as total_pedidos'),
                        DB::raw('SUM(total) as total_vendas'),
                        DB::raw('MAX(created_at) as ultimo_pedido_at')
                    )
                    ->with('client')
                    ->whereIn('status', ['paid', 'shipped', 'delivered'])
                    ->groupBy('client_id')
                    ->orderByDesc('total_pedidos');

                // Escopo supplier
                $user = auth()->user();
                if ($user?->role === 'supplier' && $user->supplier) {
                    $query->where('supplier_id', $user->supplier->id);
                }

                return $query;
            })
            ->columns([
                TextColumn::make('ranking')
                    ->label('#')
                    ->rowIndex(),

                // MUL-269 fase 2: client.company_name via accessor; busca no user conectado.
                TextColumn::make('client.company_name')
                    ->label('Cliente')
                    ->searchable(query: fn ($query, $search) => $query->whereHas('client.user', fn ($u) => $u->where('name', 'like', "%{$search}%")->orWhere('full_name', 'like', "%{$search}%"))),

                TextColumn::make('client.document')
                    ->label('Documento')
                    ->toggleable(),

                TextColumn::make('total_pedidos')
                    ->label('Total de Pedidos')
                    ->badge()
                    ->color('primary')
                    ->sortable(),

                TextColumn::make('total_vendas')
                    ->label('Volume de Vendas')
                    ->money('BRL')
                    ->sortable(),

                TextColumn::make('ultimo_pedido_at')
                    ->label('Último Pedido')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->filters([
                Filter::make('periodo_preset')
                    ->label('Período rápido')
                    ->form([
                        Select::make('preset')
                            ->label('Período')
                            ->options([
                                '7d'  => 'Últimos 7 dias',
                                '30d' => 'Últimos 30 dias',
                                '90d' => 'Últimos 90 dias',
                                'all' => 'Todo o histórico',
                            ])
                            ->default('30d')
                            ->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $preset = $data['preset'] ?? '30d';
                        return match ($preset) {
                            '7d'  => $query->where('created_at', '>=', now()->subDays(7)),
                            '30d' => $query->where('created_at', '>=', now()->subDays(30)),
                            '90d' => $query->where('created_at', '>=', now()->subDays(90)),
                            default => $query,
                        };
                    }),

                Filter::make('periodo_custom')
                    ->label('Período customizado')
                    ->form([
                        DatePicker::make('data_inicio')->label('De')->displayFormat('d/m/Y'),
                        DatePicker::make('data_fim')->label('Até')->displayFormat('d/m/Y'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['data_inicio'], fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
                            ->when($data['data_fim'],   fn ($q, $v) => $q->whereDate('created_at', '<=', $v));
                    }),
            ])
            ->defaultSort('total_pedidos', 'desc');
    }
}
