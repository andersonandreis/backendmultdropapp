<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WebhookConfigResource\Pages;
use App\Models\WebhookConfig;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class WebhookConfigResource extends Resource
{
    protected static ?string $model = WebhookConfig::class;
    protected static ?string $slug = 'configs-webhook';
    protected static ?string $modelLabel = 'Configuração Webhook';
    protected static ?string $pluralModelLabel = 'Configurações Webhook';
    protected static ?string $navigationGroup = 'Configurações';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';


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
                Forms\Components\TextInput::make('slug')
                    ->maxLength(255)
                    ->helperText('Gerado automaticamente a partir do nome. Pode ser editado manualmente.')
                    ->unique(ignoreRecord: true),
                Forms\Components\TextInput::make('security_header')
                    ->maxLength(255)
                    ->default(null),
                Forms\Components\TextInput::make('event_field')
                    ->maxLength(255)
                    ->default(null),
                Forms\Components\TextInput::make('expected_event_value')
                    ->maxLength(255)
                    ->default(null),
                // BUG-7 corrigido: ->email() removido — campo armazena caminho JSON, nao email real
                Forms\Components\TextInput::make('customer_email_field')
                    ->maxLength(255)
                    ->default(null)
                    ->helperText('Ex: ["Customer"]["email"] — caminho JSON no payload do webhook'),
                Forms\Components\TextInput::make('amount_field')
                    ->maxLength(255)
                    ->default(null),
                Forms\Components\TextInput::make('subscription_id_field')
                    ->maxLength(255)
                    ->default(null),
                Forms\Components\Toggle::make('is_active')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('slug')
                    ->searchable(),
                Tables\Columns\TextColumn::make('security_header')
                    ->searchable(),
                Tables\Columns\TextColumn::make('event_field')
                    ->searchable(),
                Tables\Columns\TextColumn::make('expected_event_value')
                    ->searchable(),
                Tables\Columns\TextColumn::make('customer_email_field')
                    ->searchable(),
                Tables\Columns\TextColumn::make('amount_field')
                    ->searchable(),
                Tables\Columns\TextColumn::make('subscription_id_field')
                    ->searchable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
            'index' => Pages\ListWebhookConfigs::route('/'),
            'create' => Pages\CreateWebhookConfig::route('/create'),
            'edit' => Pages\EditWebhookConfig::route('/{record}/edit'),
        ];
    }
}
