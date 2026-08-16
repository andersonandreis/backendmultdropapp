<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MarketplaceFeeResource\Pages;
use App\Models\MarketplaceFee;
use App\Services\Pricing\MarketplaceFeeService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class MarketplaceFeeResource extends Resource
{
    protected static ?string $model = MarketplaceFee::class;
    protected static ?string $slug = 'taxas-marketplace';
    protected static ?string $modelLabel = 'Taxa de Marketplace';
    protected static ?string $pluralModelLabel = 'Taxas de Marketplace';

    protected static ?string $navigationIcon = 'heroicon-o-calculator';
    protected static ?string $navigationGroup = 'Configurações';


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
                Forms\Components\TextInput::make('category_name')
                    ->label('Nome da Categoria')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('category_id')
                    ->label('ID da Categoria (Externo)')
                    ->maxLength(255),
                Forms\Components\TextInput::make('listing_type_id')
                    ->label('Tipo de Anúncio (listing_type_id)')
                    ->maxLength(255),
                Forms\Components\TextInput::make('fee_percentage')
                    ->label('Taxa Percentual (%)')
                    ->required()
                    ->numeric()
                    ->default(0.00)
                    ->suffix('%'),
                Forms\Components\TextInput::make('fixed_fee')
                    ->label('Taxa Fixa (R$)')
                    ->required()
                    ->numeric()
                    ->default(0.00)
                    ->prefix('R$'),
                Forms\Components\Select::make('shipping_fee_type')
                    ->label('Tipo de Frete')
                    ->options([
                        'free' => 'Grátis',
                        'fixed' => 'Fixo',
                        'variable' => 'Variável',
                    ]),
                Forms\Components\TextInput::make('min_price')
                    ->label('Preço Mínimo')
                    ->numeric()
                    ->prefix('R$'),
                Forms\Components\TextInput::make('max_price')
                    ->label('Preço Máximo')
                    ->numeric()
                    ->prefix('R$'),
                Forms\Components\Toggle::make('is_active')
                    ->label('Ativa')
                    ->required()
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('platform')->label('Plataforma')->badge()->sortable(),
                Tables\Columns\TextColumn::make('category_name')->label('Categoria')->searchable(),
                Tables\Columns\TextColumn::make('fee_percentage')->label('Taxa (%)')->numeric()->suffix('%')->sortable(),
                Tables\Columns\TextColumn::make('fixed_fee')->label('Taxa Fixa')->money('BRL')->sortable(),
                Tables\Columns\TextColumn::make('source')
                    ->label('Fonte')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'api'     => 'info',
                        'manual'  => 'gray',
                        'default' => 'warning',
                        default   => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'api'     => 'API',
                        'manual'  => 'Manual',
                        'default' => 'Padrão',
                        default   => $state ?? 'Padrão',
                    })
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_active')->label('Ativa')->boolean(),
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
            'index' => Pages\ListMarketplaceFees::route('/'),
            'create' => Pages\CreateMarketplaceFee::route('/create'),
            'edit' => Pages\EditMarketplaceFee::route('/{record}/edit'),
        ];
    }
}
