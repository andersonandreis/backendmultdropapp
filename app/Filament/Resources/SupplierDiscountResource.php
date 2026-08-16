<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupplierDiscountResource\Pages;
use App\Models\SupplierDiscount;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SupplierDiscountResource extends Resource
{
    protected static ?string $model = SupplierDiscount::class;
    protected static ?string $slug = 'desconto-fornecedor';
    protected static ?string $modelLabel = 'Desconto de Fornecedor';
    protected static ?string $pluralModelLabel = 'Descontos de Fornecedores';

    protected static ?string $navigationIcon = 'heroicon-o-receipt-refund';
    protected static ?string $navigationGroup = 'Configurações';


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
                $query->where('supplier_id', $supplierId);
            }
        }
        return $query;
    }
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('supplier_id')
                    ->relationship('supplier', 'company_name')
                    ->required()
                    ->searchable()
                    ->label('Fornecedor'),
                Forms\Components\TextInput::make('name')
                    ->label('Nome do Desconto')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Textarea::make('description')
                    ->label('Descrição')
                    ->columnSpanFull(),
                Forms\Components\Select::make('target')
                    ->label('Público Alvo')
                    ->options([
                        'all_clients' => 'Todos os Lojistas',
                        'specific_client' => 'Lojista Específico',
                        'plan' => 'Plano Específico',
                    ])
                    ->required()
                    ->default('all_clients'),
                Forms\Components\TextInput::make('target_id')
                    ->label('ID do Alvo (Lojista/Plano)')
                    ->numeric()
                    ->helperText('Informe o ID do lojista ou plano se o alvo for específico.'),
                Forms\Components\Select::make('trigger_type')
                    ->label('Tipo de Gatilho')
                    ->options([
                        'volume' => 'Volume de Compra',
                        'first_purchase' => 'Primeira Compra',
                        'loyalty' => 'Fidelidade',
                        'manual' => 'Manual (Aplicado Manualmente)',
                    ])
                    ->required(),
                Forms\Components\Toggle::make('is_stackable')
                    ->label('Acumulável com outros descontos')
                    ->default(false),
                Forms\Components\DateTimePicker::make('starts_at')
                    ->label('Início'),
                Forms\Components\DateTimePicker::make('ends_at')
                    ->label('Fim (Opcional)'),
                Forms\Components\Toggle::make('is_active')
                    ->label('Ativo')
                    ->required()
                    ->default(true),

                Forms\Components\Section::make('Faixas de Desconto (Volume)')
                    ->schema([
                        Forms\Components\Repeater::make('tiers')
                            ->relationship()
                            ->schema([
                                Forms\Components\TextInput::make('min_quantity')
                                    ->label('Qtd. Mínima')
                                    ->numeric(),
                                Forms\Components\TextInput::make('max_quantity')
                                    ->label('Qtd. Máxima (Vazio=Ilimitado)')
                                    ->numeric(),
                                Forms\Components\Select::make('discount_type')
                                    ->label('Tipo')
                                    ->options([
                                        'percentage' => 'Porcentagem (%)',
                                        'fixed' => 'Valor Fixo (R$)',
                                    ])
                                    ->required()
                                    ->default('percentage'),
                                Forms\Components\TextInput::make('discount_value')
                                    ->label('Valor do Desconto')
                                    ->required()
                                    ->numeric(),
                            ])->columns(4)
                    ])
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('supplier.company_name')->label('Fornecedor')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('name')->label('Nome')->searchable(),
                Tables\Columns\TextColumn::make('trigger_type')->label('Gatilho')->badge(),
                Tables\Columns\TextColumn::make('target')->label('Alvo')->badge(),
                Tables\Columns\IconColumn::make('is_active')->label('Ativo')->boolean(),
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
            'index' => Pages\ListSupplierDiscounts::route('/'),
            'create' => Pages\CreateSupplierDiscount::route('/create'),
            'edit' => Pages\EditSupplierDiscount::route('/{record}/edit'),
        ];
    }
}
