<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\InventoryResource\Pages;
use App\Filament\App\Resources\InventoryResource\RelationManagers;
use App\Models\Inventory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class InventoryResource extends Resource
{
    protected static ?string $model = Inventory::class;
    protected static ?string $slug = 'estoque';
    protected static ?string $modelLabel = 'Meu Estoque Físico';
    protected static ?string $pluralModelLabel = 'Gestão de Estoques';
    protected static ?int $navigationSort = 3;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();
        $clientId = $user->client_id ?? ($user->client->id ?? null);
        $privateSupplierId = \App\Models\Supplier::where('owner_client_id', $clientId)->value('id');

        // Mostra somente os estoques atrelados aos produtos desse Fornecedor (Modo Híbrido)
        $query->whereHas('product', function ($q) use ($privateSupplierId) {
            $q->where('supplier_id', $privateSupplierId);
        });

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Hidden::make('warehouse_id')
                    ->default(function () {
                        // Neste modelo simplificado de Lojista=Fornecedor, o warehouse_id é o próprio Supplier ID para manter consistência
                        $user = auth()->user();
                        $clientId = $user->client_id ?? ($user->client->id ?? null);
                        return \App\Models\Supplier::where('owner_client_id', $clientId)->value('id');
                    }),
                Forms\Components\Hidden::make('producer_id')
                    ->default(0), // Fica 0 já que ele não é um produtor filiado
                Forms\Components\Select::make('product_id')
                    ->label('Produto')
                    ->options(function () {
                        $user = auth()->user();
                        $clientId = $user->client_id ?? ($user->client->id ?? null);
                        $privateSupplierId = \App\Models\Supplier::where('owner_client_id', $clientId)->value('id');
                        return \App\Models\Product::where('supplier_id', $privateSupplierId)->pluck('name', 'id');
                    })
                    ->searchable()
                    ->required(),
                Forms\Components\TextInput::make('quantity')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\TextInput::make('reserved')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\TextInput::make('warehouse_price')
                    ->numeric()
                    ->default(null),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('warehouse_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('product_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('producer_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('quantity')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('reserved')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('warehouse_price')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
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
