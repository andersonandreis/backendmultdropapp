<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TenantWebhookEndpointResource\Pages;
use App\Models\Tenant;
use App\Models\TenantWebhookEndpoint;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class TenantWebhookEndpointResource extends Resource
{
    protected static ?string $model = TenantWebhookEndpoint::class;
    protected static ?string $slug = 'tenant-webhook-endpoints';
    protected static ?string $modelLabel = 'Webhook Endpoint';
    protected static ?string $pluralModelLabel = 'Webhook Endpoints';
    protected static ?string $navigationIcon = 'heroicon-o-bolt';
    protected static ?string $navigationGroup = 'Supplier Core';
    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool { return auth()->user()?->role === 'super_admin'; }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('tenant_id')
                ->label('Tenant')
                ->required()
                ->options(fn () => Tenant::orderBy('slug')->pluck('slug', 'id'))
                ->searchable(),
            Forms\Components\TextInput::make('url')->label('URL')->url()->required()->maxLength(500),
            Forms\Components\CheckboxList::make('events')->required()->columns(2)->options([
                'order.created'         => 'order.created',
                'order.status_changed'  => 'order.status_changed',
                'order.shipped'         => 'order.shipped',
                'order.delivered'       => 'order.delivered',
                'order.cancelled'       => 'order.cancelled',
                'order.refunded'        => 'order.refunded',
                'order.tracking_updated'=> 'order.tracking_updated',
                '*'                     => '* (todos)',
            ])->default(['order.created', 'order.status_changed']),
            Forms\Components\TextInput::make('secret')
                ->label('HMAC secret')
                ->helperText('Deixe vazio pra gerar automatico.')
                ->maxLength(128)
                ->default(fn () => Str::random(48)),
            Forms\Components\Toggle::make('active')->default(true),
            Forms\Components\Toggle::make('shadow')->label('Shadow mode')->helperText('Adiciona header X-HubAI-Shadow: true')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('tenant.slug')->label('Tenant')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('url')->limit(50)->copyable(),
                Tables\Columns\IconColumn::make('active')->boolean(),
                Tables\Columns\IconColumn::make('shadow')->label('Shadow')->boolean()->trueIcon('heroicon-o-eye')->falseIcon('heroicon-o-eye-slash'),
                Tables\Columns\TextColumn::make('events')->formatStateUsing(fn ($state) => is_array($state) ? implode(',', $state) : $state),
                Tables\Columns\TextColumn::make('created_at')->since()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('tenant_id')
                    ->options(fn () => Tenant::orderBy('slug')->pluck('slug', 'id')),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListTenantWebhookEndpoints::route('/'),
            'create' => Pages\CreateTenantWebhookEndpoint::route('/create'),
            'edit'   => Pages\EditTenantWebhookEndpoint::route('/{record}/edit'),
        ];
    }
}
