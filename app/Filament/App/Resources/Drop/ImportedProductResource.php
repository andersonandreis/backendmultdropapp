<?php

namespace App\Filament\App\Resources\Drop;

use App\Filament\App\Resources\Drop\ImportedProductResource\Pages;
use App\Models\Drop\DropStore;
use App\Models\Drop\ImportedProduct;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ImportedProductResource extends Resource
{
    protected static ?string $model = ImportedProduct::class;
    protected static ?string $slug = 'drop/produtos-importados';
    protected static ?string $navigationIcon = 'heroicon-o-cube';
    protected static ?string $navigationGroup = 'Drop Internacional';
    protected static bool $shouldRegisterNavigation = false;
    protected static ?int $navigationSort = 2;
    protected static ?string $navigationLabel = 'Produtos Importados';
    protected static ?string $modelLabel = 'Produto Importado';
    protected static ?string $pluralModelLabel = 'Produtos Importados';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user  = auth()->user();

        if (!$user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->role === 'super_admin') {
            return $query;
        }

        $clientId = $user->client?->id;
        if ($clientId) {
            return $query->where('client_id', $clientId);
        }

        return $query->whereRaw('1 = 0');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Produto')
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->label('Titulo')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('title_ai')
                            ->label('Titulo gerado por IA')
                            ->maxLength(255)
                            ->nullable(),
                        Forms\Components\Textarea::make('description_ai')
                            ->label('Descricao IA')
                            ->rows(4)
                            ->nullable()
                            ->columnSpanFull(),
                        Forms\Components\Select::make('supplier_slug')
                            ->label('Fornecedor')
                            ->options([
                                'aliexpress' => 'AliExpress',
                                'cj'         => 'CJ Dropshipping',
                                'zendrop'    => 'Zendrop',
                                'outro'      => 'Outro',
                            ])
                            ->required(),
                        Forms\Components\TextInput::make('external_supplier_id')
                            ->label('ID no Fornecedor')
                            ->nullable()
                            ->maxLength(255),
                    ])->columns(2),

                Forms\Components\Section::make('Precos')
                    ->schema([
                        Forms\Components\TextInput::make('cost_usd')
                            ->label('Custo (USD)')
                            ->numeric()
                            ->prefix('$')
                            ->required(),
                        Forms\Components\TextInput::make('shipping_usd')
                            ->label('Frete (USD)')
                            ->numeric()
                            ->prefix('$')
                            ->default(0),
                        Forms\Components\TextInput::make('sell_price')
                            ->label('Preco de Venda')
                            ->numeric()
                            ->prefix('$'),
                        Forms\Components\TextInput::make('markup_pct')
                            ->label('Markup')
                            ->numeric()
                            ->suffix('%'),
                        Forms\Components\TextInput::make('currency')
                            ->label('Moeda')
                            ->default('USD')
                            ->maxLength(10),
                    ])->columns(2),

                Forms\Components\Section::make('Status')
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->label('Status')
                            ->options([
                                'draft'     => 'Rascunho',
                                'published' => 'Publicado',
                                'archived'  => 'Arquivado',
                            ])
                            ->default('draft')
                            ->required(),
                        Forms\Components\Select::make('drop_store_id')
                            ->label('Loja Drop')
                            ->options(function () {
                                $user = auth()->user();
                                if (!$user) {
                                    return [];
                                }
                                if ($user->role === 'super_admin') {
                                    return DropStore::pluck('shop_domain', 'id');
                                }
                                $clientId = $user->client?->id;
                                if (!$clientId) {
                                    return [];
                                }
                                return DropStore::where('client_id', $clientId)
                                    ->pluck('shop_domain', 'id');
                            })
                            ->searchable()
                            ->nullable(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Titulo')
                    ->searchable()
                    ->limit(50)
                    ->tooltip(fn ($record) => $record->title),
                Tables\Columns\TextColumn::make('supplier_slug')
                    ->label('Fornecedor')
                    ->badge()
                    ->color('primary'),
                Tables\Columns\TextColumn::make('cost_usd')
                    ->label('Custo')
                    ->formatStateUsing(fn ($state) => '$ ' . number_format((float) $state, 2))
                    ->sortable(),
                Tables\Columns\TextColumn::make('shipping_usd')
                    ->label('Frete')
                    ->formatStateUsing(fn ($state) => '$ ' . number_format((float) $state, 2))
                    ->sortable(),
                Tables\Columns\TextColumn::make('sell_price')
                    ->label('Venda')
                    ->formatStateUsing(fn ($state) => '$ ' . number_format((float) $state, 2))
                    ->sortable(),
                Tables\Columns\TextColumn::make('markup_pct')
                    ->label('Markup')
                    ->suffix('%')
                    ->sortable(),
                Tables\Columns\TextColumn::make('margin_usd')
                    ->label('Margem')
                    ->formatStateUsing(fn ($state) => '$ ' . number_format((float) $state, 2))
                    ->color(fn ($state) => ((float) $state) > 0 ? 'success' : 'danger')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'draft'     => 'gray',
                        'published' => 'success',
                        'archived'  => 'warning',
                        default     => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'draft'     => 'Rascunho',
                        'published' => 'Publicado',
                        'archived'  => 'Arquivado',
                        default     => $state,
                    }),
                Tables\Columns\TextColumn::make('shopify_published_at')
                    ->label('Publicado em')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'draft'     => 'Rascunho',
                        'published' => 'Publicado',
                        'archived'  => 'Arquivado',
                    ]),
                Tables\Filters\SelectFilter::make('supplier_slug')
                    ->label('Fornecedor')
                    ->options([
                        'aliexpress' => 'AliExpress',
                        'cj'         => 'CJ Dropshipping',
                        'zendrop'    => 'Zendrop',
                        'outro'      => 'Outro',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->iconButton()
                    ->tooltip('Editar'),
                Tables\Actions\Action::make('publish_shopify')
                    ->label('Publicar na Shopify')
                    ->icon('heroicon-o-arrow-up-circle')
                    ->color('success')
                    ->visible(fn (ImportedProduct $record): bool => $record->status === 'draft')
                    ->requiresConfirmation()
                    ->modalHeading('Publicar produto na Shopify')
                    ->modalDescription('Deseja publicar este produto na sua loja Shopify?')
                    ->action(function (ImportedProduct $record) {
                        try {
                            $service = app(\App\Services\Drop\ShopifyProductService::class);
                            $result  = $service->publishProduct($record);
                            Notification::make()
                                ->title($result['success'] ? 'Produto publicado!' : 'Erro ao publicar')
                                ->body($result['message'] ?? '')
                                ->status($result['success'] ? 'success' : 'danger')
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Erro ao publicar produto')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Tables\Actions\DeleteAction::make()
                    ->iconButton()
                    ->tooltip('Excluir'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListImportedProducts::route('/'),
            'create' => Pages\CreateImportedProduct::route('/create'),
            'edit'   => Pages\EditImportedProduct::route('/{record}/edit'),
        ];
    }
}
