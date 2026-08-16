<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PlanResource\Pages;
use App\Filament\Resources\PlanResource\RelationManagers;
use App\Models\Plan;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;
    protected static ?string $slug = 'planos';
    protected static ?string $modelLabel = 'Plano de Assinatura';
    protected static ?string $pluralModelLabel = 'Planos';

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';
    protected static ?string $navigationGroup = 'Configurações';
    protected static ?string $navigationLabel = 'Configuração de Planos';
    protected static ?int $navigationSort = 3;


    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin']);
    }
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Nome do Plano')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Textarea::make('description')
                    ->label('Descrição')
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('max_skus')
                    ->label('Limite de SKUs')
                    ->required()
                    ->numeric()
                    ->default(0)
                    ->helperText('0 para ilimitado.'),
                Forms\Components\TextInput::make('max_marketplace_connections')
                    ->label('Conexões Marketplace Max.')
                    ->required()
                    ->numeric()
                    ->default(1),
                Forms\Components\TextInput::make('max_erp_connections')
                    ->label('Conexões ERP Max.')
                    ->required()
                    ->numeric()
                    ->default(1),
                Forms\Components\TextInput::make('max_integrations')
                    ->label('Total de Integrações (ML+Shopee+Bling)')
                    ->numeric()
                    ->nullable()
                    ->helperText('Soma de todas as integrações de marketplace e ERP. Deixe em branco para ilimitado.'),
                Forms\Components\TextInput::make('price_monthly')
                    ->label('Preço Mensal')
                    ->required()
                    ->numeric()
                    ->default(0.00)
                    ->prefix('R$'),
                Forms\Components\TextInput::make('price_yearly')
                    ->label('Preço Anual')
                    ->required()
                    ->numeric()
                    ->default(0.00)
                    ->prefix('R$'),
                Forms\Components\TextInput::make('trial_days')
                    ->label('Dias de Teste (Trial)')
                    ->required()
                    ->numeric()
                    ->default(7),
                Forms\Components\Select::make('suppliers')
                    ->relationship('suppliers', 'company_name')
                    ->multiple()
                    ->preload()
                    ->searchable()
                    ->label('Fornecedores Liberados neste Plano')
                    ->columnSpanFull()
                    ->helperText('Selecione quais Fornecedores estarão disponíveis nativamente para os clientes que assinarem este plano.'),
                Forms\Components\Toggle::make('has_drop_internacional')
                    ->label('Drop Internacional')
                    ->helperText('Habilita acesso ao modulo Drop Internacional para clientes neste plano.')
                    ->default(false),
                Forms\Components\Toggle::make('is_active')
                    ->label('Ativo')
                    ->required()
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nome')->searchable(),
                Tables\Columns\TextColumn::make('price_monthly')->label('Mensal')->money('BRL')->sortable(),
                Tables\Columns\TextColumn::make('price_yearly')->label('Anual')->money('BRL')->sortable(),
                Tables\Columns\TextColumn::make('max_skus')->label('SKUs')->numeric(),
                Tables\Columns\TextColumn::make('trial_days')->label('Trial (Dias)')->numeric(),
                Tables\Columns\TextColumn::make('max_integrations')->label('Integrações')->placeholder('Ilimitado'),
                Tables\Columns\TextColumn::make('subscriptions_count')
                    ->label('Assinantes')
                    ->counts('subscriptions')
                    ->badge()
                    ->color('primary'),
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
            'index' => Pages\ListPlans::route('/'),
            'create' => Pages\CreatePlan::route('/create'),
            'edit' => Pages\EditPlan::route('/{record}/edit'),
        ];
    }
}
