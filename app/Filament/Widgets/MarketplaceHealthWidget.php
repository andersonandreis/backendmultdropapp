<?php

namespace App\Filament\Widgets;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceHealthCheck;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class MarketplaceHealthWidget extends BaseWidget
{
    protected static ?int $sort = 5;
    protected static bool $isLazy = true;
    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Saude dos Marketplaces';

    public static function canView(): bool
    {
        $user = auth()->user();
        return $user && in_array($user->role, ['super_admin', 'client', 'supplier']);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                MarketplaceHealthCheck::query()
                    ->select('marketplace_health_checks.*')
                    ->joinSub(
                        MarketplaceHealthCheck::query()
                            ->selectRaw('marketplace_account_id, metric, MAX(id) as latest_id')
                            ->groupBy('marketplace_account_id', 'metric'),
                        'latest',
                        'marketplace_health_checks.id',
                        '=',
                        'latest.latest_id'
                    )
                    ->whereHas('marketplaceAccount', function (Builder $q) {
                        $user = auth()->user();
                        if ($user && $user->role !== 'super_admin') {
                            $q->where('client_id', $user->client_id ?? $user->id);
                        }
                    })
            )
            ->columns([
                Tables\Columns\TextColumn::make('marketplaceAccount.account_name')
                    ->label('Conta')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('marketplaceAccount.platform')
                    ->label('Plataforma')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'mercadolivre' => 'warning',
                        'shopee' => 'danger',
                        'tiktok' => 'info',
                        'magalu' => 'primary',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('metric')
                    ->label('Metrica')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'reputation' => 'Reputacao',
                        'claims_rate' => 'Taxa de Reclamacoes',
                        'cancellation_rate' => 'Taxa de Cancelamentos',
                        'late_shipment_rate' => 'Atraso no Envio',
                        'response_time' => 'Tempo de Resposta',
                        default => ucfirst(str_replace('_', ' ', $state)),
                    }),

                Tables\Columns\TextColumn::make('value')
                    ->label('Valor')
                    ->numeric(2),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'healthy' => 'success',
                        'warning' => 'warning',
                        'critical' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'healthy' => 'Saudavel',
                        'warning' => 'Atencao',
                        'critical' => 'Critico',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Ultima Verificacao')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25])
            ->emptyStateHeading('Nenhuma verificacao realizada')
            ->emptyStateDescription('O monitoramento de saude sera executado diariamente as 06:00.')
            ->emptyStateIcon('heroicon-o-heart');
    }
}
