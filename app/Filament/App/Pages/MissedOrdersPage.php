<?php

namespace App\Filament\App\Pages;

use App\Jobs\ReconcileMarketplaceOrdersJob;
use App\Models\ClientProduct;
use App\Models\MissedOrderAlert;
use App\Services\Marketplace\Reconciliation\MissedOrderDetectionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Wizard;
use Filament\Forms\Components\Wizard\Step;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action as TableAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;

class MissedOrdersPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';
    protected static ?string $navigationLabel = 'Vendas Perdidas';
    protected static ?string $title = 'Vendas Possivelmente Nao Capturadas';
    protected static ?string $slug = 'vendas-perdidas';
    protected static ?int $navigationSort = 25;
    protected static string $view = 'filament.app.pages.missed-orders';

    // -------------------------------------------------------------------------
    // Elemento 1: Badge na sidebar
    // -------------------------------------------------------------------------

    public static function getNavigationBadge(): ?string
    {
        $client = auth()->user()?->client;
        if (! $client) {
            return null;
        }
        if (static::isStartPlan()) {
            return null;
        }
        $count = MissedOrderAlert::forClient($client->id)->pending()->count();
        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    // -------------------------------------------------------------------------
    // Elemento 1: Icone variavel na sidebar
    // -------------------------------------------------------------------------

    public static function getNavigationIcon(): string|Htmlable|null
    {
        if (static::isStartPlan()) {
            return 'heroicon-o-lock-closed';
        }
        return 'heroicon-o-exclamation-triangle';
    }

    // -------------------------------------------------------------------------
    // Elemento 1: canAccess — qualquer cliente autenticado (Start ve upsell)
    // -------------------------------------------------------------------------

    public static function canAccess(): bool
    {
        return auth()->user()?->client !== null;
    }

    // -------------------------------------------------------------------------
    // Helpers de plano
    // -------------------------------------------------------------------------

    protected static function isStartPlan(): bool
    {
        $client = auth()->user()?->client;
        if (! $client) {
            return true;
        }
        return $client->isStartPlan();
    }

    protected function isScalePlan(): bool
    {
        $client = auth()->user()?->client;
        if (! $client) {
            return false;
        }

        return $client->isScalePlan();
    }

    protected function getPlanName(): string
    {
        $client = auth()->user()?->client;
        if (! $client) {
            return 'Start R$29,90';
        }
        $subscription = $client->subscriptions()
            ->whereIn('status', ['active', 'trialing'])
            ->with('plan')
            ->latest()
            ->first();
        return $subscription?->plan?->name ?? 'Start R$29,90';
    }

    // -------------------------------------------------------------------------
    // Elemento 4: mount() sem redirect — Start acessa e ve upsell no body
    // -------------------------------------------------------------------------

    public function mount(): void
    {
        // Nao redireciona: Start ve card de upsell na blade (ver missed-orders.blade.php)
    }

    // -------------------------------------------------------------------------
    // Throttle helpers (Pro: 3x/dia, Scale: ilimitado)
    // -------------------------------------------------------------------------

    protected function getRefreshCacheKey(): string
    {
        $clientId = auth()->user()?->client?->id;
        return "missed-refresh-{$clientId}-" . now()->toDateString();
    }

    protected function getRefreshCountToday(): int
    {
        return (int) Cache::get($this->getRefreshCacheKey(), 0);
    }

    protected function incrementRefreshCount(): void
    {
        $key     = $this->getRefreshCacheKey();
        $current = (int) Cache::get($key, 0);
        Cache::put($key, $current + 1, now()->endOfDay());
    }

    // -------------------------------------------------------------------------
    // Elemento 3: Header action "Verificar agora" "Verificar agora" com throttle e Job sincrono
    // -------------------------------------------------------------------------

    protected function getHeaderActions(): array
    {
        $isStart      = static::isStartPlan();
        $isScale      = $this->isScalePlan();
        $planName     = $this->getPlanName();
        $refreshCount = $this->getRefreshCountToday();
        $maxRefresh   = 3;
        $canRefresh   = $isScale || $refreshCount < $maxRefresh;

        if ($isStart) {
            return [
                Action::make('verificar_agora')
                    ->label('Verificar Agora')
                    ->icon('heroicon-o-lock-closed')
                    ->color('gray')
                    ->disabled(true)
                    ->tooltip("Disponivel no plano Pro. Seu plano atual: {$planName}.")
                    ->modalHeading('Funcionalidade exclusiva')
                    ->modalDescription('Faca upgrade para o plano Pro e reconcilie pedidos perdidos automaticamente.')
                    ->modalSubmitActionLabel('Ver planos')
                    ->action(fn () => $this->redirect('/app/pricing')),
            ];
        }

        $label = $isScale
            ? 'Verificar Agora'
            : "Verificar Agora ({$refreshCount}/{$maxRefresh})";

        return [
            Action::make('verificar_agora')
                ->label($label)
                ->icon('heroicon-o-arrow-path')
                ->color($canRefresh ? 'primary' : 'gray')
                ->disabled(! $canRefresh)
                ->tooltip(! $canRefresh ? "Limite de {$maxRefresh} verificacoes manuais por dia atingido." : null)
                ->action(function () use ($canRefresh) {
                    if (! $canRefresh) {
                        Notification::make()
                            ->title('Limite diario atingido')
                            ->body('Voce ja verificou 3 vezes hoje. O sistema verifica automaticamente a cada hora.')
                            ->danger()
                            ->send();
                        return;
                    }

                    $clientId = auth()->user()?->client?->id;
                    if (! $clientId) {
                        return;
                    }

                    // Verificar se ha contas de marketplace conectadas antes de rodar
                    $hasAccounts = \App\Models\MarketplaceAccount::where('client_id', $clientId)
                        ->whereIn('status', ['active'])
                        ->exists();

                    if (! $hasAccounts) {
                        Notification::make()
                            ->title('Nenhuma integracao conectada')
                            ->body('Voce ainda nao tem uma loja de marketplace ativa. Acesse "Minhas Lojas" para conectar Mercado Livre, Shopee ou outra plataforma antes de verificar.')
                            ->warning()
                            ->persistent()
                            ->send();
                        return;
                    }

                    try {
                        $job     = new ReconcileMarketplaceOrdersJob($clientId);
                        $service = app(MissedOrderDetectionService::class);
                        $job->handle($service);
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Erro ao verificar')
                            ->body('Nao foi possivel completar: ' . $e->getMessage())
                            ->danger()
                            ->send();
                        return;
                    }

                    $this->incrementRefreshCount();

                    Notification::make()
                        ->title('Verificacao concluida')
                        ->body('Busca finalizada. Novos alertas foram adicionados a lista se houver.')
                        ->success()
                        ->send();

                    $this->resetTable();
                }),
        ];
    }

    // -------------------------------------------------------------------------
    // Elemento 2: Tabela com 4 row actions (tenant-isolated)
    // -------------------------------------------------------------------------

    public function table(Table $table): Table
    {
        $clientId = auth()->user()?->client?->id;

        return $table
            ->query(
                MissedOrderAlert::query()
                    ->when($clientId, fn (Builder $q) => $q->forClient($clientId)->pending())
                    ->latest('detected_at')
            )
            ->emptyStateHeading('Nenhum alerta pendente')
            ->emptyStateDescription('Otimo! Nao identificamos vendas possivelmente perdidas no momento.')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->columns([
                TextColumn::make('marketplace')
                    ->label('Marketplace')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'shopee'       => 'warning',
                        'mercadolivre' => 'info',
                        'bling'        => 'primary',
                        default        => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'shopee'       => 'Shopee',
                        'mercadolivre' => 'Mercado Livre',
                        'bling'        => 'Bling',
                        default        => ucfirst($state),
                    }),

                TextColumn::make('marketplace_order_id')
                    ->label('Pedido Marketplace')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('ID copiado')
                    ->fontFamily('mono'),

                TextColumn::make('buyer_name')
                    ->label('Comprador')
                    ->default('—')
                    ->searchable(),

                TextColumn::make('amount_cents')
                    ->label('Valor')
                    ->formatStateUsing(fn (int $state): string =>
                        'R$ ' . number_format($state / 100, 2, ',', '.')
                    )
                    ->sortable(),

                TextColumn::make('detected_at')
                    ->label('Detectado')
                    ->since()
                    ->sortable()
                    ->tooltip(fn ($record) => $record->detected_at?->format('d/m/Y H:i')),
            ])
            ->actions([
                TableAction::make('registrar_manualmente')
                    ->label('Registrar')
                    ->icon('heroicon-o-plus-circle')
                    ->color('primary')
                    ->url(fn ($record) => '/app/orders?fromAlert=' . $record->id),

                TableAction::make('ja_registrei')
                    ->label('Ja Registrei')
                    ->icon('heroicon-o-check')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Confirmar dispensar alerta')
                    ->modalDescription('Voce confirma que este pedido ja foi registrado no sistema?')
                    ->action(fn ($record) => $this->dismissAlert($record, 'already_registered')),

                TableAction::make('nao_e_minha')
                    ->label('Nao e Minha')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->outlined()
                    ->requiresConfirmation()
                    ->modalHeading('Confirmar: nao e minha venda')
                    ->modalDescription('Esta venda pertence a outra conta ou loja? O alerta sera descartado.')
                    ->action(fn ($record) => $this->dismissAlert($record, 'not_mine')),

                TableAction::make('ignorar')
                    ->label('Ignorar')
                    ->icon('heroicon-o-archive-box-x-mark')
                    ->color('gray')
                    ->outlined()
                    ->requiresConfirmation()
                    ->modalHeading('Ignorar alerta')
                    ->modalDescription('O alerta sera descartado sem registro. Isso nao afeta suas vendas.')
                    ->action(fn ($record) => $this->dismissAlert($record, 'other')),
            ])
            ->bulkActions([])
            ->striped();
    }

    // -------------------------------------------------------------------------
    // Dismiss helper com tenant isolation explicita
    // -------------------------------------------------------------------------

    protected function dismissAlert(MissedOrderAlert $alert, string $reason): void
    {
        $clientId = auth()->user()?->client?->id;

        MissedOrderAlert::where('id', $alert->id)
            ->where('client_id', $clientId)
            ->update([
                'dismissed_at'   => now(),
                'dismiss_reason' => $reason,
            ]);

        Notification::make()
            ->title('Alerta descartado')
            ->success()
            ->send();

        $this->resetTable();
    }
}
