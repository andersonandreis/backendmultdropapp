<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupplierMessageLogResource\Pages;
use App\Models\SupplierMessage;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SupplierMessageLogResource extends Resource
{
    protected static ?string $model = SupplierMessage::class;
    protected static ?string $slug = 'mensagens-log';

    protected static ?string $navigationIcon = 'heroicon-o-inbox-stack';
    protected static ?string $navigationGroup = 'Comunicação';
    protected static ?string $navigationLabel = 'Log de Mensagens';
    protected static ?string $modelLabel = 'Mensagem';
    protected static ?string $pluralModelLabel = 'Log de Mensagens';
    protected static ?int $navigationSort = 50;

    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'supplier']);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->role === 'super_admin';
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user?->role === 'supplier' && $user->supplier) {
            $query->where('supplier_id', $user->supplier->id);
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('subject')
                    ->label('Assunto')
                    ->searchable()
                    ->limit(40),

                Tables\Columns\TextColumn::make('recipient_type')
                    ->label('Tipo destinatário')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'client'       => 'Cliente',
                        'all_clients'  => 'Todos clientes',
                        'segment'      => 'Segmento',
                        'admin'        => 'Admin',
                        default        => $state ?? '—',
                    })
                    ->toggleable(),

                Tables\Columns\TextColumn::make('recipient_id')
                    ->label('Destinatário ID')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\BadgeColumn::make('channel')
                    ->label('Canal')
                    ->colors([
                        'info'      => 'email',
                        'success'   => 'whatsapp',
                        'warning'   => 'sms',
                        'primary'   => 'push',
                        'gray'      => 'in_app',
                    ]),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'success' => ['sent', 'delivered', 'read'],
                        'danger'  => 'failed',
                        'warning' => 'pending',
                    ]),

                Tables\Columns\TextColumn::make('recipients_count')
                    ->label('Destinatários')
                    ->badge()
                    ->color('primary')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('delivered_count')
                    ->label('Entregues')
                    ->badge()
                    ->color('success')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('failed_count')
                    ->label('Falhas')
                    ->badge()
                    ->color('danger')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('sent_at')
                    ->label('Enviado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('channel')
                    ->label('Canal')
                    ->options([
                        'email'    => 'E-mail',
                        'whatsapp' => 'WhatsApp',
                        'sms'      => 'SMS',
                        'push'     => 'Push',
                        'in_app'   => 'In-app',
                    ]),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending'   => 'Pendente',
                        'sent'      => 'Enviado',
                        'delivered' => 'Entregue',
                        'read'      => 'Lido',
                        'failed'    => 'Falhou',
                    ]),

                Tables\Filters\Filter::make('periodo')
                    ->label('Período')
                    ->form([
                        Forms\Components\DatePicker::make('de')->label('De'),
                        Forms\Components\DatePicker::make('ate')->label('Até'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['de'],  fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
                            ->when($data['ate'], fn ($q, $v) => $q->whereDate('created_at', '<=', $v));
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->modalContent(fn ($record) => view('filament.partials.supplier-message-view', ['record' => $record])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSupplierMessageLogs::route('/'),
        ];
    }
}
