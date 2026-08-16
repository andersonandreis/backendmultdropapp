<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SettingsResource\Pages;
use App\Models\Setting;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SettingsResource extends Resource
{
    protected static ?string $model = Setting::class;
    protected static ?string $slug = 'configuracoes';
    protected static ?string $modelLabel = 'Configuração';
    protected static ?string $pluralModelLabel = 'Configurações';

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationGroup = 'Configurações';


    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin']);
    }
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('key')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                // BUG-6 corrigido: campo 'type' removido — coluna nao existe na tabela settings
                // A tabela tem apenas: group, key, value
                Forms\Components\Textarea::make('value')
                    ->required()
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('group')
                    ->maxLength(255)
                    ->default('general'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('group')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('key')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('value')->limit(50)->searchable(),
            ])
            ->defaultSort('group', 'asc')
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
            'index' => Pages\ListSettings::route('/'),
            'create' => Pages\CreateSettings::route('/create'),
            'edit' => Pages\EditSettings::route('/{record}/edit'),
        ];
    }
}
