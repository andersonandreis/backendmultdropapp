<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class KitItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'kitItems';

    protected static ?string $title = 'Itens do Kit';

    protected static ?string $modelLabel = 'Item do Kit';

    protected static ?string $pluralModelLabel = 'Itens do Kit';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('child_product_id')
                    ->label('Produto Filho')
                    ->options(function () {
                        $kitId = $this->getOwnerRecord()->id;
                        return Product::where('id', '!=', $kitId)
                            ->where('is_active', true)
                            ->pluck('name', 'id');
                    })
                    ->searchable()
                    ->required(),
                Forms\Components\TextInput::make('quantity')
                    ->label('Quantidade')
                    ->numeric()
                    ->default(1)
                    ->minValue(1)
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('child_product_id')
            ->columns([
                Tables\Columns\TextColumn::make('childProduct.name')
                    ->label('Produto')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('childProduct.sku')
                    ->label('SKU')
                    ->sortable(),
                Tables\Columns\TextColumn::make('quantity')
                    ->label('Qtd no Kit')
                    ->sortable(),
                Tables\Columns\TextColumn::make('child_stock')
                    ->label('Estoque Filho')
                    ->getStateUsing(fn ($record) => $record->childProduct->inventory()->sum('quantity')),
                Tables\Columns\TextColumn::make('kit_contribution')
                    ->label('Rende (kits)')
                    ->getStateUsing(function ($record) {
                        $childStock = $record->childProduct->inventory()->sum('quantity');
                        return $record->quantity > 0 ? intdiv($childStock, $record->quantity) : 0;
                    })
                    ->color('info'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('Adicionar Produto ao Kit'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
