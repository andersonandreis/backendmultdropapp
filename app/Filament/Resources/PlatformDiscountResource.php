<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PlatformDiscountResource\Pages;
use App\Filament\Resources\PlatformDiscountResource\RelationManagers;
use App\Models\PlatformDiscount;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PlatformDiscountResource extends Resource
{
    protected static ?string $model = PlatformDiscount::class;
    protected static ?string $slug = 'descontos-plataforma';
    protected static ?string $modelLabel = 'Desconto da Plataforma';
    protected static ?string $pluralModelLabel = 'Descontos da Plataforma';

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';
    protected static ?string $navigationGroup = 'Configurações';


    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin']);
    }
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Textarea::make('description')
                    ->columnSpanFull(),
                Forms\Components\Select::make('type')
                    ->options([
                        'graduated_order' => 'Graduado por Qtd. de Pedidos',
                        'first_purchase' => 'Desconto na Primeira Compra',
                        'coupon' => 'Baseado em Cupom',
                    ])
                    ->required(),
                Forms\Components\Toggle::make('is_active')
                    ->required()
                    ->default(true),

                Forms\Components\Section::make('Faixas de Desconto (Graduado)')
                    ->schema([
                        Forms\Components\Repeater::make('tiers')
                            ->relationship()
                            ->schema([
                                Forms\Components\TextInput::make('from_order')
                                    ->required()->numeric()->label('Mín. de Pedidos'),
                                Forms\Components\TextInput::make('to_order')
                                    ->numeric()->label('Máx. de Pedidos (Vazio=Ilimitado)'),
                                Forms\Components\Select::make('discount_type')
                                    ->options([
                                        'percentage' => 'Porcentagem (%)',
                                        'fixed' => 'Valor Fixo (R$)'
                                    ])->required(),
                                Forms\Components\TextInput::make('discount_value')
                                    ->required()->numeric(),
                            ])->columns(4)
                    ])
                    ->visible(fn(Forms\Get $get) => $get('type') === 'graduated_order')
                    ->reactive()
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('type')->badge(),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
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
            'index' => Pages\ListPlatformDiscounts::route('/'),
            'create' => Pages\CreatePlatformDiscount::route('/create'),
            'edit' => Pages\EditPlatformDiscount::route('/{record}/edit'),
        ];
    }
}
