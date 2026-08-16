<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InventoryMovementResource\Pages;
use App\Models\InventoryMovement;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * NOV-115 — Resource somente leitura para histórico de movimentação de estoque.
 */
class InventoryMovementResource extends Resource
{
    protected static ?string $model = InventoryMovement::class;
    protected static ?string $slug = 'historico-estoque';
    protected static ?string $modelLabel = 'Movimentação';
    protected static ?string $pluralModelLabel = 'Histórico de Estoque';

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationGroup = 'Estoque & Remessas';
    protected static ?int $navigationSort = 5;

    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'supplier']);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
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
            ->query(InventoryMovement::query()->with(['product', 'user', 'inventory']))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Data')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('product.sku')
                    ->label('SKU')
                    ->searchable(),

                Tables\Columns\TextColumn::make('product.name')
                    ->label('Produto')
                    ->limit(40)
                    ->searchable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'entrada'           => 'success',
                        'devolucao'         => 'success',
                        'saida', 'venda'    => 'warning',
                        'ajuste'            => 'info',
                        'zerar'             => 'danger',
                        'sync_marketplace'  => 'gray',
                        default             => 'gray',
                    }),

                Tables\Columns\TextColumn::make('qty_before')->label('Antes')->numeric(),
                Tables\Columns\TextColumn::make('qty_change')
                    ->label('Δ')
                    ->numeric()
                    ->color(fn ($state) => $state >= 0 ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('qty_after')->label('Depois')->numeric(),

                Tables\Columns\TextColumn::make('marketplace')
                    ->label('MP')
                    ->badge()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('reference_type')
                    ->label('Origem')
                    ->formatStateUsing(fn ($state, $record) => $state
                        ? $state . ($record->reference_id ? '#' . $record->reference_id : '')
                        : '—'),

                Tables\Columns\TextColumn::make('user.name')->label('Usuário')->placeholder('—'),

                Tables\Columns\TextColumn::make('notes')->label('Motivo')->wrap()->limit(60),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('type')->options([
                    'entrada'          => 'Entrada',
                    'saida'            => 'Saída',
                    'ajuste'           => 'Ajuste',
                    'venda'            => 'Venda',
                    'devolucao'        => 'Devolução',
                    'zerar'            => 'Zerar',
                    'sync_marketplace' => 'Sync marketplace',
                ]),
                SelectFilter::make('marketplace')->options([
                    'ml'      => 'Mercado Livre',
                    'shopee'  => 'Shopee',
                    'bling'   => 'Bling',
                    'manual'  => 'Manual',
                ]),
                Filter::make('created_between')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')->label('De'),
                        \Filament\Forms\Components\DatePicker::make('to')->label('Até'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                            ->when($data['to']   ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d));
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInventoryMovements::route('/'),
        ];
    }
}
