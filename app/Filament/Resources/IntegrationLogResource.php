<?php

namespace App\Filament\Resources;

use App\Filament\Resources\IntegrationLogResource\Pages;
use App\Models\IntegrationLog;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * HUB-032 — Pagina unificada de logs de integracoes.
 *
 * Mostra TODAS as chamadas inbound/outbound de qualquer integracao
 * (Pagar.me, Mercado Livre, Shopee, Bling, Chatwoot, OpenAI, WL relay,
 * Bunny, etc) em uma unica visualizacao com filtros.
 */
class IntegrationLogResource extends Resource
{
    protected static ?string $model = IntegrationLog::class;
    protected static ?string $slug = 'integration-logs';
    protected static ?string $modelLabel = 'Log de Integracao';
    protected static ?string $pluralModelLabel = 'Logs de Integracoes';

    protected static ?string $navigationIcon = 'heroicon-o-signal';
    protected static ?string $navigationGroup = 'Integracoes';
    protected static ?int $navigationSort = 99;
    protected static ?string $navigationLabel = 'Logs (todas integracoes)';

    public static function canViewAny(): bool
    {
        return auth()->user()?->role === 'super_admin';
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
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Quando')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable()
                    ->since()
                    ->tooltip(fn ($state) => $state?->format('d/m/Y H:i:s')),

                Tables\Columns\TextColumn::make('integration_name')
                    ->label('Integracao')
                    ->badge()
                    ->color('info')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('direction')
                    ->label('Direcao')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'inbound' ? 'success' : 'warning')
                    ->formatStateUsing(fn (string $state): string => $state === 'inbound' ? 'IN' : 'OUT'),

                Tables\Columns\TextColumn::make('method')
                    ->label('Metodo')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('url')
                    ->label('URL / Recurso')
                    ->limit(60)
                    ->tooltip(fn ($state) => $state)
                    ->searchable(),

                Tables\Columns\TextColumn::make('status_code')
                    ->label('HTTP')
                    ->badge()
                    ->color(function ($state): string {
                        if ($state === null) return 'gray';
                        if ($state >= 200 && $state < 300) return 'success';
                        if ($state >= 300 && $state < 400) return 'info';
                        if ($state >= 400 && $state < 500) return 'warning';
                        if ($state >= 500) return 'danger';
                        return 'gray';
                    })
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn ($state): string => match (true) {
                        in_array($state, ['success', 'processed', 'sent']) => 'success',
                        in_array($state, ['failed', 'dead', 'error']) => 'danger',
                        in_array($state, ['warning']) => 'warning',
                        default => 'gray',
                    })
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('response_time_ms')
                    ->label('ms')
                    ->numeric()
                    ->alignEnd()
                    ->color(fn ($state): string => $state > 5000 ? 'danger' : ($state > 2000 ? 'warning' : 'gray'))
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('tenant_slug')
                    ->label('Tenant')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('related_resource_type')
                    ->label('Recurso')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('related_resource_id')
                    ->label('ID Recurso')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('correlation_id')
                    ->label('Correlation')
                    ->limit(20)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('error_message')
                    ->label('Erro')
                    ->limit(60)
                    ->tooltip(fn ($state) => $state)
                    ->color('danger')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('integration_name')
                    ->label('Integracao')
                    ->options(function () {
                        return IntegrationLog::query()
                            ->select('integration_name')
                            ->distinct()
                            ->orderBy('integration_name')
                            ->pluck('integration_name', 'integration_name')
                            ->toArray();
                    })
                    ->searchable(),

                Tables\Filters\SelectFilter::make('direction')
                    ->label('Direcao')
                    ->options([
                        'inbound'  => 'Inbound (recebido)',
                        'outbound' => 'Outbound (enviado)',
                    ]),

                Tables\Filters\SelectFilter::make('tenant_slug')
                    ->label('Tenant')
                    ->options(function () {
                        return IntegrationLog::query()
                            ->select('tenant_slug')
                            ->whereNotNull('tenant_slug')
                            ->distinct()
                            ->pluck('tenant_slug', 'tenant_slug')
                            ->toArray();
                    }),

                Tables\Filters\Filter::make('status_code_range')
                    ->label('Status HTTP')
                    ->form([
                        Forms\Components\Select::make('faixa')
                            ->options([
                                '2xx' => 'Sucesso (2xx)',
                                '3xx' => 'Redirect (3xx)',
                                '4xx' => 'Erro cliente (4xx)',
                                '5xx' => 'Erro servidor (5xx)',
                                'sem' => 'Sem codigo HTTP',
                            ]),
                    ])
                    ->query(function (Builder $q, array $data): Builder {
                        return match ($data['faixa'] ?? null) {
                            '2xx' => $q->whereBetween('status_code', [200, 299]),
                            '3xx' => $q->whereBetween('status_code', [300, 399]),
                            '4xx' => $q->whereBetween('status_code', [400, 499]),
                            '5xx' => $q->where('status_code', '>=', 500),
                            'sem' => $q->whereNull('status_code'),
                            default => $q,
                        };
                    }),

                Tables\Filters\Filter::make('apenas_erros')
                    ->label('Apenas falhas')
                    ->toggle()
                    ->query(fn (Builder $q) => $q->failed()),

                Tables\Filters\Filter::make('periodo')
                    ->form([
                        Forms\Components\DatePicker::make('de')->label('De'),
                        Forms\Components\DatePicker::make('ate')->label('Ate'),
                    ])
                    ->query(function (Builder $q, array $data): Builder {
                        return $q
                            ->when($data['de'],  fn ($qq, $v) => $qq->whereDate('created_at', '>=', $v))
                            ->when($data['ate'], fn ($qq, $v) => $qq->whereDate('created_at', '<=', $v));
                    })
                    ->label('Periodo'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->modalHeading(fn ($record) => sprintf(
                        '%s — %s %s',
                        $record->integration_name,
                        strtoupper($record->direction),
                        $record->status_code ?? ''
                    ))
                    ->modalContent(fn ($record) => view('filament.integration-log-view', ['record' => $record]))
                    ->modalWidth('5xl'),
            ])
            ->bulkActions([])
            ->paginated([25, 50, 100, 250])
            ->poll('30s');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIntegrationLogs::route('/'),
        ];
    }
}
