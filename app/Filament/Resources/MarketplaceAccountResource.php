<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MarketplaceAccountResource\Pages;
use App\Models\HubMarketplaceAccount;
use App\Models\MarketplaceAccount;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MUL-159 — Lojas Conectadas.
 *
 * Fonte de dados: env MARKETPLACE_LIST_SOURCE (SEL-078)
 *   • 'hub'   (default) — leitura de hub_readonly (HubMarketplaceAccount), filtrado por HUB_SUPPLIER_ID.
 *                         Usado por multdrop/hub que compartilham banco central.
 *   • 'local' — leitura de MarketplaceAccount (banco local do tenant). Usado por WLs isolados
 *               (seller.global) que gravam OAuth localmente e nao querem vazamento do hub.
 *
 * Escrita: HTTP bridge -> api.hubai.io/internal/marketplace-accounts/{id}/*.
 * Auth bridge: X-Internal-Key (INTERNAL_BRIDGE_KEY) + X-Supplier-Id.
 * NAO oferece Criar/Editar/Deletar — OAuth flow gerencia ciclo de vida.
 */
class MarketplaceAccountResource extends Resource
{
    protected static ?string $model = HubMarketplaceAccount::class;

    /**
     * SEL-078: retorna model configurado (local ou hub) baseado em env.
     * Extraido pra evitar duplicar env('MARKETPLACE_LIST_SOURCE') em varios pontos.
     */
    private static function isLocalSource(): bool
    {
        return env('MARKETPLACE_LIST_SOURCE', 'hub') === 'local';
    }

    protected static ?string $slug             = 'lojas-conectadas';
    protected static ?string $modelLabel       = 'Loja Conectada';
    protected static ?string $pluralModelLabel = 'Lojas Conectadas';
    protected static ?string $navigationIcon   = 'heroicon-o-globe-alt';
    protected static ?string $navigationGroup  = 'Integracoes';
    protected static ?int    $navigationSort   = 1;

    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin']);
    }

    // Sem criacao/edicao/delecao — hub e fonte da verdade
    public static function canCreate(): bool { return false; }
    public static function canEdit($record): bool { return false; }
    public static function canDelete($record): bool { return false; }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    // ─── Bridge Helper ───────────────────────────────────────────────────────

    private static function callBridge(string $method, int $id, string $action): array
    {
        $baseUrl    = rtrim(env('OAUTH_RELAY_URL', 'https://api.hubai.io'), '/');
        $bridgeKey  = env('INTERNAL_BRIDGE_KEY', '');
        $supplierId = (int) env('HUB_SUPPLIER_ID', 0);

        $url = "{$baseUrl}/api/internal/marketplace-accounts/{$id}/{$action}";

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'X-Internal-Key' => $bridgeKey,
                    'X-Supplier-Id'  => (string) $supplierId,
                    'X-Caller'       => 'multdrop-filament-admin',
                    'Accept'         => 'application/json',
                ])
                ->{$method}($url);

            return [
                'ok'     => $response->successful(),
                'status' => $response->status(),
                'body'   => $response->json(),
            ];
        } catch (\Throwable $e) {
            Log::error('[MUL-159 Bridge] callBridge failed', [
                'url'     => $url,
                'message' => $e->getMessage(),
            ]);

            return [
                'ok'     => false,
                'status' => 0,
                'body'   => ['error' => $e->getMessage()],
            ];
        }
    }

    // ─── Table ───────────────────────────────────────────────────────────────

    public static function table(Table $table): Table
    {
        // SEL-078: escolhe model conforme MARKETPLACE_LIST_SOURCE.
        // Local (WL isolado) usa MarketplaceAccount do banco proprio; hub (default) usa hub_readonly.
        $baseQuery = self::isLocalSource()
            ? MarketplaceAccount::query()->whereIn('marketplace_accounts.platform', ['bling', 'shopee', 'mercadolivre'])
            : HubMarketplaceAccount::query();

        return $table
            ->query(
                $baseQuery
                    ->join('clients as c', 'c.id', '=', 'marketplace_accounts.client_id')
                    ->join('users as u', 'u.id', '=', 'c.user_id')
                    ->select([
                        'marketplace_accounts.*',
                        // MUL-269 fase 2: clients.company_name removido; nome vem do user.
                        \Illuminate\Support\Facades\DB::raw("COALESCE(NULLIF(u.full_name,''), u.name, u.email) as client_display_name"),
                        'u.email as client_email',
                    ])
            )
            ->defaultSort('marketplace_accounts.updated_at', 'desc')
            ->columns([
                // Lojista
                Tables\Columns\TextColumn::make('client_display_name')
                    ->label('Lojista')
                    ->description(fn ($record) => $record->client_email ?? '')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        // MUL-269 fase 2: busca no user (clients.company_name removido).
                        return $query->where(function ($q) use ($search) {
                            $q->where('u.full_name', 'like', "%{$search}%")
                              ->orWhere('u.email', 'like', "%{$search}%")
                              ->orWhere('u.name', 'like', "%{$search}%");
                        });
                    })
                    ->placeholder('—')
                    ->copyable()
                    ->copyableState(fn ($record) => $record->client_email ?? ''),

                // Plataforma
                Tables\Columns\TextColumn::make('platform')
                    ->label('Plataforma')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'mercadolivre' => 'warning',
                        'shopee'       => 'danger',
                        'bling'        => 'info',
                        default        => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'mercadolivre' => 'Mercado Livre',
                        'shopee'       => 'Shopee',
                        'bling'        => 'Bling',
                        default        => ucfirst($state),
                    }),

                // Conta / Loja
                Tables\Columns\TextColumn::make('seller_identifier')
                    ->label('Conta / Loja')
                    ->description(fn ($record) => $record->account_name ?? '')
                    ->getStateUsing(fn ($record) => $record->seller_identifier ?? '—')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function ($q) use ($search) {
                            $q->where('marketplace_accounts.ml_user_id', 'like', "%{$search}%")
                              ->orWhere('marketplace_accounts.shop_id', 'like', "%{$search}%")
                              ->orWhere('marketplace_accounts.seller_id', 'like', "%{$search}%")
                              ->orWhere('marketplace_accounts.seller_nickname', 'like', "%{$search}%")
                              ->orWhere('marketplace_accounts.account_name', 'like', "%{$search}%");
                        });
                    })
                    ->copyable()
                    ->fontFamily('mono')
                    ->placeholder('—'),

                // Status
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'active'       => 'Ativo',
                        'needs_reauth' => 'Reconectar',
                        'expired'      => 'Expirado',
                        'inactive'     => 'Inativo',
                        'disconnected' => 'Desconectado',
                        'pending'      => 'Pendente',
                        default        => ucfirst($state),
                    })
                    ->color(fn (string $state) => match ($state) {
                        'active'       => 'success',
                        'needs_reauth' => 'danger',
                        'expired'      => 'warning',
                        'inactive'     => 'gray',
                        'disconnected' => 'danger',
                        'pending'      => 'warning',
                        default        => 'gray',
                    }),

                // Token expira em
                Tables\Columns\TextColumn::make('token_expires_display')
                    ->label('Token expira em')
                    ->getStateUsing(function ($record) {
                        $dt = $record->ml_token_expires_at
                            ?? $record->token_expires_at
                            ?? $record->bling_token_expires_at;
                        if (! $dt) {
                            return null;
                        }
                        return Carbon::parse($dt)->diffForHumans();
                    })
                    ->tooltip(fn ($record) => (function ($record) {
                        $dt = $record->ml_token_expires_at
                            ?? $record->token_expires_at
                            ?? $record->bling_token_expires_at;
                        if (! $dt) {
                            return 'Sem informacao de expiracao';
                        }
                        return Carbon::parse($dt)->format('d/m/Y H:i:s');
                    })($record))
                    ->badge()
                    ->color(function ($record) {
                        $dt = $record->ml_token_expires_at
                            ?? $record->token_expires_at
                            ?? $record->bling_token_expires_at;
                        if (! $dt) {
                            return 'gray';
                        }
                        $parsed = Carbon::parse($dt);
                        if ($parsed->isPast()) {
                            return 'danger';
                        }
                        if ($parsed->isBefore(now()->addHours(2))) {
                            return 'warning';
                        }
                        return 'success';
                    })
                    ->placeholder('—'),

                // Ultima sync
                Tables\Columns\TextColumn::make('last_sync_at')
                    ->label('Ultima sync')
                    ->since()
                    ->placeholder('Nunca')
                    ->sortable()
                    ->toggleable(),

                // Erros
                Tables\Columns\TextColumn::make('sync_errors_count')
                    ->label('Erros')
                    ->badge()
                    ->color(fn ($state) => (int) $state > 0 ? 'danger' : 'gray')
                    ->placeholder('0')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Atualizado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('platform')
                    ->label('Plataforma')
                    ->options([
                        'mercadolivre' => 'Mercado Livre',
                        'shopee'       => 'Shopee',
                        'bling'        => 'Bling',
                    ])
                    ->query(fn (Builder $query, array $data) =>
                        $data['value'] ? $query->where('marketplace_accounts.platform', $data['value']) : $query
                    ),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'active'       => 'Ativo',
                        'needs_reauth' => 'Reconectar',
                        'expired'      => 'Expirado',
                        'inactive'     => 'Inativo',
                    ])
                    ->query(fn (Builder $query, array $data) =>
                        $data['value'] ? $query->where('marketplace_accounts.status', $data['value']) : $query
                    ),
            ])
            ->actions([
                // Ver Detalhes — modal com dados do hub sem tokens sensiveis
                Tables\Actions\Action::make('ver_detalhes')
                    ->label('Detalhes')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading(fn ($record) => 'Conta #' . $record->id . ' — ' . ($record->account_name ?? $record->seller_identifier ?? ''))
                    ->modalContent(function ($record) {
                        $result = static::callBridge('get', $record->id, 'show');

                        if (! $result['ok']) {
                            $errorMsg = $result['body']['error'] ?? 'unknown';
                            $statusCode = $result['status'];
                            return new \Illuminate\Support\HtmlString(
                                '<div class="p-4 bg-red-50 rounded text-red-700">'
                                . '<strong>Erro ao buscar dados do hub</strong><br>'
                                . 'Codigo HTTP: ' . $statusCode . '<br>'
                                . 'Erro: ' . htmlspecialchars($errorMsg)
                                . '</div>'
                            );
                        }

                        $data = $result['body']['data'] ?? [];
                        $rows = '';
                        foreach ($data as $key => $value) {
                            if (is_null($value)) continue;
                            $displayValue = is_bool($value) ? ($value ? 'Sim' : 'Nao') : (string) $value;
                            $rows .= '<tr><td class="px-3 py-1 font-mono text-xs text-gray-500 border-b">'
                                . htmlspecialchars($key)
                                . '</td><td class="px-3 py-1 text-sm border-b">'
                                . htmlspecialchars($displayValue)
                                . '</td></tr>';
                        }
                        return new \Illuminate\Support\HtmlString(
                            '<div class="overflow-auto max-h-96">'
                            . '<table class="w-full text-left border-collapse"><tbody>'
                            . $rows
                            . '</tbody></table></div>'
                        );
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fechar'),

                // Renovar Token — chama POST /internal/marketplace-accounts/{id}/refresh
                Tables\Actions\Action::make('renovar_token')
                    ->label('Renovar Token')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Renovar Token')
                    ->modalDescription(fn ($record) =>
                        'Tentara renovar o token da conta #' . $record->id . ' (' . $record->platform . ') via hub. '
                        . "Se o refresh_token tambem estiver expirado, a conta ficara como 'Reconectar'."
                    )
                    ->action(function ($record) {
                        $result = static::callBridge('post', $record->id, 'refresh');

                        if ($result['ok'] && ($result['body']['success'] ?? false)) {
                            Notification::make()
                                ->title('Token renovado com sucesso')
                                ->body('Status: ' . ($result['body']['status'] ?? 'active'))
                                ->success()
                                ->send();
                        } else {
                            $errMsg = $result['body']['message']
                                ?? $result['body']['error']
                                ?? 'Erro desconhecido';

                            Notification::make()
                                ->title('Falha ao renovar token')
                                ->body($errMsg)
                                ->danger()
                                ->send();

                            Log::warning('[MUL-159] renovar_token failed', [
                                'account_id' => $record->id,
                                'result'     => $result,
                            ]);
                        }
                    }),

                // Marcar Needs Reauth — forcar reconexao manual
                Tables\Actions\Action::make('marcar_reauth')
                    ->label('Marcar Reconectar')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Marcar como "Reconectar"')
                    ->modalDescription(fn ($record) =>
                        'Isso marcara a conta #' . $record->id . " como 'needs_reauth'. "
                        . 'O lojista vera o aviso para reconectar no painel dele.'
                    )
                    ->action(function ($record) {
                        $result = static::callBridge('post', $record->id, 'mark-reauth');

                        if ($result['ok'] && ($result['body']['success'] ?? false)) {
                            Notification::make()
                                ->title('Conta marcada para reconexao')
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('Falha ao marcar reconexao')
                                ->body($result['body']['error'] ?? 'Erro desconhecido')
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->bulkActions([])
            ->emptyStateHeading('Nenhuma loja conectada')
            ->emptyStateDescription(
                'HUB_SUPPLIER_ID=' . env('HUB_SUPPLIER_ID', '?') . '. '
                . 'Lojistas conectam contas via OAuth no painel deles.'
            )
            ->paginated([25, 50, 100]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMarketplaceAccounts::route('/'),
        ];
    }
}
