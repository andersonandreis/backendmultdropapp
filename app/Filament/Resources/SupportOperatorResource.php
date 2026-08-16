<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupportOperatorResource\Pages;
use App\Models\SupportOperator;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SupportOperatorResource extends Resource
{
    protected static ?string $model = SupportOperator::class;
    protected static ?string $slug = 'sac-operadores';
    protected static ?string $modelLabel = 'Operador';
    protected static ?string $pluralModelLabel = 'SAC — Operadores';
    protected static ?string $navigationIcon = 'heroicon-o-user-group';
    protected static ?string $navigationGroup = 'Atendimento';
    protected static ?int $navigationSort = 13;

    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'supplier']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('supplier_id')->relationship('supplier', 'company_name')
                ->required()->visible(fn () => auth()->user()?->role === 'super_admin'),
            Forms\Components\Select::make('user_id')->relationship('user', 'name')
                ->required()->searchable(),
            Forms\Components\Select::make('department_ids')->label('Setores')
                ->multiple()
                ->options(fn () => \App\Models\SupportDepartment::query()->pluck('name', 'id')->all())
                ->searchable(),
            Forms\Components\Toggle::make('online')->default(false),
            Forms\Components\Toggle::make('active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label('Operador'),
                Tables\Columns\TextColumn::make('user.email')->label('Email'),
                Tables\Columns\IconColumn::make('online')->boolean(),
                Tables\Columns\IconColumn::make('active')->boolean(),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSupportOperators::route('/'),
            'create' => Pages\CreateSupportOperator::route('/create'),
            'edit'   => Pages\EditSupportOperator::route('/{record}/edit'),
        ];
    }
}
