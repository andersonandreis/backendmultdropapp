<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EventSubscriptionResource\Pages;
use App\Models\EventSubscription;
use App\Services\Events\EventDispatcherService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class EventSubscriptionResource extends Resource
{
    protected static ?string $model = EventSubscription::class;
    protected static ?string $slug = 'inscricoes-eventos';
    protected static ?string $modelLabel = 'Inscrição em Evento';
    protected static ?string $pluralModelLabel = 'Inscrições em Eventos';

    protected static ?string $navigationIcon = 'heroicon-o-bell';
    protected static ?string $navigationGroup = 'Configurações';
    protected static ?int $navigationSort = 30;

    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin']);
    }

    public static function form(Form $form): Form
    {
        // Flatten the grouped events for the Select options
        $events = [];
        foreach (EventDispatcherService::availableEvents() as $group => $items) {
            foreach ($items as $key => $label) {
                $events[$key] = "[{$group}] {$label}";
            }
        }

        return $form
            ->schema([
                Forms\Components\Select::make('event_type')
                    ->label('Tipo de Evento')
                    ->options(array_merge(['*' => '[Todos] Todos os eventos'], $events))
                    ->searchable()
                    ->required(),

                Forms\Components\Select::make('supplier_id')
                    ->label('Fornecedor (opcional)')
                    ->relationship('supplier', 'company_name')
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->helperText('Deixe em branco para receber eventos globais (admin).'),

                Forms\Components\CheckboxList::make('channels')
                    ->label('Canais de entrega')
                    ->options([
                        'email'    => 'E-mail',
                        'in_app'   => 'Notificação no App',
                        'webhook'  => 'Webhook',
                        'push'     => 'Push (em breve)',
                    ])
                    ->disableOptionWhen(fn (string $value): bool => $value === 'push')
                    ->live()
                    ->columns(2)
                    ->required(),

                Forms\Components\TextInput::make('webhook_url')
                    ->label('URL do Webhook')
                    ->url()
                    ->visible(fn (Get $get) => in_array('webhook', (array) $get('channels')))
                    ->required(fn (Get $get) => in_array('webhook', (array) $get('channels')))
                    ->placeholder('https://meu-sistema.com/webhook'),

                Forms\Components\TextInput::make('webhook_secret')
                    ->label('Webhook Secret (HMAC)')
                    ->disabled()
                    ->dehydrated(false)
                    ->suffixAction(
                        Forms\Components\Actions\Action::make('regenerar')
                            ->label('Regenerar')
                            ->icon('heroicon-o-arrow-path')
                            ->color('warning')
                            ->requiresConfirmation()
                            ->action(function ($record) {
                                if ($record) {
                                    $record->update(['webhook_secret' => Str::random(64)]);
                                    Notification::make()->title('Secret regenerado!')->success()->send();
                                }
                            })
                    )
                    ->visible(fn (Get $get) => in_array('webhook', (array) $get('channels'))),

                Forms\Components\Toggle::make('is_active')
                    ->label('Inscrição ativa')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('event_type')
                    ->label('Evento')
                    ->formatStateUsing(function (string $state): string {
                        if ($state === '*') return 'Todos os eventos';
                        return EventDispatcherService::labelFor($state);
                    })
                    ->searchable(),

                Tables\Columns\TextColumn::make('channels')
                    ->label('Canais')
                    ->formatStateUsing(function ($state): string {
                        $channels = is_array($state) ? $state : json_decode($state, true) ?? [];
                        $labels   = ['email' => 'E-mail', 'in_app' => 'App', 'webhook' => 'Webhook', 'push' => 'Push'];
                        return implode(', ', array_map(fn ($c) => $labels[$c] ?? $c, $channels));
                    })
                    ->badge(),

                Tables\Columns\TextColumn::make('webhook_url')
                    ->label('Webhook URL')
                    ->limit(40)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('supplier.company_name')
                    ->label('Fornecedor')
                    ->default('Admin (Global)')
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Ativo')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status'),
            ])
            ->actions([
                Tables\Actions\Action::make('testar_webhook')
                    ->label('Testar Webhook')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('info')
                    ->visible(fn (EventSubscription $record) => $record->webhook_url && in_array('webhook', $record->channels ?? []))
                    ->action(function (EventSubscription $record) {
                        try {
                            app(\App\Services\Events\WebhookDeliveryService::class)
                                ->sendTestWebhook($record);

                            Notification::make()
                                ->title('Webhook de teste enviado!')
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Erro ao enviar webhook')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Tables\Actions\EditAction::make()->label('Editar'),
                Tables\Actions\DeleteAction::make()->label('Excluir'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('Excluir'),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListEventSubscriptions::route('/'),
            'create' => Pages\CreateEventSubscription::route('/create'),
            'edit'   => Pages\EditEventSubscription::route('/{record}/edit'),
        ];
    }
}
