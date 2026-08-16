<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupplierBalanceResource\Pages;
use App\Models\SupplierBalance;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SupplierBalanceResource extends Resource
{
    protected static ?string $model = SupplierBalance::class;
    protected static ?string $slug = 'saldo-fornecedor';
    protected static ?string $modelLabel = 'Saldo de Fornecedor';
    protected static ?string $pluralModelLabel = 'Saldos de Fornecedores';

    protected static ?string $navigationIcon = 'heroicon-o-wallet';
    protected static ?string $navigationGroup = 'Financeiro';


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
                $query->where('producer_id', $supplierId);
            }
        }
        return $query;
    }
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('producer_id')
                    ->relationship('producer', 'company_name')
                    ->required()
                    ->disabled()
                    ->label('Produtor'),
                Forms\Components\Select::make('warehouse_id')
                    ->relationship('warehouse', 'company_name')
                    ->required()
                    ->disabled()
                    ->label('Armazém'),
                Forms\Components\TextInput::make('balance')
                    ->label('Saldo Disponível')
                    ->required()
                    ->numeric()
                    ->prefix('R$')
                    ->disabled(),
                Forms\Components\TextInput::make('total_earned')
                    ->label('Total Recebido')
                    ->required()
                    ->numeric()
                    ->prefix('R$')
                    ->disabled(),
                Forms\Components\TextInput::make('total_withdrawn')
                    ->label('Total Sacado')
                    ->required()
                    ->numeric()
                    ->prefix('R$')
                    ->disabled(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('producer.company_name')->label('Produtor')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('warehouse.company_name')->label('Armazém')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('balance')->label('Saldo')->money('BRL')->sortable(),
                Tables\Columns\TextColumn::make('total_earned')->label('Total Recebido')->money('BRL')->sortable(),
                Tables\Columns\TextColumn::make('total_withdrawn')->label('Total Sacado')->money('BRL')->sortable(),
                Tables\Columns\TextColumn::make('updated_at')->label('Atualizado em')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Visualizar'),
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
            'index' => Pages\ListSupplierBalances::route('/'),
            'create' => Pages\CreateSupplierBalance::route('/create'),
            'edit' => Pages\EditSupplierBalance::route('/{record}/edit'),
        ];
    }
}
