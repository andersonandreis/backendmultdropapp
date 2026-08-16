<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use App\Models\Order;
use App\Models\Inventory;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessOrders extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-truck';
    protected static ?string $navigationGroup = 'Pedidos & Logística';
    protected static ?string $navigationLabel = 'Central de Pedidos';
    protected static ?string $title = 'Central de Pedidos';
    protected static ?string $slug = 'processar-pedidos';
    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.process-orders';

    /**
     * Debita estoque no legado (mesma mecânica do bipagem Goolhub):
     * - Insere lançamento tipo='S' na tabela estoque (livro-caixa)
     * - Decrementa sku_pai.estoque e bumpa data_update (aciona SyncLegacyCatalogJob)
     * - Espelha o decremento no inventory local (será sobrescrito pelo sync no próximo ciclo)
     */
    private function debitarEstoqueLegado(Order $order): void
    {
        $items = $order->items()->with('product')->get();

        foreach ($items as $item) {
            $legacySkuPaiId = $item->legacy_sku_pai_id
                ?? $item->product?->legacy_sku_pai_id;

            if (!$legacySkuPaiId || $item->quantity <= 0) {
                continue;
            }

            $qty = (int) $item->quantity;

            try {
                DB::connection('legacy')->table('estoque')->insert([
                    'id_sku_pai'    => $legacySkuPaiId,
                    'tipo'          => 'S',
                    'valor'         => $qty,
                    'descricao'     => 'Saida venda ' . config('app.name') . ' #' . ($order->order_number ?? $order->id) . ' id_sku_pai #' . $legacySkuPaiId,
                    'data'          => now()->toDateTimeString(),
                    'id_pedido'     => null,
                    'id_integracao' => null,
                ]);

                // GREATEST(0, ...) garante que o estoque não vai negativo
                DB::connection('legacy')->table('sku_pai')
                    ->where('id', $legacySkuPaiId)
                    ->update([
                        'estoque'      => DB::raw("GREATEST(0, estoque - {$qty})"),
                        'data_update'  => now()->toDateTimeString(),
                        '_sync_source' => 'hubaiapp',
                    ]);

                // Espelho local — SyncLegacyCatalogJob sincronizará o valor real no próximo ciclo
                if ($item->product_id) {
                    Inventory::where('product_id', $item->product_id)
                        ->where('warehouse_id', $order->supplier_id)
                        ->update([
                            'quantity' => DB::raw("GREATEST(0, quantity - {$qty})"),
                        ]);
                }

                Log::info("[ProcessOrders] Estoque debitado: sku_pai={$legacySkuPaiId} qty={$qty} pedido={$order->order_number}");
            } catch (\Throwable $e) {
                Log::error("[ProcessOrders] Falha ao debitar estoque legacy sku_pai={$legacySkuPaiId}: " . $e->getMessage());
            }
        }
    }

    /**
     * Estorna estoque no legado quando pedido é devolvido (reverso da bipagem):
     * - Só estorna se o pedido chegou a ser separado (estoque foi debitado)
     * - Insere lançamento tipo='E' (Estorno) no livro-caixa
     * - Incrementa sku_pai.estoque e bumpa data_update
     */
    private function estornarEstoqueLegado(Order $order): void
    {
        if (!in_array($order->order_processing_status, ['separated', 'shipped'])) {
            return;
        }

        $items = $order->items()->with('product')->get();

        foreach ($items as $item) {
            $legacySkuPaiId = $item->legacy_sku_pai_id
                ?? $item->product?->legacy_sku_pai_id;

            if (!$legacySkuPaiId || $item->quantity <= 0) {
                continue;
            }

            $qty = (int) $item->quantity;

            try {
                DB::connection('legacy')->table('estoque')->insert([
                    'id_sku_pai'    => $legacySkuPaiId,
                    'tipo'          => 'E',
                    'valor'         => $qty,
                    'descricao'     => 'Estorno V.Cancel. venda ' . config('app.name') . ' #' . ($order->order_number ?? $order->id) . ' id_sku_pai #' . $legacySkuPaiId,
                    'data'          => now()->toDateTimeString(),
                    'id_pedido'     => null,
                    'id_integracao' => null,
                ]);

                DB::connection('legacy')->table('sku_pai')
                    ->where('id', $legacySkuPaiId)
                    ->update([
                        'estoque'      => DB::raw("estoque + {$qty}"),
                        'data_update'  => now()->toDateTimeString(),
                        '_sync_source' => 'hubaiapp',
                    ]);

                if ($item->product_id) {
                    Inventory::where('product_id', $item->product_id)
                        ->where('warehouse_id', $order->supplier_id)
                        ->update([
                            'quantity' => DB::raw("quantity + {$qty}"),
                        ]);
                }

                Log::info("[ProcessOrders] Estorno estoque: sku_pai={$legacySkuPaiId} qty={$qty} pedido={$order->order_number}");
            } catch (\Throwable $e) {
                Log::error("[ProcessOrders] Falha ao estornar estoque legacy sku_pai={$legacySkuPaiId}: " . $e->getMessage());
            }
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('scanner_devolucao')
                ->label('Registrar Devolucao')
                ->icon('heroicon-o-arrow-path-rounded-square')
                ->color('danger')
                ->modalHeading('Receber Devolucao')
                ->modalDescription('Use o leitor de código de barras no pacote devolvido para cancelar o envio e estornar o Lojista.')
                ->form([
                    \Filament\Forms\Components\TextInput::make('order_number')
                        ->label('Código do Pedido / Rastreio')
                        ->required()
                        ->autofocus()
                        ->placeholder('Ex: ORD-12345'),
                ])
                ->action(function (array $data, \Filament\Notifications\Notification $notification) {
                    $order = Order::where('order_number', $data['order_number'])
                        ->orWhere('tracking_number', $data['order_number'])
                        ->first();

                    if (!$order) {
                        $notification::make()
                            ->title('Pedido não encontrado')
                            ->body('Nenhum pedido localizado com o código informado.')
                            ->danger()
                            ->send();
                        return;
                    }

                    if ($order->status === \App\Enums\OrderStatus::RETURNED->value || $order->status === \App\Enums\OrderStatus::CANCELLED->value) {
                        $notification::make()
                            ->title('Já Processado')
                            ->body('Este pedido já foi marcado como Devolvido/Cancelado.')
                            ->warning()
                            ->send();
                        return;
                    }

                    // Estornar estoque no legado antes de mudar o status (só se foi separado)
                    $this->estornarEstoqueLegado($order);

                    // Ao mudar o status para RETURNED, o OrderObserver vai triggar o ClientWalletService.
                    $order->update([
                        'status' => \App\Enums\OrderStatus::RETURNED->value,
                        'order_processing_status' => 'returned',
                    ]);

                    $notification::make()
                        ->title('Devolucao Registrada com Sucesso!')
                        ->body("O pedido {$order->order_number} foi retornado ao estoque e o valor de {$order->total} foi estornado para a Carteira do Lojista.")
                        ->success()
                        ->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Order::query()->whereIn('status', ['paid', 'preparing'])->orWhere('order_processing_status', 'payment_pending'))
            ->columns([
                TextColumn::make('order_number')
                    ->label('Nº Pedido')
                    ->searchable(),
                TextColumn::make('client.company_name')
                    ->label('Lojista'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn(string $state) => match ($state) {
                        'pending'   => 'Aguardando',
                        'paid'      => 'Confirmado',
                        'preparing' => 'Em Preparação',
                        'shipped'   => 'Enviado',
                        'delivered' => 'Entregue',
                        'cancelled' => 'Cancelado',
                        'returned'  => 'Devolvido',
                        default     => $state,
                    })
                    ->color(fn(string $state) => match ($state) {
                        'paid'      => 'success',
                        'pending'   => 'warning',
                        'preparing' => 'info',
                        'shipped'   => 'primary',
                        'delivered' => 'success',
                        'cancelled' => 'danger',
                        'returned'  => 'danger',
                        default     => 'gray',
                    }),
                TextColumn::make('order_processing_status')
                    ->label('Status do Pedido')
                    ->badge()
                    ->formatStateUsing(fn(?string $state) => match ($state) {
                        'awaiting_label'    => 'Aguardando Etiqueta',
                        'awaiting_dispatch' => 'Pronto p/ Despacho',
                        'separated'         => 'Separado',
                        'packed'            => 'Embalado',
                        'shipped'           => 'Enviado',
                        'delivered'         => 'Entregue',
                        'returned'          => 'Devolvido',
                        'pending'           => 'Pendente',
                        'preparing'         => 'Em Preparação',
                        'payment_pending'   => 'Aguard. Pagamento',
                        default             => $state ?? 'Novo',
                    })
                    ->color(fn(?string $state) => match ($state) {
                        'awaiting_label'    => 'warning',
                        'awaiting_dispatch' => 'info',
                        'separated'         => 'primary',
                        'packed'            => 'primary',
                        'shipped'           => 'success',
                        'delivered'         => 'success',
                        'returned'          => 'danger',
                        'payment_pending'   => 'danger',
                        default             => 'gray',
                    }),

                TextColumn::make('financeiro')
                    ->label('Financeiro')
                    ->html()
                    ->getStateUsing(function (Order $record): \Illuminate\Support\HtmlString {
                        $custo  = (float) $record->supplier_total;
                        $venda  = (float) $record->total;
                        $margem = $venda > 0 ? (($venda - $custo) / $venda) * 100 : 0;

                        $html = '<div style="line-height:1.6; font-size:0.8rem;">';
                        $html .= '<span style="color:#ef4444;">Custo R$ ' . number_format($custo, 2, ',', '.') . '</span><br>';
                        $html .= '<span style="color:#3b82f6;">Venda R$ ' . number_format($venda, 2, ',', '.') . '</span><br>';
                        $html .= '<span style="color:#f59e0b;">Margem ' . number_format($margem, 1, ',', '.') . '%</span>';
                        $html .= '</div>';
                        return new \Illuminate\Support\HtmlString($html);
                    }),

                TextColumn::make('created_at')
                    ->label('Data')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->actions([
                Action::make('upload_invoice')
                    ->label('Anexar NF-e')
                    ->icon('heroicon-o-document-text')
                    ->color('info')
                    ->form([
                        \Filament\Forms\Components\FileUpload::make('invoice_file')
                            ->label('Arquivo da Nota Fiscal (PDF)')
                            ->acceptedFileTypes(['application/pdf'])
                            ->directory('invoices')
                            ->required(),
                    ])
                    ->action(function (Order $record, array $data) {
                        $record->update([
                            'invoice_url' => $data['invoice_file'],
                        ]);

                        \App\Models\OrderLabelQueue::firstOrCreate(
                            ['order_id' => $record->id],
                            ['status' => 'pending']
                        );
                    })
                    ->visible(fn(Order $record) => in_array($record->source, ['Shopee', 'MagazineLuiza']) && !$record->invoice_url),
                Action::make('check_label')
                    ->label('Tentar Baixar Etiqueta')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    // SEL-413: nao desabilita mais por 'payment_pending'. Buscar a etiqueta
                    // e pre-requisito do pagamento (NOV-207), entao travar a busca por falta
                    // de pagamento cria o mesmo deadlock que o MUL-242 corrigiu nos jobs.
                    // O envio continua protegido por assertPaymentConfirmed (MUL-255).
                    ->action(function (Order $record) {
                        $simulator = new \App\Services\ShippingLabelService();
                        $res = $simulator->checkLabelStatus($record);

                        $queue = \App\Models\OrderLabelQueue::firstOrCreate(
                            ['order_id' => $record->id],
                            ['status' => 'pending']
                        );

                        if ($res['ready']) {
                            $record->update([
                                'label_url' => $res['label_url'],
                                'order_processing_status' => 'awaiting_dispatch'
                            ]);
                            $queue->update(['status' => 'available', 'error_log' => null]);
                            \Filament\Notifications\Notification::make()->title('Etiqueta Liberada!')->success()->send();
                        } else {
                            $queue->update(['status' => 'pending', 'next_check_at' => now()->addMinutes($res['retry_in_minutes'] ?? 60), 'error_log' => $res['reason'] ?? 'Not ready']);
                            \Filament\Notifications\Notification::make()->title('Ainda não liberada')->body($res['reason'] ?? '')->warning()->send();
                        }
                    })
                    ->visible(fn(Order $record) => !$record->label_url),
                Action::make('print_label')
                    ->icon('heroicon-o-printer')
                    ->label('Imprimir Etiqueta')
                    ->url(fn(Order $record) => $record->label_url ?? '#print-' . $record->id)
                    ->openUrlInNewTab()
                    ->visible(fn(Order $record) => (bool) $record->label_url && $record->order_processing_status !== 'payment_pending'),
                Action::make('to_separated')
                    ->label('Marcar Separado')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Confirmar Separação')
                    ->modalDescription('Ao confirmar, o estoque será debitado no sistema. Esta ação não pode ser desfeita.')
                    ->action(function (Order $record) {
                        if ($record->order_processing_status === 'separated') {
                            return; // Idempotência — não debita duas vezes
                        }

                        $this->debitarEstoqueLegado($record);

                        $record->update(['order_processing_status' => 'separated']);
                    })
                    ->visible(fn(Order $record) =>
                        $record->order_processing_status !== 'payment_pending'
                        && ($record->order_processing_status === 'awaiting_dispatch' || ($record->order_processing_status !== 'separated' && (bool) $record->label_url))
                    ),
            ])
            ->bulkActions([
                BulkAction::make('print_labels_batch')
                    ->icon('heroicon-o-printer')
                    ->label('Imprimir Etiquetas em Lote')
                    ->action(function (Collection $records) {
                        // Batch print mock
                    })->deselectRecordsAfterCompletion(),
                BulkAction::make('mark_shipped')
                    ->icon('heroicon-o-truck')
                    ->label('Marcar como Expedido')
                    ->color('success')
                    ->action(function (Collection $records) {
                        $records->each(function ($order) {
                            $order->update([
                                'order_processing_status' => 'shipped',
                                'status' => 'shipped'
                            ]);
                        });
                    })->deselectRecordsAfterCompletion(),
            ]);
    }
}
