<?php

namespace App\Filament\Widgets;

use App\Models\HubSellerBlingAccount;
use Carbon\Carbon;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * MUL-148B — Widget nativo Filament: contas Bling dos sellers.
 * Fonte: hub_readonly (marketplace_accounts via HubSellerBlingAccount).
 * Read-only. Sem acoes de escrita — seller gerencia OAuth em /integracoes.
 */
class SellerBlingAccountsWidget extends BaseWidget
{
    protected int|string|array $columnSpan = 'full';
    protected static ?string $heading = 'Contas Bling dos Sellers';

    public static function canView(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin']);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                HubSellerBlingAccount::query()
                    ->join('clients as c', 'c.id', '=', 'marketplace_accounts.client_id')
                    ->join('users as u', 'u.id', '=', 'c.user_id')
                    ->select([
                        'marketplace_accounts.id',
                        'marketplace_accounts.account_name',
                        'marketplace_accounts.status',
                        'marketplace_accounts.bling_token_expires_at',
                        'marketplace_accounts.last_sync_at',
                        'marketplace_accounts.updated_at',
                        'c.id as client_id',
                        // MUL-269 fase 2: clients.company_name removido; nome vem do user.
                        \Illuminate\Support\Facades\DB::raw("COALESCE(NULLIF(u.full_name,''), u.name, u.email) as client_name"),
                        'u.email as client_email',
                    ])
            )
            ->columns([
                Tables\Columns\TextColumn::make('client_name')
                    ->label('Lojista')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        // MUL-269 fase 2: busca no user (clients.company_name removido).
                        return $query->where(function ($q) use ($search) {
                            $q->where('u.full_name', 'like', "%{$search}%")
                              ->orWhere('u.name', 'like', "%{$search}%")
                              ->orWhere('u.email', 'like', "%{$search}%");
                        });
                    })
                    ->description(fn ($record) => $record->client_email ?? '')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('account_name')
                    ->label('Conta Bling')
                    ->searchable()
                    ->description(fn ($record) => '#' . $record->id)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'active'       => 'Ativo',
                        'needs_reauth' => 'Precisa Reconectar',
                        'pending'      => 'Pendente',
                        default        => ucfirst($state),
                    })
                    ->color(fn (string $state) => match ($state) {
                        'active'       => 'success',
                        'needs_reauth' => 'danger',
                        'pending'      => 'warning',
                        default        => 'gray',
                    }),

                Tables\Columns\TextColumn::make('token_health')
                    ->label('Token')
                    ->badge()
                    ->state(function ($record): string {
                        if (empty($record->bling_token_expires_at)) {
                            return 'Sem validade';
                        }
                        $exp = Carbon::parse($record->bling_token_expires_at);
                        if ($exp->isPast()) {
                            return 'Expirado';
                        }
                        if ($exp->isBefore(now()->addHours(6))) {
                            return 'Expirando';
                        }
                        return 'Valido';
                    })
                    ->color(function ($record): string {
                        if (empty($record->bling_token_expires_at)) {
                            return 'gray';
                        }
                        $exp = Carbon::parse($record->bling_token_expires_at);
                        if ($exp->isPast()) {
                            return 'danger';
                        }
                        if ($exp->isBefore(now()->addHours(6))) {
                            return 'warning';
                        }
                        return 'success';
                    }),

                Tables\Columns\TextColumn::make('bling_token_expires_at')
                    ->label('Expira em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('last_sync_at')
                    ->label('Ultima sync')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->placeholder('Nunca'),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Atualizado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'active'       => 'Ativo',
                        'needs_reauth' => 'Precisa Reconectar',
                        'pending'      => 'Pendente',
                    ]),
            ])
            ->defaultSort('marketplace_accounts.updated_at', 'desc')
            ->paginated([25, 50, 100])
            ->emptyStateHeading('Nenhuma conta Bling de seller')
            ->emptyStateDescription('HUB_SUPPLIER_ID nao configurado ou sem contas cadastradas.');
    }
}
