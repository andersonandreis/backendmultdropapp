<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RepricingCostConfigResource\Pages;
use App\Models\RepricingCostConfig;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** NOV-127 — Configuração de custos para repricing por marketplace. */
class RepricingCostConfigResource extends Resource
{
    protected static ?string $model = RepricingCostConfig::class;
    protected static ?string $slug = 'repricing-custos';
    protected static ?string $modelLabel = 'Config Repricing';
    protected static ?string $pluralModelLabel = 'Configurações de Repricing';
    protected static ?string $navigationIcon = 'heroicon-o-calculator';
    protected static ?string $navigationGroup = 'Preços & Margem';
    protected static ?int $navigationSort = 5;

    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'supplier']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Escopo')->schema([
                Forms\Components\Select::make('supplier_id')
                    ->relationship('supplier', 'company_name')
                    ->required()->searchable()
                    ->visible(fn () => auth()->user()?->role === 'super_admin'),
                Forms\Components\Select::make('marketplace')->options([
                    'mercadolivre' => 'Mercado Livre',
                    'shopee'       => 'Shopee',
                    'amazon'       => 'Amazon',
                    'magalu'       => 'Magalu',
                    'b2w'          => 'B2W',
                    'site'         => 'Site próprio',
                ])->required(),
                Forms\Components\TextInput::make('product_category')->label('Categoria (opcional)')
                    ->helperText('Deixe vazio para aplicar a TODAS as categorias do marketplace'),
                Forms\Components\Toggle::make('active')->default(true),
            ])->columns(2),

            Forms\Components\Section::make('Custos (% sobre o preço final)')->schema([
                Forms\Components\TextInput::make('shipping_cost_pct')->label('Frete %')
                    ->numeric()->step(0.001)->default(0)->suffix('%'),
                Forms\Components\TextInput::make('marketplace_fee_pct')->label('Taxa marketplace %')
                    ->numeric()->step(0.001)->default(0)->suffix('%')
                    ->helperText('ML: ~12-16%, Shopee: ~14-20%'),
                Forms\Components\TextInput::make('desired_margin_pct')->label('Margem desejada %')
                    ->numeric()->step(0.001)->default(20)->suffix('%')->required(),
                Forms\Components\TextInput::make('extra_cost_fixed')->label('Custo fixo extra')
                    ->numeric()->step(0.01)->default(0)->prefix('R$')
                    ->helperText('Embalagem, etiqueta, manuseio etc'),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('marketplace')->badge(),
                Tables\Columns\TextColumn::make('product_category')->label('Categoria')->placeholder('Todas'),
                Tables\Columns\TextColumn::make('shipping_cost_pct')->label('Frete %')->numeric(decimalPlaces: 2),
                Tables\Columns\TextColumn::make('marketplace_fee_pct')->label('Tx MP %')->numeric(decimalPlaces: 2),
                Tables\Columns\TextColumn::make('desired_margin_pct')->label('Margem %')->numeric(decimalPlaces: 2),
                Tables\Columns\TextColumn::make('extra_cost_fixed')->label('Extra')->money('BRL'),
                Tables\Columns\IconColumn::make('active')->boolean(),
            ])
            ->filters([])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListRepricingCostConfigs::route('/'),
            'create' => Pages\CreateRepricingCostConfig::route('/create'),
            'edit'   => Pages\EditRepricingCostConfig::route('/{record}/edit'),
        ];
    }
}
