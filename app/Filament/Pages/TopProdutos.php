<?php

namespace App\Filament\Pages;

use App\Models\OrderItem;
use Filament\Actions;
use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Forms\Components\DatePicker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class TopProdutos extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-trophy';
    protected static ?string $navigationGroup = 'Análises';
    protected static ?string $navigationLabel = 'Top Produtos';
    protected static ?string $title = 'Ranking — Top Produtos Vendidos';
    protected static ?string $slug = 'top-produtos';
    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.top-produtos';

    // MUL-226-05: toggle Lista/Grade (mesmos dados nos dois modos)
    public bool $isGridLayout = false;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('toggleGrid')
                ->label(fn() => $this->isGridLayout ? 'Ver em Lista' : 'Ver em Grade')
                ->icon(fn() => $this->isGridLayout ? 'heroicon-o-list-bullet' : 'heroicon-o-squares-2x2')
                ->color('gray')
                ->action(function () {
                    $this->isGridLayout = !$this->isGridLayout;
                }),
        ];
    }

    public function getTableRecordKey(\Illuminate\Database\Eloquent\Model $record): string
    {
        return (string) ($record->grupo ?? $record->getKey() ?? uniqid());
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                // MUL-226-05: agrupa por PRODUTO (product_id; fallback sku quando item sem vínculo).
                // Antes agrupava por sku+product_id — o mesmo produto aparecia N vezes,
                // uma por SKU/anúncio de cada seller (contaminação de lojas no ranking).
                OrderItem::query()
                    ->select(
                        DB::raw('COALESCE(order_items.product_id, order_items.sku) as grupo'),
                        DB::raw('MAX(order_items.product_id) as product_id'),
                        DB::raw('MAX(order_items.sku) as sku'),
                        DB::raw('MAX(order_items.name) as item_name'),
                        DB::raw('SUM(order_items.quantity) as total_vendido'),
                        DB::raw('SUM(order_items.unit_price * order_items.quantity) as total_receita'),
                        DB::raw('COUNT(DISTINCT orders.client_id) as qtd_sellers')
                    )
                    ->with('product.media')
                    ->join('orders', 'order_items.order_id', '=', 'orders.id')
                    ->whereIn('orders.status', ['paid', 'shipped', 'delivered'])
                    // MUL-226-05: item sem product_id E sem SKU não é rankeável
                    ->where(function ($q) {
                        $q->whereNotNull('order_items.product_id')
                          ->orWhere(fn ($qq) => $qq->whereNotNull('order_items.sku')->where('order_items.sku', '!=', ''));
                    })
                    ->groupBy('grupo')
                    ->orderByDesc('total_vendido')
            )
            ->columns($this->isGridLayout ? $this->getGridColumns() : $this->getListColumns())
            ->contentGrid($this->isGridLayout ? ['md' => 2, 'lg' => 3, '2xl' => 4] : null)
            ->filters([
                // MUL-226-05: períodos rápidos (mesmo padrão do Top Sellers)
                SelectFilter::make('periodo_rapido')
                    ->label('Período rápido')
                    ->options(TopLojas::quickPeriodOptions())
                    ->query(fn (Builder $query, array $data): Builder => $data['value']
                        ? TopLojas::applyQuickPeriod($query, $data['value'], 'orders.created_at')
                        : $query),

                Filter::make('periodo')
                    ->label('Período personalizado')
                    ->form([
                        DatePicker::make('data_inicio')->label('De')->displayFormat('d/m/Y'),
                        DatePicker::make('data_fim')->label('Até')->displayFormat('d/m/Y'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['data_inicio'], fn($q, $val) => $q->whereDate('orders.created_at', '>=', $val))
                            ->when($data['data_fim'],   fn($q, $val) => $q->whereDate('orders.created_at', '<=', $val));
                    }),

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
                        ? $query->where('orders.source', $data['value'])
                        : $query),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(3);
    }

    protected function getListColumns(): array
    {
        return [
            TextColumn::make('ranking')
                ->label('#')
                ->rowIndex(),

            ImageColumn::make('produto_foto')
                ->label('Foto')
                ->getStateUsing(fn ($record) => self::resolveProductImage($record))
                ->defaultImageUrl(fn ($record) => self::fallbackImage($record))
                ->size(48)
                ->square(),

            TextColumn::make('sku')
                ->label('SKU')
                ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('order_items.sku', 'like', "%{$search}%")),

            TextColumn::make('produto_nome')
                ->label('Produto')
                ->getStateUsing(fn ($record) => $record->product?->name ?? $record->item_name ?? '—')
                ->limit(60)
                ->tooltip(fn ($record) => $record->product?->name ?? $record->item_name)
                ->wrap(),

            TextColumn::make('total_vendido')
                ->label('Unidades Vendidas')
                ->badge()
                ->color('success')
                ->sortable(),

            TextColumn::make('total_receita')
                ->label('Receita Total')
                ->money('BRL')
                ->sortable(),

            // MUL-226-05: transparência do agrupamento por produto
            TextColumn::make('qtd_sellers')
                ->label('Sellers')
                ->tooltip('Quantos sellers venderam este produto no período')
                ->badge()
                ->color('info')
                ->sortable(),
        ];
    }

    protected function getGridColumns(): array
    {
        return [
            Stack::make([
                ImageColumn::make('produto_foto')
                    ->label('')
                    ->getStateUsing(fn ($record) => self::resolveProductImage($record))
                    ->defaultImageUrl(fn ($record) => self::fallbackImage($record))
                    ->height(140)
                    ->extraImgAttributes(['style' => 'width:100%;object-fit:cover;border-radius:10px;']),

                TextColumn::make('produto_nome')
                    ->label('Produto')
                    ->getStateUsing(fn ($record) => $record->product?->name ?? $record->item_name ?? '—')
                    ->weight('bold')
                    ->limit(60)
                    ->wrap(),

                TextColumn::make('sku')
                    ->label('SKU')
                    ->formatStateUsing(fn ($state) => '#' . $state)
                    ->color('gray')
                    ->size(TextColumn\TextColumnSize::ExtraSmall),

                Split::make([
                    TextColumn::make('total_vendido')
                        ->label('Unidades')
                        ->badge()
                        ->formatStateUsing(fn ($state) => "{$state} un.")
                        ->color('success'),

                    TextColumn::make('total_receita')
                        ->label('Receita')
                        ->money('BRL')
                        ->weight('bold')
                        ->color('primary'),
                ]),
            ])->space(2),
        ];
    }

    protected static function resolveProductImage($record): ?string
    {
        $media = $record->product?->media
            ?->where('type', 'image')
            ->sortBy([['is_cover', 'desc'], ['position', 'asc']])
            ->first();
        if (! $media) {
            $media = $record->product?->media?->first();
        }
        $url = $media?->url;
        if ($url && !str_starts_with($url, 'http') && str_starts_with($url, '/storage/')) {
            $url = asset($url);
        }
        return $url;
    }

    protected static function fallbackImage($record): string
    {
        return 'https://ui-avatars.com/api/?name=' . urlencode(substr((string) ($record->sku ?? '?'), 0, 2)) . '&background=1e293b&color=94a3b8&size=160';
    }
}
