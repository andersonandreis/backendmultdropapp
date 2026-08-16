<?php

namespace App\Filament\Resources;

use App\Enums\EmailStatus;
use App\Filament\Resources\EmailLogResource\Pages;
use App\Jobs\SendWelcomeEmailJob;
use App\Models\EmailLog;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EmailLogResource extends Resource
{
    protected static ?string $model = EmailLog::class;
    protected static ?string $slug = 'log-emails';
    protected static ?string $modelLabel = 'Log de E-mail';
    protected static ?string $pluralModelLabel = 'Logs de E-mail';

    protected static ?string $navigationIcon = 'heroicon-o-envelope';
    protected static ?string $navigationGroup = 'Comunicação';
    protected static ?int $navigationSort = 10;

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
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Usuário')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('to_email')
                    ->label('E-mail')
                    ->searchable()
                    ->copyable()
                    ->icon('heroicon-m-envelope'),

                Tables\Columns\TextColumn::make('email_type')
                    ->label('Tipo')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'welcome'           => 'Boas-vindas',
                        'password_reset'    => 'Redefinição de Senha',
                        'order_confirmed'   => 'Pedido Confirmado',
                        'order_shipped'     => 'Pedido Enviado',
                        'invoice'           => 'Nota Fiscal',
                        default             => ucfirst(str_replace('_', ' ', $state)),
                    }),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (EmailStatus $state): string => $state->getColor())
                    ->formatStateUsing(fn (EmailStatus $state): string => $state->getLabel()),

                Tables\Columns\TextColumn::make('sent_at')
                    ->label('Enviado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('opened_at')
                    ->label('Aberto em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->icon(fn ($record): ?string => $record?->opened_at ? 'heroicon-m-eye' : null)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('opened_count')
                    ->label('Aberturas')
                    ->numeric()
                    ->alignCenter()
                    ->placeholder('0')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('clicked_at')
                    ->label('Clicado em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->icon(fn ($record): ?string => $record?->clicked_at ? 'heroicon-m-cursor-arrow-rays' : null)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('click_count')
                    ->label('Cliques')
                    ->numeric()
                    ->alignCenter()
                    ->placeholder('0')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('failed_reason')
                    ->label('Motivo da Falha')
                    ->limit(40)
                    ->tooltip(fn ($state) => $state)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(EmailStatus::cases())->mapWithKeys(
                        fn (EmailStatus $case) => [$case->value => $case->getLabel()]
                    )->toArray()),

                Tables\Filters\SelectFilter::make('email_type')
                    ->label('Tipo')
                    ->options(function () {
                        return EmailLog::query()
                            ->select('email_type')
                            ->distinct()
                            ->orderBy('email_type')
                            ->pluck('email_type', 'email_type')
                            ->mapWithKeys(fn (string $type) => [
                                $type => match ($type) {
                                    'welcome'           => 'Boas-vindas',
                                    'password_reset'    => 'Redefinição de Senha',
                                    'order_confirmed'   => 'Pedido Confirmado',
                                    'order_shipped'     => 'Pedido Enviado',
                                    'invoice'           => 'Nota Fiscal',
                                    default             => ucfirst(str_replace('_', ' ', $type)),
                                },
                            ])
                            ->toArray();
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
                Tables\Actions\Action::make('reenviar')
                    ->label('Reenviar')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Reenviar e-mail?')
                    ->modalDescription('O registro atual será removido e um novo e-mail será disparado para o usuário.')
                    ->modalSubmitActionLabel('Sim, reenviar')
                    ->visible(fn (EmailLog $record): bool => in_array($record->status, [
                        EmailStatus::Failed,
                        EmailStatus::Sent,
                        EmailStatus::Bounced,
                    ]))
                    ->action(function (EmailLog $record): void {
                        $user = $record->user;

                        if (! $user) {
                            Notification::make()
                                ->title('Usuário não encontrado')
                                ->danger()
                                ->send();

                            return;
                        }

                        $record->delete();

                        SendWelcomeEmailJob::dispatch($user);

                        Notification::make()
                            ->title('E-mail re-enfileirado com sucesso!')
                            ->body("Será enviado para {$user->email} em instantes.")
                            ->success()
                            ->send();
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
            'index' => Pages\ListEmailLogs::route('/'),
        ];
    }
}
