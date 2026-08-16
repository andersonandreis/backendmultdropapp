<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PlanDiscountResource\Pages;
use App\Filament\Resources\PlanDiscountResource\RelationManagers;
use App\Models\PlanDiscount;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PlanDiscountResource extends Resource
{
    protected static ?string $model = PlanDiscount::class;
    protected static ?string $slug = 'descontos-plano';
    protected static ?string $modelLabel = 'Desconto de Plano';
    protected static ?string $pluralModelLabel = 'Descontos de Planos';

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
                Forms\Components\Select::make('plan_id')
                    ->relationship('plan', 'name')
                    ->required()
                    ->searchable(),
                Forms\Components\Select::make('discount_type')
                    ->options([
                        'percentage' => 'Porcentagem (%)',
                        'fixed' => 'Valor Fixo (R$)'
                    ])
                    ->required(),
                Forms\Components\TextInput::make('discount_value')
                    ->required()
                    ->numeric(),
                Forms\Components\Select::make('billing_cycle')
                    ->options([
                        'monthly' => 'Mensal',
                        'yearly' => 'Anual'
                    ])
                    ->required(),
                Forms\Components\Toggle::make('is_active')
                    ->required()
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('plan.name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('discount_type')->badge(),
                Tables\Columns\TextColumn::make('discount_value')->numeric()->sortable(),
                Tables\Columns\TextColumn::make('billing_cycle')->badge(),
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
            'index' => Pages\ListPlanDiscounts::route('/'),
            'create' => Pages\CreatePlanDiscount::route('/create'),
            'edit' => Pages\EditPlanDiscount::route('/{record}/edit'),
        ];
    }
}
