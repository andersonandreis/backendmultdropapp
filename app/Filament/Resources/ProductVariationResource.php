<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductVariationResource\Pages;
use App\Filament\Resources\ProductVariationResource\RelationManagers;
use App\Models\ProductVariation;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ProductVariationResource extends Resource
{
    protected static ?string $model = ProductVariation::class;
    protected static ?string $slug = 'variacoes-produto';
    // protected static ?string $modelLabel = '...';

    protected static ?string $navigationIcon = 'heroicon-o-squares-plus';
    protected static ?string $navigationGroup = 'Catálogo & Produtos';
    protected static ?string $navigationLabel = 'Variações';
    protected static ?int $navigationSort = 2;


    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'supplier']);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = parent::getEloquentQuery();
        if (auth()->user()?->role === 'supplier') {
            $supplierId = auth()->user()->supplier?->id;
            if ($supplierId) {
                $query->whereHas('product', function ($q) use ($supplierId) {
                    $q->where('supplier_id', $supplierId);
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
                    ->searchable(),
                Forms\Components\TextInput::make('sku')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->label('Nome da Variação (ex: Tam. M, Cor Vermelha)'),
                Forms\Components\TextInput::make('price_modifier')
                    ->required()
                    ->numeric()
                    ->default(0.00)
                    ->prefix('R$')
                    ->helperText('Valor a adicionar ou subtrair do preço base do produto'),
                Forms\Components\TextInput::make('gtin')
                    ->maxLength(255)
                    ->label('GTIN/EAN'),
                Forms\Components\Toggle::make('is_active')
                    ->required()
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('product.name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('sku')->searchable(),
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('price_modifier')->money('BRL')->sortable(),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
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
            'index' => Pages\ListProductVariations::route('/'),
            'create' => Pages\CreateProductVariation::route('/create'),
            'edit' => Pages\EditProductVariation::route('/{record}/edit'),
        ];
    }
}
