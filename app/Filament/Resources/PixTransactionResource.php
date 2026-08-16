<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PixTransactionResource\Pages;
use App\Filament\Resources\PixTransactionResource\Widgets\PixStatsWidget;
use App\Models\PixTransaction;
use App\Services\Financial\PixService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PixTransactionResource extends Resource
{
    protected static ?string $model = PixTransaction::class;
    protected static ?string $slug = 'pix-transacoes';
    protected static ?string $modelLabel = 'Transação PIX';
    protected static ?string $pluralModelLabel = 'Transações PIX';

    protected static ?string $navigationIcon = 'heroicon-o-qr-code';
    protected static ?string $navigationGroup = 'Financeiro';
    protected static ?int $navigationSort = 20;

    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'supplier']);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user?->role === 'supplier' && $user->supplier) {
            $query->where('supplier_id', $user->supplier->id);
        }

        return $query;
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
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('supplier.company_name')
                    ->label('Fornecedor')
                    ->searchable()
                    ->sortable(),

                // MUL-269 fase 2: client.company_name via accessor; busca no user conectado.
                Tables\Columns\TextColumn::make('client.company_name')
                    ->label('Cliente')
                    ->searchable(query: fn ($query, $search) => $query->whereHas('client.user', fn ($u) => $u->where('name', 'like', "%{$search}%")->orWhere('full_name', 'like', "%{$search}%"))),

                Tables\Columns\TextColumn::make('order_id')
                    ->label('Pedido')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'order_payment' => 'info',
                        'wallet_topup'  => 'success',
                        default         => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'order_payment' => 'Pagamento de Pedido',
                        'wallet_topup'  => 'Recarga de Wallet',
                        default         => $state,
                    }),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Valor')
                    ->money('BRL')
                    ->sortable(),

                Tables\Columns\TextColumn::make('fee_amount')
                    ->label('Taxa')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('net_amount')
                    ->label('Líquido')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'paid'     => 'success',
                        'pending'  => 'warning',
                        'expired'  => 'danger',
                        'failed'   => 'danger',
                        default    => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'paid'    => 'Pago',
                        'pending' => 'Pendente',
                        'expired' => 'Expirado',
                        'failed'  => 'Falhou',
                        default   => $state,
                    }),

                Tables\Columns\TextColumn::make('gateway')
                    ->label('Gateway')
                    ->badge()
                    ->color('primary')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Expira em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('paid_at')
                    ->label('Pago em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Pendente',
                        'paid'    => 'Pago',
                        'expired' => 'Expirado',
                        'failed'  => 'Falhou',
                    ]),

                Tables\Filters\SelectFilter::make('type')
                    ->label('Tipo')
                    ->options([
                        'order_payment' => 'Pagamento de Pedido',
                        'wallet_topup'  => 'Recarga de Wallet',
                    ]),

                Tables\Filters\SelectFilter::make('gateway')
                    ->label('Gateway')
                    ->options([
                        'asaas'  => 'Asaas',
                        'shipay' => 'Shipay',
                        'pagarme' => 'Pagar.me',
                    ]),

                Tables\Filters\SelectFilter::make('supplier')
                    ->label('Fornecedor')
                    ->relationship('supplier', 'company_name'),

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
            ->searchPlaceholder('Buscar por ID externo ou pedido...')
            ->actions([
                Tables\Actions\Action::make('sincronizar')
                    ->label('Sincronizar Status')
                    ->icon('heroicon-o-arrow-path')
                    ->color('info')
                    ->visible(fn (PixTransaction $record) => $record->status === 'pending')
                    ->action(function (PixTransaction $record) {
                        try {
                            app(PixService::class)->syncPixStatus($record);
                            Notification::make()
                                ->title('Status sincronizado!')
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Erro ao sincronizar')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('confirmar_manual')
                    ->label('Confirmar Manualmente')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (PixTransaction $record) => $record->status !== 'paid')
                    ->form([
                        Forms\Components\Textarea::make('observacao')
                            ->label('Observação (obrigatória)')
                            ->required()
                            ->rows(3)
                            ->placeholder('Motivo da confirmação manual (ex.: comprovante recebido fora do gateway)'),
                    ])
                    ->action(function (PixTransaction $record, array $data): void {
                        $record->update([
                            'status'                   => 'paid',
                            'paid_at'                  => now(),
                            'manually_confirmed_at'    => now(),
                            'confirmed_by_user_id'     => auth()->id(),
                            'manual_confirmation_note' => $data['observacao'] ?? null,
                        ]);

                        Notification::make()
                            ->title('Transação confirmada manualmente')
                            ->body("PIX #{$record->id} marcada como paga por " . (auth()->user()?->name ?? 'admin') . '.')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('expirar')
                    ->label('Expirar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (PixTransaction $record) => $record->status === 'pending')
                    ->action(function (PixTransaction $record) {
                        $record->markAsExpired();
                        Notification::make()
                            ->title('Transação marcada como expirada.')
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getWidgets(): array
    {
        return [
            PixStatsWidget::class,
        ];
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPixTransactions::route('/'),
        ];
    }
}
