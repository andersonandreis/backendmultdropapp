<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FederationSyncLogResource\Pages;
use App\Models\FederationSyncLog;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * FederationSyncLogResource -- NOV-171-E
 *
 * Painel de visibilidade da federacao hub<->WL no /admin.
 * Exibe logs de sincronizacao de produtos e status de pedidos
 * registrados em federation_sync_log (hubaiapp apenas).
 *
 * Acesso: apenas super_admin (nao expor para fornecedores).
 * Grupo de navegacao: Federacao (novo).
 */
class FederationSyncLogResource extends Resource
{
    protected static ?string $model = FederationSyncLog::class;

    protected static ?string $slug = 'federation-sync-logs';
    protected static ?string $modelLabel = 'Log de Federacao';
    protected static ?string $pluralModelLabel = 'Logs de Federacao';

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';
    protected static ?string $navigationGroup = 'Federacao';
    protected static ?int $navigationSort = 1;

    // =========================================================================
    // ACESSO -- apenas super_admin (nunca expor ao supplier)
    // =========================================================================

    public static function canViewAny(): bool
    {
        return auth()->user()?->role === 'super_admin';
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
        return false;
    }

    // =========================================================================
    // FORM -- Detalhe de um log (view-only)
    // =========================================================================

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Identificacao')
                    ->schema([
                        Forms\Components\TextInput::make('direction')
                            ->label('Direcao')
                            ->formatStateUsing(fn (?string $state): string => match ($state) {
                                'hub_to_wl' => 'Hub para WL',
                                'wl_to_hub' => 'WL para Hub',
                                default     => $state ?? '--',
                            })
                            ->disabled(),

                        Forms\Components\TextInput::make('entity_type')
                            ->label('Tipo de Entidade')
                            ->formatStateUsing(fn (?string $state): string => match ($state) {
                                'product'      => 'Produto',
                                'order'        => 'Pedido',
                                'order_status' => 'Status de Pedido',
                                default        => $state ?? '--',
                            })
                            ->disabled(),

                        Forms\Components\TextInput::make('entity_id')
                            ->label('ID da Entidade')
                            ->disabled(),

                        Forms\Components\TextInput::make('target_tenant')
                            ->label('WL Alvo / Origem')
                            ->disabled(),

                        Forms\Components\TextInput::make('status')
                            ->label('Status')
                            ->disabled(),

                        Forms\Components\TextInput::make('created_at')
                            ->label('Data/Hora')
                            ->disabled()
                            ->formatStateUsing(fn ($state): string => $state
                                ? \Carbon\Carbon::parse($state)->format('d/m/Y H:i:s')
                                : '--'),
                    ])->columns(3),

                Forms\Components\Section::make('Detalhes de Erro')
                    ->schema([
                        Forms\Components\Textarea::make('error_message')
                            ->label('Mensagem de Erro')
                            ->disabled()
                            ->columnSpanFull()
                            ->rows(4)
                            ->placeholder('Sem erros registrados'),
                    ])
                    ->visible(fn (?FederationSyncLog $record): bool => filled($record?->error_message))
                    ->collapsible(),

                Forms\Components\Section::make('Hash do Payload')
                    ->schema([
                        Forms\Components\TextInput::make('payload_hash')
                            ->label('SHA-256 do Payload (dedup)')
                            ->disabled()
                            ->placeholder('--'),
                    ])
                    ->collapsed()
                    ->collapsible(),
            ]);
    }

    // =========================================================================
    // TABLE -- Listagem com filtros
    // =========================================================================

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('direction')
                    ->label('Direcao')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'hub_to_wl' => 'Hub > WL',
                        'wl_to_hub' => 'WL > Hub',
                        default     => $state ?? '--',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'hub_to_wl' => 'primary',
                        'wl_to_hub' => 'info',
                        default     => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('entity_type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'product'      => 'Produto',
                        'order'        => 'Pedido',
                        'order_status' => 'Status Pedido',
                        default        => $state ?? '--',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'product'      => 'warning',
                        'order'        => 'success',
                        'order_status' => 'info',
                        default        => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('entity_id')
                    ->label('ID Entidade')
                    ->sortable(),

                Tables\Columns\TextColumn::make('target_tenant')
                    ->label('WL')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'multdrop'    => 'success',
                        'fornecefy'   => 'warning',
                        'mestoredrop' => 'danger',
                        default       => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'success' => 'Sucesso',
                        'failed'  => 'Erro',
                        'skipped' => 'Ignorado',
                        default   => $state ?? '--',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'success' => 'success',
                        'failed'  => 'danger',
                        'skipped' => 'gray',
                        default   => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('error_message')
                    ->label('Erro')
                    ->limit(60)
                    ->placeholder('--')
                    ->tooltip(fn (Tables\Columns\TextColumn $column): ?string => $column->getState())
                    ->color('danger')
                    ->toggleable(isToggledHiddenByDefault: false),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Data/Hora')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable()
                    ->timezone('America/Sao_Paulo'),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginationPageOptions([50, 100, 250])
            ->defaultPaginationPageOption(50)
            ->filters([
                Tables\Filters\SelectFilter::make('direction')
                    ->label('Direcao')
                    ->options([
                        'hub_to_wl' => 'Hub para WL',
                        'wl_to_hub' => 'WL para Hub',
                    ]),

                Tables\Filters\SelectFilter::make('entity_type')
                    ->label('Tipo de Entidade')
                    ->options([
                        'product'      => 'Produto',
                        'order'        => 'Pedido',
                        'order_status' => 'Status de Pedido',
                    ]),

                Tables\Filters\SelectFilter::make('target_tenant')
                    ->label('WL')
                    ->options([
                        'multdrop'    => 'MultDrop',
                        'fornecefy'   => 'Fornecefy',
                        'mestoredrop' => 'MEStoreDrop',
                    ]),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'success' => 'Sucesso',
                        'failed'  => 'Erro',
                        'skipped' => 'Ignorado',
                    ]),

                Tables\Filters\Filter::make('created_at')
                    ->label('Periodo')
                    ->form([
                        Forms\Components\DatePicker::make('from')
                            ->label('De')
                            ->displayFormat('d/m/Y'),
                        Forms\Components\DatePicker::make('until')
                            ->label('Ate')
                            ->displayFormat('d/m/Y'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'], fn ($q) => $q->whereDate('created_at', '>=', $data['from']))
                            ->when($data['until'], fn ($q) => $q->whereDate('created_at', '<=', $data['until']));
                    }),

                Tables\Filters\Filter::make('apenas_erros')
                    ->label('Apenas Erros')
                    ->query(fn (Builder $query): Builder => $query->where('status', 'failed'))
                    ->toggle(),
            ])
            ->filtersLayout(Tables\Enums\FiltersLayout::AboveContent)
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('Ver Detalhe'),
            ])
            ->bulkActions([])
            ->headerActions([])
            ->poll('30s');
    }

    // =========================================================================
    // PAGES
    // =========================================================================

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFederationSyncLogs::route('/'),
            'view'  => Pages\ViewFederationSyncLog::route('/{record}'),
        ];
    }
}
