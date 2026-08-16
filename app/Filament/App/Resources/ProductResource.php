<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\ProductResource\Pages;
use App\Filament\App\Resources\ProductResource\RelationManagers;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;
    protected static ?string $slug = 'produtos';

    protected static ?string $modelLabel = 'Meu Catálogo (Estoque Próprio)';
    protected static ?string $pluralModelLabel = 'Meus Produtos (Mestres)';
    protected static ?int $navigationSort = 3;


    public static function canCreate(): bool { return false; }
    public static function canEdit($record): bool { return false; }
    public static function canDelete($record): bool { return false; }

    public static function shouldRegisterNavigation(): bool
    {
        $user = auth()->user();
        if (!$user)
            return false;
        $clientId = $user->client_id ?? ($user->client->id ?? null);
        return \App\Models\Supplier::where('owner_client_id', $clientId)->exists();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();
        $clientId = $user->client_id ?? ($user->client->id ?? null);
        $privateSupplierId = \App\Models\Supplier::where('owner_client_id', $clientId)->value('id');

        // Filtra para mostrar somente os produtos criados neste sub-banco
        $query->where('supplier_id', $privateSupplierId);

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Hidden::make('supplier_id')
                    ->default(function () {
                        $user = auth()->user();
                        $clientId = $user->client_id ?? ($user->client->id ?? null);
                        return \App\Models\Supplier::where('owner_client_id', $clientId)->value('id');
                    }),
                Forms\Components\TextInput::make('sku')
                    ->label('SKU')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Textarea::make('description')
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('price')
                    ->required()
                    ->numeric()
                    ->prefix('$'),
                Forms\Components\TextInput::make('cost')
                    ->required()
                    ->numeric()
                    ->prefix('$'),
                Forms\Components\TextInput::make('gtin')
                    ->maxLength(255)
                    ->default(null),
                Forms\Components\TextInput::make('brand')
                    ->maxLength(255)
                    ->default(null),
                Forms\Components\TextInput::make('model')
                    ->maxLength(255)
                    ->default(null),
                Forms\Components\TextInput::make('weight_kg')
                    ->numeric()
                    ->default(null),
                Forms\Components\TextInput::make('height_cm')
                    ->numeric()
                    ->default(null),
                Forms\Components\TextInput::make('width_cm')
                    ->numeric()
                    ->default(null),
                Forms\Components\TextInput::make('length_cm')
                    ->numeric()
                    ->default(null),
                Forms\Components\TextInput::make('category_id')
                    ->numeric()
                    ->default(null),
                Forms\Components\TextInput::make('condition')
                    ->required()
                    ->maxLength(255)
                    ->default('new'),
                Forms\Components\Textarea::make('attributes')
                    ->columnSpanFull(),
                Forms\Components\Section::make('Regras de Estoque e Segurança')
                    ->description('Configure o estoque fictício infinito e suas margens físicas.')
                    ->schema([
                        Forms\Components\TextInput::make('virtual_stock_qty')
                            ->label('Estoque Fictício (Virtual)')
                            ->numeric()
                            ->hint('Envia este número fixo. Deixe vazio para usar o Real.')
                            ->default(null),
                        Forms\Components\TextInput::make('safety_margin_stock')
                            ->label('Margem de Segurança')
                            ->numeric()
                            ->default(20)
                            ->hint('Abaixo deste saldo real, anula o fictício.'),
                        Forms\Components\TextInput::make('zero_out_margin_stock')
                            ->label('Margem de Ruptura (Zeragem)')
                            ->numeric()
                            ->default(10)
                            ->hint('Abaixo deste saldo real, declara Estoque ZERO nas lojas.'),
                    ])->columns(3),
                Forms\Components\Toggle::make('is_active')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // -- COLUNAS DO MODO LISTA TRADICIONAL --
                Tables\Columns\ImageColumn::make('media_list')
                    ->label('Imagem')
                    ->getStateUsing(fn(Product $record) => $record->media()->first()?->url)
                    ->size(40)
                    ->visible(fn(\Livewire\Component $livewire) => !($livewire->isGridLayout ?? false)),

                Tables\Columns\TextColumn::make('name')
                    ->label('Produto')
                    ->searchable()
                    ->limit(40)
                    ->visible(fn(\Livewire\Component $livewire) => !($livewire->isGridLayout ?? false)),

                Tables\Columns\TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->visible(fn(\Livewire\Component $livewire) => !($livewire->isGridLayout ?? false)),

                Tables\Columns\TextColumn::make('price')
                    ->label('Preço')
                    ->money('BRL')
                    ->sortable()
                    ->visible(fn(\Livewire\Component $livewire) => !($livewire->isGridLayout ?? false)),

                Tables\Columns\TextColumn::make('supplier.company_name')
                    ->label('Fornecedor')
                    ->visible(fn(\Livewire\Component $livewire) => (!($livewire->isGridLayout ?? false)) && (auth()->user()->is_admin ?? false)),

                // -- LAYOUT DO MODO GRID (CARDS) --
                Tables\Columns\Layout\Split::make([
                    Tables\Columns\ImageColumn::make('media_grid')
                        ->label('Imagem')
                        ->getStateUsing(fn(Product $record) => $record->media()->first()?->url)
                        ->size('100%')
                        ->extraImgAttributes([
                            'class' => 'object-cover w-full h-48 rounded-t-lg',
                        ]),
                    Tables\Columns\Layout\Stack::make([
                        Tables\Columns\TextColumn::make('name_grid')
                            ->getStateUsing(fn(Product $record) => $record->name)
                            ->searchable()
                            ->weight('bold')
                            ->size('lg')
                            ->limit(40),
                        Tables\Columns\TextColumn::make('sku_grid')
                            ->getStateUsing(fn(Product $record) => $record->sku)
                            ->label('SKU')
                            ->searchable()
                            ->color('gray')
                            ->size('sm'),
                        Tables\Columns\TextColumn::make('price_grid')
                            ->getStateUsing(fn(Product $record) => $record->price)
                            ->money('BRL')
                            ->weight('bold')
                            ->color('success'),
                        Tables\Columns\TextColumn::make('supplier_grid')
                            ->getStateUsing(fn(Product $record) => $record->supplier?->company_name)
                            ->label('Fornecedor')
                            ->size('sm')
                            ->color('gray')
                            ->visible(fn(): bool => auth()->user()->is_admin ?? false),
                    ])->space(2)->extraAttributes(['class' => 'p-4']),
                ])->visible(fn(\Livewire\Component $livewire) => ($livewire->isGridLayout ?? false)),
            ])
            ->contentGrid(fn(\Livewire\Component $livewire) => ($livewire->isGridLayout ?? false) ? [
                'md' => 2,
                'xl' => 3,
            ] : null)
            ->defaultPaginationPageOption(50)
            ->filters([
                Tables\Filters\SelectFilter::make('category_id')
                    ->label('Categoria')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('sort')
                    ->label('Ordenar por')
                    ->placeholder('Mais recentes')
                    ->options([
                        'recent'        => 'Mais recentes',
                        'best_selling'  => 'Mais vendidos',
                        'price_asc'     => 'Menor preço',
                        'price_desc'    => 'Maior preço',
                    ])
                    ->query(function ($query, array $data) {
                        $value = $data['value'] ?? null;
                        if (! $value) return;
                        // limpa qualquer order anterior
                        $query->getQuery()->orders = null;
                        match ($value) {
                            'recent'       => $query->orderBy('products.created_at', 'desc'),
                            'best_selling' => $query->withCount('clientProducts')->orderBy('client_products_count', 'desc'),
                            'price_asc'    => $query->orderBy('products.price', 'asc'),
                            'price_desc'   => $query->orderBy('products.price', 'desc'),
                            default        => null,
                        };
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\Action::make('cadastrar_na_loja')
                    ->label(function (\App\Models\Product $record) {
                        $client = auth()->user()?->client;
                        if (!$client) return 'Cadastrar na Minha Loja';
                        $exists = \App\Models\ClientProduct::where('client_id', $client->id)
                            ->where('product_id', $record->id)->exists();
                        return $exists ? 'Ja Cadastrado' : 'Cadastrar na Minha Loja';
                    })
                    ->icon(function (\App\Models\Product $record) {
                        $client = auth()->user()?->client;
                        if (!$client) return 'heroicon-o-plus-circle';
                        $exists = \App\Models\ClientProduct::where('client_id', $client->id)
                            ->where('product_id', $record->id)->exists();
                        return $exists ? 'heroicon-o-check-circle' : 'heroicon-o-plus-circle';
                    })
                    ->color(function (\App\Models\Product $record) {
                        $client = auth()->user()?->client;
                        if (!$client) return 'success';
                        $exists = \App\Models\ClientProduct::where('client_id', $client->id)
                            ->where('product_id', $record->id)->exists();
                        return $exists ? 'gray' : 'success';
                    })
                    ->disabled(function (\App\Models\Product $record) {
                        $client = auth()->user()?->client;
                        if (!$client) return true;
                        return \App\Models\ClientProduct::where('client_id', $client->id)
                            ->where('product_id', $record->id)->exists();
                    })
                    ->action(function (\App\Models\Product $record) {
                        $client = auth()->user()?->client;
                        if (!$client) {
                            \Filament\Notifications\Notification::make()
                                ->title('Erro: perfil de cliente nao localizado.')
                                ->danger()->send();
                            return;
                        }
                        $already = \App\Models\ClientProduct::where('client_id', $client->id)
                            ->where('product_id', $record->id)->exists();
                        if ($already) {
                            \Filament\Notifications\Notification::make()
                                ->title('Produto ja esta no seu sub-catalogo.')
                                ->warning()->send();
                            return;
                        }
                        \App\Models\ClientProduct::create([
                            'client_id'    => $client->id,
                            'product_id'   => $record->id,
                            'custom_title' => null,        // user personaliza depois (era $record->name antes)
                            'custom_description' => null,  // idem
                            'custom_price' => $record->price,
                            'pricing_mode' => 'manual',
                            'sync_status'  => 'pending',
                            'is_active'    => true,
                            'listing_status' => 'draft',
                        ]);
                        \Filament\Notifications\Notification::make()
                            ->title('Produto cadastrado no seu sub-catalogo!')
                            ->body('Acesse "Meus Produtos" para configurar e publicar.')
                            ->success()->send();
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
