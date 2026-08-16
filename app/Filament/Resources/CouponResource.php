<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CouponResource\Pages;
use App\Filament\Resources\CouponResource\RelationManagers;
use App\Models\Coupon;
use App\Models\Client;
use App\Models\Supplier;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CouponResource extends Resource
{
    protected static ?string $model = Coupon::class;
    protected static ?string $slug = 'cupons';
    protected static ?string $modelLabel = 'Cupom';
    protected static ?string $pluralModelLabel = 'Cupons';

    protected static ?string $navigationIcon = 'heroicon-o-ticket';
    protected static ?string $navigationGroup = 'Configurações';


    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin']);
    }
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('code')
                    ->label('Código')
                    ->required()
                    ->maxLength(50)
                    ->unique(ignoreRecord: true)
                    ->helperText('Ex: GRUPO50 — use maiusculo, sem espacos'),
                Forms\Components\Textarea::make('description')
                    ->label('Anotacao interna')
                    ->helperText('Contexto: campanha, grupo, data — nao aparece pro cliente')
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('applies_to_plan_slug')
                    ->label('Restringir ao plano (slug)')
                    ->helperText('Ex: premium — deixe vazio para valer em qualquer plano')
                    ->nullable()
                    ->maxLength(50),
                Forms\Components\Select::make('discount_type')
                    ->label('Tipo de Desconto')
                    ->options([
                        'percentage' => 'Porcentagem (%)',
                        'fixed' => 'Valor Fixo (R$)',
                        'free_shipping' => 'Frete Grátis'
                    ])
                    ->required()
                    ->live(),
                Forms\Components\TextInput::make('discount_value')
                    ->label('Valor do Desconto')
                    ->numeric()
                    ->required(fn(Forms\Get $get) => $get('discount_type') !== 'free_shipping')
                    ->visible(fn(Forms\Get $get) => $get('discount_type') !== 'free_shipping'),
                Forms\Components\Select::make('owner_type')
                    ->label('Tipo de Proprietário')
                    ->options([
                        'App\Models\Supplier' => 'Fornecedor',
                        'App\Models\Client' => 'Lojista',
                        'global' => 'Global (Plataforma)',
                    ])
                    ->required()
                    ->live()
                    ->default('global'),
                Forms\Components\TextInput::make('owner_id')
                    ->label('ID do Proprietário')
                    ->numeric()
                    ->visible(fn(Forms\Get $get) => in_array($get('owner_type'), ['App\Models\Supplier', 'App\Models\Client']))
                    ->helperText('Informe o ID do Fornecedor ou Lojista ao qual pertence este cupom.'),
                Forms\Components\TextInput::make('min_order_value')
                    ->label('Valor Mínimo do Pedido (Opcional)')
                    ->numeric()
                    ->prefix('R$'),
                Forms\Components\TextInput::make('max_uses')
                    ->label('Limite de Uso (Total)')
                    ->numeric(),
                Forms\Components\TextInput::make('max_uses_per_client')
                    ->label('Limite de Uso (Por Usuário)')
                    ->numeric()
                    ->default(1),
                Forms\Components\Textarea::make('description')
                    ->label('Descrição')
                    ->columnSpanFull(),
                Forms\Components\DateTimePicker::make('starts_at')
                    ->label('Válido a partir de')
                    ->required(),
                Forms\Components\DateTimePicker::make('ends_at')
                    ->label('Válido até'),
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
                Tables\Columns\TextColumn::make('code')->label('Código')->searchable(),
                Tables\Columns\TextColumn::make('description')->label('Anotacao')->limit(40)->toggleable(),
                Tables\Columns\TextColumn::make('discount_type')->label('Tipo')->badge(),
                Tables\Columns\TextColumn::make('discount_value')->label('Valor')->numeric(),
                Tables\Columns\TextColumn::make('applies_to_plan_slug')->label('Plano')->default('qualquer')->badge(),
                Tables\Columns\TextColumn::make('uses_count')->label('Usos')->numeric()->sortable(),
                Tables\Columns\IconColumn::make('is_active')->label('Ativo')->boolean(),
                Tables\Columns\TextColumn::make('ends_at')->label('Expira em')->dateTime()->sortable(),
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
            'index' => Pages\ListCoupons::route('/'),
            'create' => Pages\CreateCoupon::route('/create'),
            'edit' => Pages\EditCoupon::route('/{record}/edit'),
        ];
    }
}
