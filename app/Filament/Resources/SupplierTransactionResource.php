<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupplierTransactionResource\Pages;
use App\Models\SupplierTransaction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SupplierTransactionResource extends Resource
{
    protected static ?string $model = SupplierTransaction::class;
    protected static ?string $slug = 'transacoes-fornecedor';
    protected static ?string $modelLabel = 'Transação de Fornecedor';
    protected static ?string $pluralModelLabel = 'Transações de Fornecedores';

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';
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
                Forms\Components\TextInput::make('amount')
                    ->label('Valor')
                    ->required()
                    ->numeric()
                    ->prefix('R$')
                    ->disabled(),
                Forms\Components\Select::make('type')
                    ->label('Tipo')
                    ->options([
                        'sale' => 'Venda (Crédito)',
                        'withdrawal' => 'Saque (Débito)',
                        'adjustment' => 'Ajuste Manual',
                    ])
                    ->required()
                    ->disabled(),
                Forms\Components\TextInput::make('description')
                    ->label('Descrição')
                    ->maxLength(255)
                    ->disabled(),
                Forms\Components\TextInput::make('order_id')
                    ->label('ID do Pedido')
                    ->numeric()
                    ->disabled(),
                Forms\Components\TextInput::make('withdrawal_request_id')
                    ->label('ID da Solicitação de Saque')
                    ->numeric()
                    ->disabled(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('producer.company_name')->label('Produtor')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('warehouse.company_name')->label('Armazém')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'sale' => 'success',
                        'withdrawal' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('amount')->label('Valor')->money('BRL')->sortable(),
                Tables\Columns\TextColumn::make('description')->label('Descrição')->searchable()->limit(50),
                Tables\Columns\TextColumn::make('created_at')->label('Data')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Visualizar'),
            ])
            ->bulkActions([
                //
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
            'index' => Pages\ListSupplierTransactions::route('/'),
            'create' => Pages\CreateSupplierTransaction::route('/create'),
            'edit' => Pages\EditSupplierTransaction::route('/{record}/edit'),
        ];
    }
}
