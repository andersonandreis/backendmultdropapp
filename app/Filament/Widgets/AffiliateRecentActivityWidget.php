<?php

namespace App\Filament\Widgets;

use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\AffiliateReferral;
use App\Models\AffiliateWithdrawal;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Collection;

/**
 * SEL-345: Widget de atividade recente de afiliados.
 * Mostra os ultimos 10 eventos: novo cadastro, upgrade convertido,
 * saque solicitado, saque pago.
 * Inspirado no RecentTransactions do Tokfy.
 */
class AffiliateRecentActivityWidget extends BaseWidget
{
    protected static ?int $sort = 6;
    protected int|string|array $columnSpan = 'full';
    protected static ?string $heading = 'Atividade Recente — Afiliados';

    public static function canView(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'admin']);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                // Union-like via commissions as base (mais relevante para financeiro)
                AffiliateCommission::query()
                    ->with(['affiliate.user'])
                    ->latest()
                    ->limit(10)
            )
            ->columns([
                Tables\Columns\TextColumn::make('affiliate.user.name')
                    ->label('Afiliado')
                    ->default(fn ($record) => $record->affiliate?->application_name ?? 'Desconhecido')
                    ->searchable(false),

                Tables\Columns\BadgeColumn::make('event_type')
                    ->label('Evento')
                    ->colors([
                        'success' => 'upgrade',
                        'info'    => 'recurring',
                        'warning' => 'signup',
                    ])
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'upgrade'   => 'Primeiro upgrade',
                        'recurring' => 'Renovacao',
                        'signup'    => 'Cadastro',
                        default     => $state,
                    }),

                Tables\Columns\TextColumn::make('plan_slug')
                    ->label('Plano')
                    ->default('-'),

                Tables\Columns\TextColumn::make('commission_amount')
                    ->label('Comissao')
                    ->money('BRL')
                    ->color('success'),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'warning' => 'pending',
                        'info'    => 'approved',
                        'success' => 'paid',
                        'danger'  => 'cancelled',
                    ])
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'pending'   => 'Pendente',
                        'approved'  => 'Aprovada',
                        'paid'      => 'Paga',
                        'cancelled' => 'Cancelada',
                        default     => $state,
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Data')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Aprovar')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn ($record) => $record->status === 'pending')
                    ->action(fn ($record) => $record->update(['status' => 'approved'])),
            ])
            ->paginated(false);
    }
}
