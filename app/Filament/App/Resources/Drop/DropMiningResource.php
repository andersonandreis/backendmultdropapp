<?php

namespace App\Filament\App\Resources\Drop;

use App\Filament\App\Resources\Drop\DropMiningResource\Pages;
use App\Models\Drop\SupplierProduct;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Http;

class DropMiningResource extends Resource
{
    protected static ?string $model = SupplierProduct::class;
    protected static ?string $slug = 'drop/mineracao';
    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass';
    protected static ?string $navigationGroup = 'Drop Internacional';
    protected static bool $shouldRegisterNavigation = false;
    protected static ?int $navigationSort = 5;
    protected static ?string $navigationLabel = 'Mineracao de Produtos';
    protected static ?string $modelLabel = 'Produto Minerado';
    protected static ?string $pluralModelLabel = 'Mineracao de Produtos';

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
            $plan = $user->client->subscription?->plan ?? null;
            if ($plan && isset($plan->has_drop_internacional) && !$plan->has_drop_internacional) {
                Notification::make()
                    ->title('Modulo Drop Internacional')
                    ->body('Seu plano atual nao inclui o modulo Drop Internacional. Fale com o suporte para fazer upgrade.')
                    ->warning()
                    ->persistent()
                    ->send();
            }
            return $query->where('client_id', $clientId);
        }

        return $query->whereRaw('1 = 0');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Produto')
                    ->searchable()
                    ->limit(60)
                    ->sortable(),
                Tables\Columns\TextColumn::make('supplier_slug')
                    ->label('Fornecedor')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'cj'         => 'primary',
                        'aliexpress' => 'warning',
                        'zendrop'    => 'success',
                        default      => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'cj'         => 'CJ Dropshipping',
                        'aliexpress' => 'AliExpress',
                        'zendrop'    => 'Zendrop',
                        default      => $state,
                    }),
                Tables\Columns\TextColumn::make('cost_usd')
                    ->label('Custo')
                    ->formatStateUsing(fn ($state) => '$ ' . number_format((float) $state, 2))
                    ->sortable(),
                Tables\Columns\TextColumn::make('shipping_usd')
                    ->label('Frete')
                    ->formatStateUsing(fn ($state) => '$ ' . number_format((float) $state, 2))
                    ->sortable(),
                Tables\Columns\TextColumn::make('estimated_days')
                    ->label('Prazo')
                    ->formatStateUsing(fn ($state) => $state . ' dias')
                    ->sortable(),
                Tables\Columns\TextColumn::make('rating')
                    ->label('Avaliacao')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 1) . ' estrelas')
                    ->sortable(),
                Tables\Columns\TextColumn::make('sales_count')
                    ->label('Vendas')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('risk_score')
                    ->label('Risco')
                    ->badge()
                    ->color(fn ($state): string => match (true) {
                        (int) $state === 0 => 'success',
                        (int) $state <= 3  => 'warning',
                        default            => 'danger',
                    })
                    ->formatStateUsing(fn ($state): string => match (true) {
                        (int) $state === 0 => 'Baixo',
                        (int) $state <= 3  => 'Medio',
                        default            => 'Alto',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('last_fetched_at')
                    ->label('Atualizado')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('supplier_slug')
                    ->label('Fornecedor')
                    ->options([
                        'cj'         => 'CJ Dropshipping',
                        'aliexpress' => 'AliExpress',
                        'zendrop'    => 'Zendrop',
                    ]),
            ])
            ->headerActions([
                Tables\Actions\Action::make('buscar_produtos')
                    ->label('Buscar Produtos')
                    ->icon('heroicon-o-magnifying-glass')
                    ->color('primary')
                    ->slideOver()
                    ->form([
                        Forms\Components\Select::make('supplier_slug')
                            ->label('Fornecedor')
                            ->options([
                                'cj'         => 'CJ Dropshipping',
                                'aliexpress' => 'AliExpress',
                                'zendrop'    => 'Zendrop',
                            ])
                            ->required(),
                        Forms\Components\TextInput::make('keyword')
                            ->label('Palavra-chave')
                            ->placeholder('Ex: wireless earbuds')
                            ->required(),
                        Forms\Components\TextInput::make('category')
                            ->label('Categoria (opcional)')
                            ->placeholder('Ex: Electronics')
                            ->nullable(),
                    ])
                    ->action(function (array $data) {
                        try {
                            $response = Http::timeout(30)
                                ->post(config('app.url') . '/api/v1/drop/mining/search', [
                                    'supplier_slug' => $data['supplier_slug'],
                                    'keyword'       => $data['keyword'],
                                    'category'      => $data['category'] ?? null,
                                ]);

                            if ($response->successful()) {
                                Notification::make()
                                    ->title('Busca iniciada!')
                                    ->body('Os resultados aparecerao na tabela em instantes.')
                                    ->success()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('Erro na busca')
                                    ->body($response->json('message') ?? 'Tente novamente.')
                                    ->danger()
                                    ->send();
                            }
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Erro ao buscar produtos')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('importar_produto')
                    ->label('Importar Produto')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->form(function () {
                        $user  = auth()->user();
                        $lojas = [];

                        if ($user) {
                            $clientId = $user->role === 'super_admin' ? null : $user->client?->id;
                            $q = \App\Models\Drop\DropStore::where('status', 'active');
                            if ($clientId) {
                                $q->where('client_id', $clientId);
                            }
                            $lojas = $q->pluck('shop_domain', 'id')->toArray();
                        }

                        return [
                            Forms\Components\Select::make('drop_store_id')
                                ->label('Loja de Destino')
                                ->options($lojas)
                                ->required()
                                ->placeholder('Selecione a loja')
                                ->helperText('Somente lojas ativas aparecem aqui.'),
                        ];
                    })
                    ->action(function (SupplierProduct $record, array $data) {
                        try {
                            $response = Http::timeout(30)
                                ->post(config('app.url') . '/api/v1/drop/mining/import', [
                                    'supplier_product_id' => $record->id,
                                    'drop_store_id'       => $data['drop_store_id'],
                                ]);

                            if ($response->successful()) {
                                Notification::make()
                                    ->title('Produto importado!')
                                    ->body('O produto foi importado para a loja selecionada.')
                                    ->success()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('Erro ao importar')
                                    ->body($response->json('message') ?? 'Tente novamente.')
                                    ->danger()
                                    ->send();
                            }
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Erro ao importar produto')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([]),
            ])
            ->defaultSort('last_fetched_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSupplierProducts::route('/'),
        ];
    }
}
