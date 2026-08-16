<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EventLogResource\Pages;
use App\Models\EventLog;
use App\Services\Events\EventDispatcherService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EventLogResource extends Resource
{
    protected static ?string $model = EventLog::class;
    protected static ?string $slug = 'log-eventos';
    protected static ?string $modelLabel = 'Log de Evento';
    protected static ?string $pluralModelLabel = 'Logs de Eventos';

    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationGroup = 'Configurações';
    protected static ?int $navigationSort = 40;

    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin']);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('event_type')
                    ->label('Evento')
                    ->formatStateUsing(fn (string $state): string => EventDispatcherService::labelFor($state))
                    ->searchable(),

                Tables\Columns\TextColumn::make('channel')
                    ->label('Canal')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'webhook' => 'info',
                        'email'   => 'primary',
                        'in_app'  => 'success',
                        'push'    => 'warning',
                        default   => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'webhook' => 'Webhook',
                        'email'   => 'E-mail',
                        'in_app'  => 'App',
                        'push'    => 'Push',
                        default   => $state,
                    }),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'sent'    => 'success',
                        'pending' => 'warning',
                        'failed'  => 'danger',
                        default   => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'sent'    => 'Enviado',
                        'pending' => 'Pendente',
                        'failed'  => 'Falhou',
                        default   => $state,
                    }),

                Tables\Columns\TextColumn::make('attempts')
                    ->label('Tentativas')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('sent_at')
                    ->label('Enviado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('error')
                    ->label('Erro')
                    ->limit(50)
                    ->toggleable()
                    ->tooltip(fn ($state) => $state),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Pendente',
                        'sent'    => 'Enviado',
                        'failed'  => 'Falhou',
                    ]),

                Tables\Filters\SelectFilter::make('channel')
                    ->label('Canal')
                    ->options([
                        'email'   => 'E-mail',
                        'in_app'  => 'App',
                        'webhook' => 'Webhook',
                    ]),

                Tables\Filters\SelectFilter::make('event_type')
                    ->label('Evento')
                    ->options(function () {
                        $opts = [];
                        foreach (EventDispatcherService::availableEvents() as $group => $items) {
                            foreach ($items as $key => $label) {
                                $opts[$key] = "[{$group}] {$label}";
                            }
                        }
                        return $opts;
                    }),

                Tables\Filters\Filter::make('periodo')
                    ->form([
                        Forms\Components\DatePicker::make('de')->label('De'),
                        Forms\Components\DatePicker::make('ate')->label('Até'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['de'],  fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
                            ->when($data['ate'], fn ($q, $v) => $q->whereDate('created_at', '<=', $v));
                    })
                    ->label('Período'),
            ])
            ->actions([
                Tables\Actions\Action::make('retentar')
                    ->label('Retentar')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (EventLog $record) => in_array($record->status, ['pending', 'failed']))
                    ->action(function (EventLog $record) {
                        try {
                            app(\App\Services\Events\WebhookDeliveryService::class)
                                ->attemptDelivery($record);

                            Notification::make()
                                ->title('Retentativa disparada!')
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Erro na retentativa')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEventLogs::route('/'),
        ];
    }
}
