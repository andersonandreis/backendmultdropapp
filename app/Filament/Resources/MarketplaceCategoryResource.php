<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MarketplaceCategoryResource\Pages;
use App\Models\MarketplaceCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class MarketplaceCategoryResource extends Resource
{
    protected static ?string $model = MarketplaceCategory::class;
    protected static ?string $slug = 'categorias-marketplace';
    protected static ?string $modelLabel = 'Categoria Marketplace';
    protected static ?string $pluralModelLabel = 'Categorias Marketplace';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationGroup = 'Catálogo & Produtos';
    protected static ?int $navigationSort = 5;


    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin']);
    }
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('platform')
                    ->label('Plataforma')
                    ->options([
                        'mercadolivre' => 'Mercado Livre',
                        'shopee' => 'Shopee',
                        'amazon' => 'Amazon',
                        'b2w' => 'B2W / Americanas',
                        'magalu' => 'Magazine Luiza',
                    ])
                    ->required(),
                Forms\Components\TextInput::make('external_id')
                    ->label('ID Externo')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('name')
                    ->label('Nome da Categoria')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('full_path')
                    ->label('Caminho Completo')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Ex: Eletrônicos > Celulares > Smartphones'),
                Forms\Components\TextInput::make('parent_external_id')
                    ->label('ID da Categoria Pai')
                    ->maxLength(255),
                Forms\Components\DateTimePicker::make('last_synced_at')
                    ->label('Última Sincronização')
                    ->disabled(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('platform')->label('Plataforma')->badge()->sortable(),
                Tables\Columns\TextColumn::make('external_id')->label('ID Externo')->searchable(),
                Tables\Columns\TextColumn::make('name')->label('Nome')->searchable(),
                Tables\Columns\TextColumn::make('full_path')->label('Caminho')->limit(50)->searchable(),
                Tables\Columns\TextColumn::make('last_synced_at')->label('Última Sync')->dateTime()->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Editar'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('Excluir'),
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
            'index' => Pages\ListMarketplaceCategories::route('/'),
            'create' => Pages\CreateMarketplaceCategory::route('/create'),
            'edit' => Pages\EditMarketplaceCategory::route('/{record}/edit'),
        ];
    }
}
