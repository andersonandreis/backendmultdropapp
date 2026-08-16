<?php

namespace App\Filament\Pages;

use App\Models\Order;
use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Forms\Components\DatePicker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class TopLojas extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-star';
    protected static ?string $navigationGroup = 'Análises';
    protected static ?string $navigationLabel = 'Top Lojas';
    protected static ?string $title = 'Ranking — Top Sellers mais Ativos';
    protected static ?string $slug = 'top-lojas';
    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.top-lojas';

    public function getTableRecordKey(\Illuminate\Database\Eloquent\Model $record): string
    {
        return (string) ($record->client_id ?? $record->getKey() ?? uniqid());
    }

    /**
     * MUL-226-04: períodos rápidos reutilizáveis (Hoje ... Ano).
     */
    public static function applyQuickPeriod(Builder $query, string $periodo, string $column = 'created_at'): Builder
    {
        return match ($periodo) {
            'hoje'           => $query->whereDate($column, today()),
            'ontem'          => $query->whereDate($column, today()->subDay()),
            'semana'         => $query->whereBetween($column, [now()->startOfWeek(), now()->endOfWeek()]),
            'semana_passada' => $query->whereBetween($column, [now()->subWeek()->startOfWeek(), now()->subWeek()->endOfWeek()]),
            '7d'             => $query->where($column, '>=', now()->subDays(7)),
            'mes'            => $query->whereBetween($column, [now()->startOfMonth(), now()->endOfMonth()]),
            'mes_passado'    => $query->whereBetween($column, [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()]),
            'ano'            => $query->where($column, '>=', now()->startOfYear()),
            default          => $query,
        };
    }

    public static function quickPeriodOptions(): array
    {
        return [
            'hoje'           => 'Hoje',
            'ontem'          => 'Ontem',
            'semana'         => 'Esta Semana',
            'semana_passada' => 'Semana Passada',
            '7d'             => 'Últimos 7 dias',
            'mes'            => 'Este Mês',
            'mes_passado'    => 'Mês Passado',
            'ano'            => 'Este Ano',
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Order::query()
                    ->select(
                        'client_id',
                        DB::raw('COUNT(*) as total_pedidos'),
                        DB::raw('SUM(total) as total_vendas'),
                        // MUL-226-04: marketplaces usados no período, sem duplicidade
                        DB::raw('GROUP_CONCAT(DISTINCT source ORDER BY source SEPARATOR ",") as marketplaces'),
                        // MUL-226-04: canais de envio usados no período, sem duplicidade
                        DB::raw('GROUP_CONCAT(DISTINCT shipping_mode ORDER BY shipping_mode SEPARATOR ",") as canais_envio'),
                        // MUL-226-04: produtos distintos já vendidos pelo seller (todo o histórico)
                        DB::raw('(SELECT COUNT(DISTINCT oi.sku) FROM order_items oi JOIN orders o2 ON o2.id = oi.order_id WHERE o2.client_id = orders.client_id) as qtd_produtos')
                    )
                    ->with('client')
                    ->whereIn('status', ['paid', 'shipped', 'delivered'])
                    ->groupBy('client_id')
                    ->orderByDesc('total_pedidos')
            )
            ->columns([
                TextColumn::make('ranking')
                    ->label('#')
                    ->rowIndex(),

                // MUL-269 fase 2: client.company_name via accessor; busca no user conectado.
                TextColumn::make('client.company_name')
                    ->label('Seller')
                    ->searchable(query: fn ($query, $search) => $query->whereHas('client.user', fn ($u) => $u->where('name', 'like', "%{$search}%")->orWhere('full_name', 'like', "%{$search}%"))),

                // MUL-226-04: plano atual do seller
                TextColumn::make('plano')
                    ->label('Plano')
                    ->getStateUsing(function ($record) {
                        $sub = $record->client?->subscriptions()
                            ->whereIn('status', ['active', 'trialing'])
                            ->with('plan')
                            ->latest()
                            ->first();
                        return $sub?->plan?->name ?? 'Sem plano';
                    })
                    ->badge()
                    ->color(fn ($state) => $state === 'Sem plano' ? 'gray' : 'info'),

                TextColumn::make('total_pedidos')
                    ->label('Total de Pedidos')
                    ->badge()
                    ->color('primary')
                    ->sortable(),

                TextColumn::make('total_vendas')
                    ->label('Volume de Vendas')
                    ->money('BRL')
                    ->sortable(),

                // MUL-226-04: qtd de produtos distintos do seller
                TextColumn::make('qtd_produtos')
                    ->label('Qtd. Produtos')
                    ->tooltip('Produtos (SKUs) distintos já vendidos pelo seller — todo o histórico')
                    ->badge()
                    ->color('warning')
                    ->sortable(),

                // MUL-226-04: marketplaces sem duplicidade
                TextColumn::make('marketplaces')
                    ->label('Marketplaces')
                    ->formatStateUsing(function (?string $state) {
                        if (! $state) return '—';
                        $labels = array_map(fn ($s) => match (strtolower(trim($s))) {
                            'mercadolivre', 'mercado_livre' => 'Mercado Livre',
                            'shopee'                         => 'Shopee',
                            'amazon', 'amazon_fba', 'amazon_dba' => 'Amazon',
                            'magalu', 'magazineluiza'        => 'Magalu',
                            'tiktok', 'tiktok_shop', 'tiktokshop' => 'TikTok Shop',
                            'manual'                         => 'Manual',
                            default                          => trim($s),
                        }, explode(',', $state));
                        return implode(' · ', array_unique($labels));
                    })
                    ->wrap(),

                // MUL-226-04: canal de envio
                TextColumn::make('canais_envio')
                    ->label('Canal de Envio')
                    ->formatStateUsing(fn (?string $state) => $state ? implode(' · ', array_unique(array_map('trim', explode(',', $state)))) : '—')
                    ->placeholder('—')
                    ->wrap(),
            ])
            ->filters([
                // MUL-226-04: períodos rápidos
                SelectFilter::make('periodo_rapido')
                    ->label('Período rápido')
                    ->options(self::quickPeriodOptions())
                    ->query(fn (Builder $query, array $data): Builder => $data['value']
                        ? self::applyQuickPeriod($query, $data['value'])
                        : $query),

                Filter::make('periodo')
                    ->label('Período personalizado')
                    ->form([
                        DatePicker::make('data_inicio')->label('De')->displayFormat('d/m/Y'),
                        DatePicker::make('data_fim')->label('Até')->displayFormat('d/m/Y'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['data_inicio'], fn($q, $val) => $q->whereDate('created_at', '>=', $val))
                            ->when($data['data_fim'],   fn($q, $val) => $q->whereDate('created_at', '<=', $val));
                    }),

                // MUL-226-04: filtro por marketplace
                SelectFilter::make('marketplace')
                    ->label('Marketplace')
                    ->options([
                        'mercadolivre' => 'Mercado Livre',
                        'shopee'       => 'Shopee',
                        'amazon'       => 'Amazon',
                        'magalu'       => 'Magalu',
                        'tiktok_shop'  => 'TikTok Shop',
                        'manual'       => 'Manual',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $data['value']
                        ? $query->where('source', $data['value'])
                        : $query),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(3);
    }
}
