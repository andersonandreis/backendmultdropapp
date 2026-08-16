<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InventoryResource\Pages;
use App\Models\Inventory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InventoryResource extends Resource
{
    protected static ?string $model = Inventory::class;
    protected static ?string $slug = 'estoque';
    protected static ?string $modelLabel = 'Estoque';
    protected static ?string $pluralModelLabel = 'Estoques';

    protected static ?string $navigationIcon = 'heroicon-o-archive-box';
    protected static ?string $navigationGroup = 'Catálogo & Produtos';
    protected static ?int $navigationSort = 6;

    // Campos da tabela `inventory`:
    // warehouse_id (NOT NULL), product_id (NOT NULL), producer_id (NOT NULL),
    // quantity (NOT NULL, default 0), reserved (NOT NULL, default 0), warehouse_price (nullable)

    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'supplier']);
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->role === 'super_admin';
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return auth()->user()?->role === 'super_admin';
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return auth()->user()?->role === 'super_admin';
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = parent::getEloquentQuery();
        if (auth()->user()?->role === 'supplier') {
            $supplierId = auth()->user()->supplier?->id;
            if ($supplierId) {
                $query->where(function ($q) use ($supplierId) {
                    $q->where('producer_id', $supplierId)
                      ->orWhere('warehouse_id', $supplierId);
                });
            }
        }
        return $query;
    }
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('product_id')
                    ->relationship('product', 'name')
                    ->required()
                    ->searchable()
                    ->label('Produto'),
                Forms\Components\Select::make('warehouse_id')
                    ->relationship('warehouse', 'company_name')
                    ->required()
                    ->searchable()
                    ->label('Armazém (Galpão)'),
                Forms\Components\Select::make('producer_id')
                    ->relationship('producer', 'company_name')
                    ->required()
                    ->searchable()
                    ->label('Produtor / Fornecedor'),
                Forms\Components\TextInput::make('quantity')
                    ->required()
                    ->numeric()
                    ->default(0)
                    ->label('Quantidade em Estoque'),
                Forms\Components\TextInput::make('reserved')
                    ->required()
                    ->numeric()
                    ->default(0)
                    ->label('Quantidade Reservada'),
                Forms\Components\TextInput::make('stock_alert_threshold')
                    ->numeric()
                    ->minValue(0)
                    ->label('Alerta de Estoque Mínimo')
                    ->helperText('NOV-118: notifica quando quantidade ficar abaixo deste valor (deixe vazio para desativar).'),
                Forms\Components\TextInput::make('warehouse_price')
                    ->numeric()
                    ->prefix('R$')
                    ->label('Preço de Armazém')
                    ->helperText('Opcional. Custo cobrado pelo armazém por unidade.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('product.name')
                    ->label('Produto')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('warehouse.company_name')
                    ->label('Armazém')
                    ->searchable(),
                Tables\Columns\TextColumn::make('producer.company_name')
                    ->label('Produtor')
                    ->searchable(),
                Tables\Columns\TextColumn::make('quantity')
                    ->label('Estoque')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('reserved')
                    ->label('Reservado')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('available')
                    ->label('Disponível')
                    ->numeric()
                    ->state(fn($record) => $record->quantity - $record->reserved),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Atualizado em')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
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
            'index' => Pages\ListInventories::route('/'),
            'create' => Pages\CreateInventory::route('/create'),
            'edit' => Pages\EditInventory::route('/{record}/edit'),
        ];
    }
}
