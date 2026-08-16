<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupportDepartmentResource\Pages;
use App\Models\SupportDepartment;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SupportDepartmentResource extends Resource
{
    protected static ?string $model = SupportDepartment::class;
    protected static ?string $slug = 'sac-setores';
    protected static ?string $modelLabel = 'Setor';
    protected static ?string $pluralModelLabel = 'SAC — Setores';
    protected static ?string $navigationIcon = 'heroicon-o-folder';
    protected static ?string $navigationGroup = 'Atendimento';
    protected static ?int $navigationSort = 10;

    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'supplier']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('supplier_id')->relationship('supplier', 'company_name')
                ->required()->visible(fn () => auth()->user()?->role === 'super_admin'),
            Forms\Components\TextInput::make('name')->required()->maxLength(100),
            Forms\Components\TextInput::make('description')->maxLength(255),
            Forms\Components\TextInput::make('color')->label('Cor (hex)')->placeholder('#3b82f6')->maxLength(20),
            Forms\Components\Toggle::make('active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('description')->limit(40)->placeholder('—'),
                Tables\Columns\TextColumn::make('topics_count')->counts('topics')->label('Tópicos'),
                Tables\Columns\IconColumn::make('active')->boolean(),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSupportDepartments::route('/'),
            'create' => Pages\CreateSupportDepartment::route('/create'),
            'edit'   => Pages\EditSupportDepartment::route('/{record}/edit'),
        ];
    }
}
