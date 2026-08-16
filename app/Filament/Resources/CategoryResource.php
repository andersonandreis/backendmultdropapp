<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CategoryResource\Pages;
use App\Models\Category;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;
    protected static ?string $slug = 'categorias';
    protected static ?string $modelLabel = 'Categoria';
    protected static ?string $pluralModelLabel = 'Categorias';

    protected static ?string $navigationIcon = 'heroicon-o-tag';
    protected static ?string $navigationGroup = 'Catálogo & Produtos';
    protected static ?int $navigationSort = 4;


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
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('supplier_id')
                    ->relationship('supplier', 'company_name')
                    ->searchable()
                    ->preload()
                    ->label('Fornecedor (Opcional)'),
                Forms\Components\TextInput::make('name')
                    ->label('Nome da Categoria')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('parent_id')
                    ->relationship('parent', 'name')
                    ->searchable()
                    ->preload()
                    ->label('Categoria Pai'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nome')->searchable(),
                Tables\Columns\TextColumn::make('supplier.company_name')->label('Fornecedor')->searchable(),
                Tables\Columns\TextColumn::make('parent.name')->label('Categoria Pai')->searchable(),
                Tables\Columns\TextColumn::make('created_at')->label('Criado em')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
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
            'index' => Pages\ListCategories::route('/'),
            'create' => Pages\CreateCategory::route('/create'),
            'edit' => Pages\EditCategory::route('/{record}/edit'),
        ];
    }
}
