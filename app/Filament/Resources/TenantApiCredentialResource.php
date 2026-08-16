<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TenantApiCredentialResource\Pages;
use App\Models\Tenant;
use App\Models\TenantApiCredential;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class TenantApiCredentialResource extends Resource
{
    protected static ?string $model = TenantApiCredential::class;
    protected static ?string $slug = 'tenant-api-credentials';
    protected static ?string $modelLabel = 'API Credential';
    protected static ?string $pluralModelLabel = 'API Credentials';
    protected static ?string $navigationIcon = 'heroicon-o-key';
    protected static ?string $navigationGroup = 'Supplier Core';
    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool { return auth()->user()?->role === 'super_admin'; }
    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool { return false; }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('tenant_id')
                ->label('Tenant')
                ->required()
                ->options(fn () => Tenant::where('status', '!=', 'archived')->orderBy('slug')->pluck('slug', 'id'))
                ->searchable(),
            Forms\Components\CheckboxList::make('scopes')
                ->required()
                ->options(array_combine(TenantApiCredential::SCOPES, TenantApiCredential::SCOPES))
                ->columns(2)
                ->default(['orders:read', 'suppliers:read', 'products:read', 'events:read']),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('tenant.slug')->label('Tenant')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('key_id')->label('Key ID')->copyable()->searchable(),
                Tables\Columns\TextColumn::make('scopes')->formatStateUsing(fn ($state) => is_array($state) ? implode(',', $state) : $state),
                Tables\Columns\TextColumn::make('last_used_at')->label('Último uso')->since(),
                Tables\Columns\IconColumn::make('revoked_at')->label('Revogada')->boolean()->trueIcon('heroicon-o-x-circle')->falseIcon('heroicon-o-check-circle'),
                Tables\Columns\TextColumn::make('created_at')->since()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('tenant_id')
                    ->label('Tenant')
                    ->options(fn () => Tenant::orderBy('slug')->pluck('slug', 'id')),
                Tables\Filters\TernaryFilter::make('revoked')
                    ->label('Revogada?')
                    ->placeholder('Todas')
                    ->trueLabel('Só revogadas')
                    ->falseLabel('Só ativas')
                    ->queries(
                        true:  fn ($q) => $q->whereNotNull('revoked_at'),
                        false: fn ($q) => $q->whereNull('revoked_at'),
                    ),
            ])
            ->actions([
                Tables\Actions\Action::make('revoke')
                    ->label('Revogar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn ($record) => $record->revoked_at === null)
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        $record->forceFill(['revoked_at' => now()])->save();
                        Notification::make()->title('Credencial revogada')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListTenantApiCredentials::route('/'),
            'create' => Pages\CreateTenantApiCredential::route('/create'),
        ];
    }
}
