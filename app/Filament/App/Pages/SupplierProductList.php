<?php

namespace App\Filament\App\Pages;

use Filament\Pages\Page;
use App\Models\Product;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Database\Eloquent\Collection;
use App\Models\ClientProduct;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;

class SupplierProductList extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $slug = 'produtos-do-fornecedor';
    protected static ?string $title = 'Produtos do Fornecedor';

    // Esconde do menu lateral. Só é acessível pelo clique na Vitrine.
    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.app.pages.supplier-product-list';

    #[\Livewire\Attributes\Url]
    public ?string $supplier = null;

    public bool $isGridLayout = true;

    public function getTitle(): string
    {
        $supplierId = $this->supplier ?? request()->query('supplier');
        if ($supplierId) {
            $supplier = Supplier::find($supplierId);
            if ($supplier) {
                return "Produtos - " . $supplier->company_name;
            }
        }
        return 'Catálogo do Fornecedor';
    }

    public function table(Table $table): Table
    {
        $supplierId = $this->supplier ?? request()->query('supplier');

        $user = auth()->user();
        $clientId = $user->client_id ?? ($user->client->id ?? null);
        return $table
            ->selectCurrentPageOnly()
            ->heading(new \Illuminate\Support\HtmlString(view('filament.components.nexus-search-heading')->render()))
            ->query(
                Product::with(['variations', 'media', 'supplier'])
                    ->where('is_active', true)
                    ->where(function($q) use ($supplierId) { $q->where('supplier_id', $supplierId)->orWhereNull('supplier_id'); })
            )
            ->contentGrid(fn() => $this->isGridLayout ? [
                'md' => 2,
                'lg' => 3,
                'xl' => 4,
            ] : null)
            ->defaultPaginationPageOption(50)
            ->headerActions([
                \Filament\Tables\Actions\Action::make('toggleGrid')
                    ->label(fn() => $this->isGridLayout ? 'Ver em Lista' : 'Ver em Grade')
                    ->hiddenLabel()
                    ->tooltip(fn() => $this->isGridLayout ? 'Tabela' : 'Cards')
                    ->icon(fn() => $this->isGridLayout ? 'heroicon-m-list-bullet' : 'heroicon-m-squares-2x2')
                    ->color('gray')
                    ->action(function () {
                        $this->isGridLayout = !$this->isGridLayout;
                    }),
            ])
            ->columns([
                // -- MODO LISTA TRADICIONAL --
                ImageColumn::make('media_list')
                    ->label('Imagem')
                    ->getStateUsing(fn(Product $record) => $record->media->first() ? asset($record->media->first()->url) : null)
                    ->disk(null)
                    ->size(50)
                    ->extraImgAttributes(['class' => 'rounded-md'])
                    ->visible(fn() => !$this->isGridLayout),

                TextColumn::make('name_list')
                    ->getStateUsing(fn(Product $record) => $record->name)
                    ->label('Produto')
                    ->sortable(['name'])
                    ->weight('bold')
                    ->description(fn(Product $record) => '#' . $record->sku)
                    ->limit(40)
                    ->visible(fn() => !$this->isGridLayout),

                TextColumn::make('price_list')
                    ->getStateUsing(fn(Product $record) => $record->price)
                    ->label('Preço Base')
                    ->money('BRL')
                    ->sortable(['price'])
                    ->weight('bold')
                    ->color('success')
                    ->visible(fn() => !$this->isGridLayout),

                // -- MODO GRID (CARDS PREMIUM NEXUS) --
                // grid_card removed in favor of content override
            ])
            ->filters([
                \Filament\Tables\Filters\Filter::make('custom_search')
                    ->form([
                        \Filament\Forms\Components\Hidden::make('search_by')->default('name'),
                        \Filament\Forms\Components\Hidden::make('search_term'),
                    ])
                    ->query(function (\Illuminate\Database\Eloquent\Builder $query, array $data): \Illuminate\Database\Eloquent\Builder {
                        if (!empty($data['search_term']) && !empty($data['search_by'])) {
                            $term = '%' . trim($data['search_term']) . '%';
                            $query->where($data['search_by'], 'like', $term);
                        }
                        return $query;
                    }),
                \Filament\Tables\Filters\Filter::make('integration_status')
                    ->form(function () use ($clientId, $supplierId) {
                        return [
                            \Filament\Forms\Components\Select::make('store_id')
                                ->label('Minha Loja')
                                ->options(
                                    \App\Models\MarketplaceAccount::where('client_id', $clientId)
                                        ->where(function($q) use ($supplierId) { $q->where('supplier_id', $supplierId)->orWhereNull('supplier_id'); })->pluck('account_name', 'id')
                                )
                                ->placeholder('Todas as lojas...'),
                            \Filament\Forms\Components\Select::make('status')
                                ->label('Filtrar Status')
                                ->options([
                                    'not_integrated' => 'Apenas Não Cadastrados',
                                    'integrated' => 'Já Cadastrados na Loja',
                                ])
                                ->placeholder('Todos...'),
                        ];
                    })
                    ->query(function (Builder $query, array $data): Builder {
                        if (!empty($data['store_id']) && !empty($data['status'])) {
                            if ($data['status'] === 'integrated') {
                                $query->whereHas('clientProducts', function ($q) use ($data) {
                                    $q->where('marketplace_account_id', $data['store_id']);
                                });
                            } elseif ($data['status'] === 'not_integrated') {
                                $query->whereDoesntHave('clientProducts', function ($q) use ($data) {
                                    $q->where('marketplace_account_id', $data['store_id']);
                                });
                            }
                        }
                        return $query;
                    }),
            ])
            ->content(fn() => $this->isGridLayout ? view('filament.components.product-grid-body') : null)
            ->filtersLayout(\Filament\Tables\Enums\FiltersLayout::Dropdown)
            ->actions([
                // Botão de Ver Detalhes Rico
                \Filament\Tables\Actions\ViewAction::make('view_details')
                    ->label('Detalhes')
                    ->icon('heroicon-m-eye')
                    ->color('info')
                    ->modalHeading('Ficha Técnica do Produto')
                    ->modalWidth('5xl')
                    ->infolist([
                        \Filament\Infolists\Components\Grid::make(12)
                            ->schema([
                                \Filament\Infolists\Components\Section::make('Imagens')
                                    ->schema([
                                        \Filament\Infolists\Components\ViewEntry::make('gallery')
                                            ->label('')
                                            ->view('filament.components.product-carousel')
                                            ->getStateUsing(fn(Product $record) => $record->media()->pluck('url')->toArray())
                                    ])
                                    ->columnSpan(['default' => 12, 'md' => 5]),

                                \Filament\Infolists\Components\Section::make('Dados Principais')
                                    ->schema([
                                        \Filament\Infolists\Components\TextEntry::make('name')->label('Produto')->weight('bold')->size('lg')->columnSpanFull(),
                                        \Filament\Infolists\Components\TextEntry::make('sku')->label('SKU Base')->badge(),
                                        \Filament\Infolists\Components\TextEntry::make('price')->label('Preço Base')->money('BRL')->color('success')->weight('bold')->size('xl'),
                                        \Filament\Infolists\Components\TextEntry::make('brand')->label('Marca'),
                                        \Filament\Infolists\Components\TextEntry::make('description')->label('Descrição')->html()->columnSpanFull(),
                                    ])
                                    ->columns(2)
                                    ->columnSpan(['default' => 12, 'md' => 7]),
                            ])
                    ])
                    ->extraModalFooterActions(function (Product $record) use ($clientId, $supplierId) {
                        return [
                            \Filament\Tables\Actions\Action::make('import_inside_modal')
                                ->label('Importar para Loja')
                                ->icon('heroicon-m-plus-circle')
                                ->color('success')
                                ->form([
                                    \Filament\Forms\Components\CheckboxList::make('marketplace_account_ids')
                                        ->label('Lojas de Destino (marque uma ou mais)')
                                        ->options(
                                            \App\Models\MarketplaceAccount::where('client_id', $clientId)
                                                ->where(function($q) use ($supplierId) { $q->where('supplier_id', $supplierId)->orWhereNull('supplier_id'); })
                                                ->pluck('account_name', 'id')
                                        )
                                        ->required()
                                ])
                                ->action(function (array $data) use ($record) {
                                    $accountIds = array_filter((array) ($data['marketplace_account_ids'] ?? []));
                                    $cloneService = new \App\Services\ProductCloneService(); $count = 0;
                                    foreach ($accountIds as $accountId) {
                                        $account = \App\Models\MarketplaceAccount::find($accountId);
                                        if (!$account) continue;
                                        if (ClientProduct::where('marketplace_account_id', $accountId)->where('product_id', $record->id)->exists()) continue;
                                        $cloneService->cloneToAccount($record, $account); $count++;
                                    }
                                    \Filament\Notifications\Notification::make()->title($count > 0 ? "Importado para {$count} loja(s)!" : 'Já existia em todas.')->color($count > 0 ? 'success' : 'warning')->send();
                                }),
                        ];
                    }),

                // Botão de Importar Direto na Tabela/Card
                Action::make('add_to_catalog')
                    ->label('Importar')
                    ->icon('heroicon-m-plus-circle')
                    ->color('success')
                    ->form(function (Product $record) use ($clientId, $supplierId) {
                        return [
                            \Filament\Forms\Components\CheckboxList::make('marketplace_account_ids')
                                ->label('Lojas de Destino (marque uma ou mais)')
                                ->options(function () use ($clientId, $supplierId) {
                                    return \App\Models\MarketplaceAccount::where('client_id', $clientId)
                                        ->where(function($q) use ($supplierId) { $q->where('supplier_id', $supplierId)->orWhereNull('supplier_id'); })
                                        ->get()
                                        ->mapWithKeys(function ($acc) {
                                            $platform = ucfirst($acc->platform);
                                            return [$acc->id => "{$acc->account_name} ({$platform})"];
                                        });
                                })
                                ->required(),
                        ];
                    })
                    ->action(function (array $data, Product $record) {
                        $accountIds = array_filter((array) ($data['marketplace_account_ids'] ?? []));
                        $cloneService = new \App\Services\ProductCloneService(); $count = 0;
                        foreach ($accountIds as $accountId) {
                            $account = \App\Models\MarketplaceAccount::find($accountId);
                            if (!$account) continue;
                            if (ClientProduct::where('marketplace_account_id', $accountId)->where('product_id', $record->id)->exists()) continue;
                            $cloneService->cloneToAccount($record, $account); $count++;
                        }
                        \Filament\Notifications\Notification::make()->title($count > 0 ? "Importado para {$count} marketplace(s)!" : 'Já existia em todos os selecionados.')->color($count > 0 ? 'success' : 'warning')->send();
                    })
                    ->modalHeading('Importar Produto')
                    ->modalDescription('Selecione para qual das suas lojas este produto será copiado.'),
            ])
            ->bulkActions([
                BulkAction::make('add_batch_to_catalog')
                    ->label('Importar Selecionados')
                    ->icon('heroicon-m-plus-circle')
                    ->color('success')
                    ->form([
                        \Filament\Forms\Components\CheckboxList::make('marketplace_account_ids')
                            ->label('Lojas de Destino (marque uma ou mais)')
                            ->options(function () use ($clientId, $supplierId) {
                                return \App\Models\MarketplaceAccount::where('client_id', $clientId)
                                    ->where(function($q) use ($supplierId) { $q->where('supplier_id', $supplierId)->orWhereNull('supplier_id'); })
                                    ->get()
                                    ->mapWithKeys(function ($acc) {
                                        $platform = ucfirst($acc->platform);
                                        return [$acc->id => "{$acc->account_name} ({$platform})"];
                                    });
                            })
                            ->required()
                    ])
                    ->action(function (array $data, Collection $records) {
                        $accountIds = array_filter((array) ($data['marketplace_account_ids'] ?? []));
                        $cloneService = new \App\Services\ProductCloneService();
                        $clonedCount = 0;

                        foreach ($records as $record) {
                            foreach ($accountIds as $accountId) {
                                $account = \App\Models\MarketplaceAccount::find($accountId);
                                if (!$account) continue;
                                if (!ClientProduct::where('marketplace_account_id', $accountId)->where('product_id', $record->id)->exists()) {
                                    $cloneService->cloneToAccount($record, $account);
                                    $clonedCount++;
                                }
                            }
                        }

                        \Filament\Notifications\Notification::make()
                            ->title("Concluído: {$clonedCount} produtos importados.")
                            ->success()
                            ->send();
                    })->deselectRecordsAfterCompletion(),
            ]);
    }
}
