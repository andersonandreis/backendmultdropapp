<?php
namespace App\Filament\Resources\ClientKitResource\RelationManagers;

use App\Models\ClientProduct;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';
    protected static ?string $title = 'Itens do Kit';
    protected static ?string $modelLabel = 'Item';
    protected static ?string $pluralModelLabel = 'Itens';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('client_product_id')
                ->label('client_product_id do produto do catalogo do cliente')
                ->numeric()
                ->required(),
            Forms\Components\TextInput::make('quantity')
                ->label('Quantidade')
                ->numeric()
                ->minValue(1)
                ->default(1)
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID'),
                Tables\Columns\TextColumn::make('client_product_id')->label('client_product_id')->searchable(),
                Tables\Columns\TextColumn::make('quantity')->label('Qtd'),
                Tables\Columns\TextColumn::make('created_at')->label('Criado')->dateTime('d/m/Y H:i'),
            ])
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }
}
