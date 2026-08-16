<?php

namespace App\Filament\Pages;

use App\Jobs\SyncProductsJob;
use App\Models\Client;
use App\Models\ClientProduct;
use App\Models\Order;
use App\Models\Setting;
use App\Models\SyncLog;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

class BlingExport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-cloud-arrow-up';
    protected static ?string $navigationGroup = 'Integrações';
    protected static ?string $navigationLabel = 'Exportação Bling';
    protected static ?string $title = 'Exportação Bling';
    protected static ?string $slug = 'bling-export';
    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.bling-export';

    /** MUL-226-02: filtro ativo vindo do card clicado (null = todos) */
    public ?string $statusCard = null;

    private const ERROR_STATUSES = ['error', 'invalid_category', 'missing_gtin', 'failed'];
    private const PROCESSING_STATUSES = ['pending', 'syncing', 'queued'];

    public function setStatusCard(?string $status): void
    {
        $this->statusCard = ($this->statusCard === $status) ? null : $status;
        $this->resetPage();
    }

    public function isCatalogFrozen(): bool
    {
        $v = DB::table('settings')->where('key', 'bling_catalog_frozen')->value('value');

        return $v === '1' || $v === 'true';
    }

    public function isOrdersQueueFrozen(): bool
    {
        $v = DB::table('settings')->where('key', 'bling_queue_frozen')->value('value');

        return $v === '1' || $v === 'true';
    }

    /**
     * MUL-226-02: cards de status — mesma base da tabela (client_products em contas Bling),
     * então o número do card bate com a listagem filtrada por construção.
     */
    public function getCardsData(): array
    {
        $counts = ClientProduct::query()
            ->join('marketplace_accounts as ma', 'ma.id', '=', 'client_products.marketplace_account_id')
            ->where('ma.platform', 'bling')
            ->selectRaw("
                SUM(CASE WHEN client_products.sync_status = 'synced' THEN 1 ELSE 0 END) as sucesso,
                SUM(CASE WHEN client_products.sync_status IN ('" . implode("','", self::ERROR_STATUSES) . "') THEN 1 ELSE 0 END) as erro,
                SUM(CASE WHEN client_products.sync_status IN ('" . implode("','", self::PROCESSING_STATUSES) . "') THEN 1 ELSE 0 END) as processando,
                SUM(CASE WHEN client_products.sync_status = 'bling_frozen' THEN 1 ELSE 0 END) as congelado
            ")
            ->first();

        $pulados30d = SyncLog::where('platform', 'bling')
            ->where('status', 'skipped')
            ->where('created_at', '>=', now()->subDays(30))
            ->distinct('syncable_id')
            ->count('syncable_id');

        // MUL-226-02: pedidos da migração ainda sem espelho no Bling (reenvio em massa
        // NÃO é automático — depende de liberação, pra não criar pedidos em lote no Bling real)
        $migracao = Order::where('is_draft', 0)
            ->where('status', 'shipped')
            ->where(fn ($q) => $q->whereNull('bling_order_id')->orWhere('bling_order_id', ''))
            ->count();

        return [
            ['key' => 'sucesso',     'label' => 'Sucesso',           'count' => (int) ($counts->sucesso ?? 0),     'cor' => '#10b981', 'hint' => 'exportados pro Bling'],
            ['key' => 'erro',        'label' => 'Erro',              'count' => (int) ($counts->erro ?? 0),        'cor' => '#ef4444', 'hint' => 'falha na exportação'],
            ['key' => 'processando', 'label' => 'Em Processamento',  'count' => (int) ($counts->processando ?? 0), 'cor' => '#3b82f6', 'hint' => 'na fila de exportação'],
            ['key' => 'congelado',   'label' => 'Congelados',        'count' => (int) ($counts->congelado ?? 0),   'cor' => '#f59e0b', 'hint' => 'pausados pelo admin'],
            ['key' => 'pulados',     'label' => 'Pulados (30d)',     'count' => $pulados30d,                       'cor' => '#6b7280', 'hint' => 'produtos com skip recente'],
            ['key' => null,          'label' => 'Pedidos migração',  'count' => $migracao,                         'cor' => '#a855f7', 'hint' => 'enviados sem espelho no Bling'],
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('toggleCatalog')
                ->label(fn () => $this->isCatalogFrozen() ? 'Descongelar Catálogo' : 'Congelar Catálogo')
                ->icon(fn () => $this->isCatalogFrozen() ? 'heroicon-o-play-circle' : 'heroicon-o-pause-circle')
                ->color(fn () => $this->isCatalogFrozen() ? 'success' : 'warning')
                ->requiresConfirmation()
                ->modalHeading(fn () => $this->isCatalogFrozen() ? 'Descongelar exportação do catálogo' : 'Congelar exportação do catálogo')
                ->modalDescription(fn () => $this->isCatalogFrozen()
                    ? 'A exportação de produtos pro Bling volta a rodar e os produtos pulados durante o congelamento são reenviados automaticamente (sem duplicar — o envio atualiza pelo SKU).'
                    : 'NENHUM produto será exportado pro Bling até descongelar. A importação de vendas dos sellers segue normal. Nada se perde: ao descongelar, os pulados são reenviados.')
                ->action('toggleCatalogFreeze'),

            Actions\Action::make('toggleOrdersQueue')
                ->label(fn () => $this->isOrdersQueueFrozen() ? 'Descongelar Fila de Pedidos' : 'Congelar Fila de Pedidos')
                ->icon(fn () => $this->isOrdersQueueFrozen() ? 'heroicon-o-play-circle' : 'heroicon-o-pause-circle')
                ->color(fn () => $this->isOrdersQueueFrozen() ? 'success' : 'gray')
                ->requiresConfirmation()
                ->modalHeading(fn () => $this->isOrdersQueueFrozen() ? 'Descongelar fila de pedidos/NF' : 'Congelar fila de pedidos/NF')
                ->modalDescription(fn () => $this->isOrdersQueueFrozen()
                    ? 'Os pedidos voltam a ser empurrados pro Bling (os que ficaram na fila re-executam sozinhos).'
                    : 'Pedidos param de ser empurrados pro Bling (uso típico: balanço de estoque). Ficam re-agendados de hora em hora até descongelar.')
                ->action('toggleOrdersQueueFreeze'),
        ];
    }

    public function toggleCatalogFreeze(): void
    {
        if ($this->isCatalogFrozen()) {
            $frozenAt = DB::table('settings')->where('key', 'bling_catalog_frozen')->value('updated_at');

            DB::table('settings')->updateOrInsert(
                ['key' => 'bling_catalog_frozen'],
                ['group' => 'bling', 'value' => '0', 'updated_at' => now(), 'created_at' => now()]
            );

            // MUL-226-01: retomada sem perda — reenvia quem foi pulado durante o congelamento.
            // Congelados individualmente (bling_frozen) ficam de fora: o guard do job os segura.
            $ids = SyncLog::where('platform', 'bling')
                ->where('status', 'skipped')
                ->where('error_message', 'like', 'catalog_frozen%')
                ->when($frozenAt, fn ($q) => $q->where('created_at', '>=', $frozenAt))
                ->distinct()
                ->pluck('syncable_id');

            foreach ($ids as $id) {
                SyncProductsJob::dispatch((int) $id);
            }

            $this->audit('catalog_unfreeze', ['redispatched' => $ids->count()]);

            Notification::make()
                ->title('Catálogo descongelado')
                ->body($ids->count() . ' exportação(ões) pulada(s) durante o congelamento foram reenviadas.')
                ->success()
                ->send();

            return;
        }

        DB::table('settings')->updateOrInsert(
            ['key' => 'bling_catalog_frozen'],
            ['group' => 'bling', 'value' => '1', 'updated_at' => now(), 'created_at' => now()]
        );

        $this->audit('catalog_freeze');

        Notification::make()
            ->title('Catálogo congelado')
            ->body('Nenhum produto será exportado pro Bling até descongelar. Importação de vendas segue normal.')
            ->warning()
            ->send();
    }

    public function toggleOrdersQueueFreeze(): void
    {
        $freeze = ! $this->isOrdersQueueFrozen();

        DB::table('settings')->updateOrInsert(
            ['key' => 'bling_queue_frozen'],
            ['group' => 'bling', 'value' => $freeze ? '1' : '0', 'updated_at' => now(), 'created_at' => now()]
        );

        $this->audit($freeze ? 'orders_queue_freeze' : 'orders_queue_unfreeze');

        Notification::make()
            ->title($freeze ? 'Fila de pedidos/NF congelada' : 'Fila de pedidos/NF descongelada')
            ->body($freeze ? 'Pedidos re-agendam de hora em hora até descongelar (nada se perde).' : 'Pedidos represados na fila re-executam sozinhos.')
            ->{$freeze ? 'warning' : 'success'}()
            ->send();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => ClientProduct::query()
                ->join('marketplace_accounts as ma', 'ma.id', '=', 'client_products.marketplace_account_id')
                ->join('clients as c', 'c.id', '=', 'client_products.client_id')
                // MUL-269 fase 2: nome do seller vem do user (clients.company_name removido).
                ->leftJoin('users as u_seller', 'u_seller.id', '=', 'c.user_id')
                ->where('ma.platform', 'bling')
                ->select('client_products.*', DB::raw("COALESCE(NULLIF(u_seller.full_name,''), u_seller.name) as seller_nome"), 'ma.account_name as conta_nome')
                ->when($this->statusCard === 'sucesso', fn ($q) => $q->where('client_products.sync_status', 'synced'))
                ->when($this->statusCard === 'erro', fn ($q) => $q->whereIn('client_products.sync_status', self::ERROR_STATUSES))
                ->when($this->statusCard === 'processando', fn ($q) => $q->whereIn('client_products.sync_status', self::PROCESSING_STATUSES))
                ->when($this->statusCard === 'congelado', fn ($q) => $q->where('client_products.sync_status', 'bling_frozen'))
                ->when($this->statusCard === 'pulados', fn ($q) => $q->whereIn('client_products.id', SyncLog::where('platform', 'bling')
                    ->where('status', 'skipped')
                    ->where('created_at', '>=', now()->subDays(30))
                    ->select('syncable_id')))
            )
            ->defaultSort('client_products.last_sync_at', 'desc')
            ->columns([
                TextColumn::make('custom_title')
                    ->label('Produto')
                    ->limit(45)
                    ->tooltip(fn ($record) => $record->custom_title)
                    ->searchable()
                    ->weight('medium'),

                TextColumn::make('custom_sku')
                    ->label('SKU')
                    ->searchable()
                    ->badge()
                    ->color('gray'),

                TextColumn::make('seller_nome')
                    ->label('Seller'),

                TextColumn::make('conta_nome')
                    ->label('Conta Bling')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('sync_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => match (true) {
                        $state === 'synced'                             => 'Sucesso',
                        $state === 'bling_frozen'                       => 'Congelado',
                        in_array($state, self::PROCESSING_STATUSES)     => 'Em processamento',
                        in_array($state, self::ERROR_STATUSES)          => 'Erro',
                        default                                         => $state ?? '—',
                    })
                    ->color(fn (?string $state) => match (true) {
                        $state === 'synced'                             => 'success',
                        $state === 'bling_frozen'                       => 'warning',
                        in_array($state, self::PROCESSING_STATUSES)     => 'info',
                        in_array($state, self::ERROR_STATUSES)          => 'danger',
                        default                                         => 'gray',
                    }),

                TextColumn::make('last_sync_at')
                    ->label('Último envio')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('client_products.last_sync_at', $direction)),

                TextColumn::make('last_sync_error')
                    ->label('Último erro')
                    ->limit(40)
                    ->tooltip(fn ($record) => $record->last_sync_error)
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('client_id')
                    ->label('Seller')
                    ->attribute('client_products.client_id')
                    // MUL-269 fase 2: label do seller vem do user (clients.company_name removido).
                    ->options(function () {
                        return Client::query()
                            ->whereIn('clients.id', DB::table('marketplace_accounts')->where('platform', 'bling')->pluck('client_id'))
                            ->join('users', 'users.id', '=', 'clients.user_id')
                            ->select('clients.id', DB::raw("COALESCE(NULLIF(users.full_name,''), users.name) as label"))
                            ->pluck('label', 'clients.id')
                            ->toArray();
                    }),

                SelectFilter::make('sync_status')
                    ->label('Status')
                    ->attribute('client_products.sync_status')
                    ->options([
                        'synced'       => 'Sucesso',
                        'error'        => 'Erro',
                        'pending'      => 'Em processamento',
                        'bling_frozen' => 'Congelado',
                    ]),
            ])
            ->actions([
                Action::make('reenviar')
                    ->label('Reenviar')
                    ->icon('heroicon-o-arrow-path')
                    ->color('info')
                    ->visible(fn ($record) => $record->sync_status !== 'bling_frozen')
                    ->requiresConfirmation()
                    ->modalHeading('Reenviar exportação pro Bling')
                    ->modalDescription('O envio atualiza o produto pelo SKU no Bling — não cria duplicado.')
                    ->action(function ($record) {
                        $this->queueResend(collect([$record]));
                        Notification::make()->title('Exportação reenviada')->body('SKU ' . $record->custom_sku . ' na fila.')->success()->send();
                    }),

                Action::make('historico')
                    ->label('Histórico')
                    ->icon('heroicon-o-clock')
                    ->color('gray')
                    ->slideOver()
                    ->modalHeading(fn ($record) => 'Histórico de exportação — ' . $record->custom_sku)
                    ->modalContent(fn ($record) => self::renderHistorico((int) $record->id))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fechar'),
            ])
            ->bulkActions([
                BulkAction::make('congelar')
                    ->label('Congelar export (selecionados)')
                    ->icon('heroicon-o-pause-circle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Congelar exportação Bling dos selecionados')
                    ->modalDescription('Os produtos selecionados param de exportar pro Bling até serem descongelados. Nada se perde: ao descongelar, o envio é refeito.')
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records) {
                        $ids = $records->pluck('id');
                        $n = ClientProduct::whereIn('id', $ids)
                            ->where('sync_status', '!=', 'bling_frozen')
                            ->update(['sync_status' => 'bling_frozen']);
                        foreach ($ids as $id) {
                            $this->auditProduct((int) $id, 'product_freeze');
                        }
                        Notification::make()->title("{$n} produto(s) congelado(s)")->warning()->send();
                    }),

                BulkAction::make('descongelar')
                    ->label('Descongelar + reexportar (selecionados)')
                    ->icon('heroicon-o-play-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Descongelar e reexportar os selecionados')
                    ->modalDescription('Volta a exportar e reenvia imediatamente. O envio atualiza pelo SKU — sem duplicar no Bling.')
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records) {
                        $frozen = $records->where('sync_status', 'bling_frozen');
                        ClientProduct::whereIn('id', $frozen->pluck('id'))->update(['sync_status' => 'pending']);
                        foreach ($frozen as $r) {
                            $this->auditProduct((int) $r->id, 'product_unfreeze');
                            SyncProductsJob::dispatch((int) $r->id);
                        }
                        Notification::make()->title($frozen->count() . ' produto(s) descongelado(s) e reenviado(s)')->success()->send();
                    }),

                BulkAction::make('reenviar')
                    ->label('Reenviar export (selecionados)')
                    ->icon('heroicon-o-arrow-path')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('Reenviar exportação dos selecionados')
                    ->modalDescription('Reenvia pro Bling. Produtos congelados ficam de fora. O envio atualiza pelo SKU — sem duplicar.')
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records) {
                        $n = $this->queueResend($records);
                        Notification::make()->title("{$n} exportação(ões) na fila")->success()->send();
                    }),
            ])
            ->emptyStateHeading('Nenhum produto em contas Bling')
            ->emptyStateDescription('Nenhum resultado pro filtro atual.');
    }

    /** Reenvio com registro (MUL-226-02): marca pending, loga a intenção e enfileira. */
    protected function queueResend(\Illuminate\Support\Collection|Collection $records): int
    {
        $eligible = $records->filter(fn ($r) => $r->sync_status !== 'bling_frozen');

        ClientProduct::whereIn('id', $eligible->pluck('id'))->update(['sync_status' => 'pending']);

        foreach ($eligible as $r) {
            $this->auditProduct((int) $r->id, 'resend_requested');
            SyncProductsJob::dispatch((int) $r->id);
        }

        return $eligible->count();
    }

    /** MUL-226-01: auditoria de ação por produto em sync_logs. */
    protected function auditProduct(int $clientProductId, string $action): void
    {
        SyncLog::create([
            'syncable_type'   => ClientProduct::class,
            'syncable_id'     => $clientProductId,
            'platform'        => 'bling',
            'action'          => $action,
            'direction'       => 'outbound',
            'status'          => 'success',
            'request_payload' => json_encode([
                'user_id' => auth()->id(),
                'email'   => auth()->user()?->email,
                'origem'  => 'admin/bling-export',
            ]),
        ]);
    }

    /** MUL-226-01: auditoria de ação global (freeze/unfreeze) em sync_logs. */
    protected function audit(string $action, array $payload = []): void
    {
        SyncLog::create([
            'syncable_type'   => Setting::class,
            'syncable_id'     => 0,
            'platform'        => 'bling',
            'action'          => $action,
            'direction'       => 'outbound',
            'status'          => 'success',
            'request_payload' => json_encode(array_merge([
                'user_id' => auth()->id(),
                'email'   => auth()->user()?->email,
                'origem'  => 'admin/bling-export',
            ], $payload)),
        ]);
    }

    protected static function renderHistorico(int $clientProductId): HtmlString
    {
        $logs = SyncLog::where('syncable_type', ClientProduct::class)
            ->where('syncable_id', $clientProductId)
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        if ($logs->isEmpty()) {
            return new HtmlString('<p class="text-gray-500 text-sm p-4">Nenhum evento registrado pra este produto.</p>');
        }

        $badge = function (string $status): string {
            $cor = match ($status) {
                'success' => '#10b981',
                'failed', 'error' => '#ef4444',
                'skipped' => '#f59e0b',
                default => '#6b7280',
            };

            return '<span style="background:' . $cor . '20;color:' . $cor . ';padding:2px 8px;border-radius:9999px;font-weight:700;font-size:0.75rem;">' . htmlspecialchars($status) . '</span>';
        };

        $html = '<div class="overflow-x-auto p-2"><table style="width:100%;border-collapse:collapse;font-size:0.85rem;">';
        $html .= '<thead><tr style="border-bottom:2px solid #94a3b8;"><th style="padding:8px 10px;text-align:left;">Quando</th><th style="padding:8px 10px;text-align:left;">Ação</th><th style="padding:8px 10px;text-align:left;">Status</th><th style="padding:8px 10px;text-align:left;">Detalhe</th></tr></thead><tbody>';

        foreach ($logs as $log) {
            $html .= '<tr style="border-bottom:1px solid #e5e7eb;">';
            $html .= '<td style="padding:8px 10px;white-space:nowrap;">' . $log->created_at?->format('d/m/Y H:i') . '</td>';
            $html .= '<td style="padding:8px 10px;">' . htmlspecialchars($log->action ?? '—') . '</td>';
            $html .= '<td style="padding:8px 10px;">' . $badge((string) $log->status) . '</td>';
            $html .= '<td style="padding:8px 10px;color:#6b7280;">' . htmlspecialchars(mb_strimwidth((string) ($log->error_message ?? ''), 0, 120, '…')) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table></div>';

        return new HtmlString($html);
    }
}
