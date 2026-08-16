<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupportTopicResource\Pages;
use App\Models\SupportTopic;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SupportTopicResource extends Resource
{
    protected static ?string $model = SupportTopic::class;
    protected static ?string $slug = 'sac-topicos';
    protected static ?string $modelLabel = 'Tópico';
    protected static ?string $pluralModelLabel = 'SAC — Tópicos';
    protected static ?string $navigationIcon = 'heroicon-o-tag';
    protected static ?string $navigationGroup = 'Atendimento';
    protected static ?int $navigationSort = 11;

    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'supplier']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('supplier_id')->relationship('supplier', 'company_name')
                ->required()->visible(fn () => auth()->user()?->role === 'super_admin'),
            Forms\Components\Select::make('department_id')->relationship('department', 'name')->required(),
            Forms\Components\TextInput::make('name')->required()->maxLength(100),
            Forms\Components\TextInput::make('description')->maxLength(255),
            Forms\Components\Toggle::make('active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('department.name')->label('Setor'),
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('description')->limit(40)->placeholder('—'),
                Tables\Columns\IconColumn::make('active')->boolean(),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSupportTopics::route('/'),
            'create' => Pages\CreateSupportTopic::route('/create'),
            'edit'   => Pages\EditSupportTopic::route('/{record}/edit'),
        ];
    }
}
