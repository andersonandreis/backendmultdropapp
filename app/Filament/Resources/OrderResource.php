<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Filament\Resources\OrderResource\RelationManagers;
use App\Models\LabelPrintLog;
use App\Models\Order;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * OrderResource — Painel Admin MEStoreDrop (supplier_id=25)
 *
 * NOV-140: Auditoria e correção do painel admin MEStore
 * - Item 1: Histórico de etiquetas (LabelPrintLog)
 * - Item 2: Colunas custo do pedido + e-mail do seller + datas validadas
 * - Item 3: Botão copiar e-mail + dados do seller no detalhe
 * - Item 4: Informações de pagamento (paid_at, wallet_transaction_id, capture_source)
 * - Item 5: Timeline separada em 3 eventos: Venda / Pagamento / Fornecedor
 * - Item 7: Auditoria geral + filtros adicionais
 */
class OrderResource extends Resource
{
    protected static ?string $model = Order::class;
    protected static ?string $slug = 'pedidos';
    protected static ?string $modelLabel = 'Pedido';
    protected static ?string $pluralModelLabel = 'Pedidos';

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';
    protected static ?string $navigationGroup = 'Pedidos & Logística';


    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'supplier']);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = parent::getEloquentQuery();
        // NOV-183: eager load pra coluna de imagem do 1º item (evita N+1)
        // MUL-217: colunas extras pro Repeater de itens no form de edição
        $query->with(['items:id,order_id,product_image,sku,name,quantity,unit_price,supplier_unit_cost']);
        // MUL-202-C: rascunhos (guards de custo zero + observer_safety_net) sao ocultados
        // por padrao via TernaryFilter 'is_draft' na tabela (default=false). O filtro na UI
        // permite alternar entre "sem rascunhos", "somente rascunhos" e "todos" sem precisar
        // de query-string manual.
        if (auth()->user()?->role === 'supplier') {
            $supplierId = auth()->user()->supplier?->id;
            if ($supplierId) {
                $query->where('supplier_id', $supplierId);
            }
        }
        return $query;
    }

    // =========================================================================
    // FORM — Detail view of a single order
    // =========================================================================

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // ---------------------------------------------------------------
                // ITEM 5: Timeline separada em 3 momentos
                // ---------------------------------------------------------------
                Forms\Components\Section::make('Timeline do Pedido')
                    ->description('3 momentos principais: Venda, Pagamento do Lojista e Repasse ao Fornecedor.')
                    ->schema([
                        Forms\Components\Placeholder::make('timeline_eventos')
                            ->label('')
                            ->content(function (Order $record): HtmlString {
                                $eventos = [];

                                // MUL-299: a data da VENDA e marketplace_created_at. created_at e a data em
                                // que o pedido foi IMPORTADO. Mostrar uma pela outra confundia o time inteiro:
                                // pedido de dezembro/2025 importado hoje aparecia como venda de hoje.
                                $eventos[] = [
                                    'icon'  => '🛍️',
                                    'label' => 'Venda no marketplace',
                                    'dt'    => $record->marketplace_created_at ?? $record->created_at,
                                    'color' => '#6366f1',
                                    'meta'  => 'Canal: ' . strtoupper($record->source ?? 'N/A') . ' | #' . ($record->external_order_id ?? $record->order_number)
                                        . ($record->marketplace_created_at ? '' : ' | sem data de venda — exibindo a de importacao'),
                                ];

                                // MUL-299: importacao vira evento proprio, so quando cai em outro dia
                                if ($record->marketplace_created_at && $record->created_at
                                    && $record->marketplace_created_at->format('Y-m-d') !== $record->created_at->format('Y-m-d')) {
                                    $eventos[] = [
                                        'icon'  => '📥',
                                        'label' => 'Importado para o sistema',
                                        'dt'    => $record->created_at,
                                        'color' => '#94a3b8',
                                        'meta'  => 'Data em que o pedido entrou no HubAI — nao e a data da venda',
                                    ];
                                }

                                // Momento 2: Pagamento do lojista (paid_at)
                                $eventos[] = [
                                    'icon'  => '💳',
                                    'label' => 'Pagamento do Lojista',
                                    'dt'    => $record->paid_at,
                                    'color' => $record->paid_at ? '#10b981' : '#ef4444',
                                    'meta'  => $record->paid_at
                                        ? 'Pago em ' . $record->paid_at->format('H:i:s d/m/Y')
                                            . ($record->capture_source ? ' | Via: ' . strtoupper($record->capture_source) : '')
                                        : 'Aguardando pagamento',
                                ];

                                // Momento 3: Repasse ao fornecedor (wallet_paid_at ou shipped_at como proxy)
                                $fornecedorDt   = $record->wallet_paid_at ?? $record->shipped_at ?? null;
                                $fornecedorMeta = $record->wallet_paid_at
                                    ? 'Saldo debitado | Tx ID: ' . ($record->wallet_transaction_id ?? 'N/A')
                                    : ($record->shipped_at ? 'Pedido despachado (proxy)' : 'Aguardando repasse');

                                $eventos[] = [
                                    'icon'  => '💰',
                                    'label' => 'Repasse ao Fornecedor',
                                    'dt'    => $fornecedorDt,
                                    'color' => $fornecedorDt ? '#f59e0b' : '#9ca3af',
                                    'meta'  => $fornecedorMeta,
                                ];

                                // MUL-340: as etapas da devolucao. So aparecem quando existe uma —
                                // pedido normal segue com os tres momentos de sempre.
                                //
                                // Ate 06/08 o pedido devolvido ficava "entregue" para sempre: para o
                                // marketplace a ENTREGA foi concluida, e a devolucao e um processo
                                // paralelo que o sistema nao enxergava.
                                $devolucoes = \Illuminate\Support\Facades\DB::table('marketplace_returns')
                                    ->where(function ($q) use ($record) {
                                        $q->where('order_id', $record->id);
                                        if ($record->external_order_id) {
                                            $q->orWhere('order_sn', $record->external_order_id);
                                        }
                                    })
                                    ->orderBy('marketplace_created_at')
                                    ->get();

                                foreach ($devolucoes as $dev) {
                                    $motivo = \App\Support\DevolucaoDicionario::motivo($dev->reason);
                                    $culpaExpedicao = \App\Support\DevolucaoDicionario::ehErroDeExpedicao($dev->reason);

                                    // 1. o comprador abriu
                                    $eventos[] = [
                                        'icon'  => '↩️',
                                        'label' => 'Devolução aberta',
                                        'dt'    => $dev->marketplace_created_at
                                            ? \Carbon\Carbon::parse($dev->marketplace_created_at) : null,
                                        'color' => '#f97316',
                                        'meta'  => 'Motivo: ' . $motivo
                                            . ($culpaExpedicao ? ' — erro de expedição' : '')
                                            . ' | #' . $dev->return_sn
                                            . ($dev->refund_amount ? ' | R$ ' . number_format((float) $dev->refund_amount, 2, ',', '.') : ''),
                                    ];

                                    // 2. a mercadoria voltando
                                    if ($dev->needs_logistics) {
                                        $eventos[] = [
                                            'icon'  => $dev->is_arrived_at_warehouse ? '📦' : '🚚',
                                            'label' => $dev->is_arrived_at_warehouse
                                                ? 'Mercadoria recebida de volta'
                                                : 'Mercadoria em trânsito de volta',
                                            'dt'    => $dev->is_arrived_at_warehouse && $dev->marketplace_updated_at
                                                ? \Carbon\Carbon::parse($dev->marketplace_updated_at) : null,
                                            'color' => $dev->is_arrived_at_warehouse ? '#0ea5e9' : '#94a3b8',
                                            'meta'  => $dev->tracking_number
                                                ? 'Rastreio: ' . $dev->tracking_number
                                                : 'Sem rastreio informado pelo marketplace',
                                        ];
                                    }

                                    // 3. prazo do seller, quando ha — e aqui que se perde dinheiro
                                    $prazo = $dev->seller_evidence_deadline ?: $dev->return_seller_due_date;
                                    if ($prazo) {
                                        $dtPrazo  = \Carbon\Carbon::parse($prazo);
                                        $vencido  = $dtPrazo->isPast();
                                        $eventos[] = [
                                            'icon'  => $vencido ? '⏰' : '⚠️',
                                            'label' => $vencido ? 'Prazo do seller VENCIDO' : 'Prazo do seller para contestar',
                                            'dt'    => $dtPrazo,
                                            'color' => $vencido ? '#dc2626' : '#eab308',
                                            'meta'  => $vencido
                                                ? 'Prazo vencido — a devolução segue sem contestação'
                                                : 'Contestar até esta data, senão o estorno é automático',
                                        ];
                                    }

                                    // 4. desfecho
                                    $desfechos = \App\Support\DevolucaoDicionario::DESFECHOS;
                                    if (isset($desfechos[$dev->status])) {
                                        [$rotulo, $txt, $ic, $cor] = $desfechos[$dev->status];
                                        $lb = 'Devolução ' . $rotulo;
                                        $eventos[] = [
                                            'icon'  => $ic,
                                            'label' => $lb,
                                            'dt'    => $dev->marketplace_updated_at
                                                ? \Carbon\Carbon::parse($dev->marketplace_updated_at) : null,
                                            'color' => $cor,
                                            'meta'  => $txt
                                                . ($dev->negotiation_status ? ' | Negociação: ' . $dev->negotiation_status : ''),
                                        ];
                                    }
                                }

                                $html = '<div style="display:flex; flex-direction:column; gap:12px; padding:8px 0;">';
                                foreach ($eventos as $i => $ev) {
                                    $dtStr   = $ev['dt'] ? $ev['dt']->format('d/m/Y H:i:s') : '—';
                                    $opacity = $ev['dt'] ? '1' : '0.4';
                                    $html .= '<div style="display:flex; align-items:flex-start; gap:14px; opacity:' . $opacity . ';">';
                                    $html .= '<div style="width:36px; height:36px; border-radius:50%; background:' . $ev['color'] . '22; border:2px solid ' . $ev['color'] . '; display:flex; align-items:center; justify-content:center; font-size:1rem; flex-shrink:0;">' . $ev['icon'] . '</div>';
                                    $html .= '<div style="flex:1;">';
                                    $html .= '<div style="font-weight:700; font-size:0.85rem; color:' . $ev['color'] . ';">' . $ev['label'] . '</div>';
                                    $html .= '<div style="font-size:0.9rem; font-weight:600;">' . $dtStr . '</div>';
                                    $html .= '<div style="font-size:0.75rem; opacity:0.65;">' . htmlspecialchars($ev['meta']) . '</div>';
                                    $html .= '</div>';
                                    $html .= '</div>';
                                    if ($i < count($eventos) - 1) {
                                        $html .= '<div style="margin-left:16px; border-left:2px dashed #e5e7eb; height:12px;"></div>';
                                    }
                                }
                                $html .= '</div>';

                                return new HtmlString($html);
                            })
                            ->columnSpanFull(),
                    ])
                    ->collapsible(),

                // ---------------------------------------------------------------
                // ITEM 3 + 4: Seller e Pagamento lado a lado
                // ---------------------------------------------------------------
                Forms\Components\Grid::make(2)
                    ->schema([
                        // ITEM 3: Informações do Seller
                        Forms\Components\Section::make('Informações do Seller')
                            ->schema([
                                Forms\Components\Placeholder::make('seller_info')
                                    ->label('')
                                    ->content(function (Order $record): HtmlString {
                                        $client = $record->client;
                                        $user   = $client?->user;

                                        $nome  = $client?->company_name ?? $user?->name ?? 'N/A';
                                        $email = $user?->email ?? $client?->email ?? 'N/A';
                                        $phone = $client?->phone ?? $user?->phone ?? $record->customer_phone ?? 'N/A';
                                        $doc   = $client?->document ?? $record->customer_document_number ?? 'N/A';

                                        $emailEncoded = htmlspecialchars($email);

                                        $html  = '<div style="display:flex; flex-direction:column; gap:8px; font-size:0.875rem;">';
                                        $html .= '<div><span style="opacity:0.55; font-size:0.75rem;">NOME</span><br><strong>' . htmlspecialchars($nome) . '</strong></div>';

                                        // E-mail com botão copiar
                                        $html .= '<div style="display:flex; align-items:center; gap:8px;">';
                                        $html .= '<div><span style="opacity:0.55; font-size:0.75rem;">E-MAIL</span><br><span>' . $emailEncoded . '</span></div>';
                                        if ($email !== 'N/A') {
                                            $html .= '<button onclick="navigator.clipboard.writeText(\'' . $emailEncoded . '\').then(()=>{this.textContent=\'Copiado\'; setTimeout(()=>this.textContent=\'Copiar\',1500)});" '
                                                . 'style="font-size:0.7rem; padding:2px 8px; border-radius:4px; border:1px solid #d1d5db; cursor:pointer; background:#f9fafb; color:#374151; margin-top:14px; white-space:nowrap;">Copiar</button>';
                                        }
                                        $html .= '</div>';

                                        $html .= '<div><span style="opacity:0.55; font-size:0.75rem;">TELEFONE</span><br>' . htmlspecialchars($phone) . '</div>';
                                        $html .= '<div><span style="opacity:0.55; font-size:0.75rem;">CPF/CNPJ</span><br>' . htmlspecialchars($doc) . '</div>';
                                        $html .= '</div>';

                                        return new HtmlString($html);
                                    }),
                            ]),

                        // ITEM 4: Informações de Pagamento
                        Forms\Components\Section::make('Informações de Pagamento')
                            ->schema([
                                Forms\Components\Placeholder::make('payment_info')
                                    ->label('')
                                    ->content(function (Order $record): HtmlString {
                                        $html = '<div style="display:flex; flex-direction:column; gap:8px; font-size:0.875rem;">';

                                        // Data/hora do pagamento
                                        $html .= '<div><span style="opacity:0.55; font-size:0.75rem;">DATA/HORA PAGAMENTO</span><br>'
                                            . '<strong>' . ($record->paid_at
                                                ? $record->paid_at->format('d/m/Y H:i:s')
                                                : '<span style="color:#ef4444;">Não pago</span>') . '</strong></div>';

                                        // Método / gateway
                                        $captureSource = $record->capture_source ?? 'PIX';
                                        $html .= '<div><span style="opacity:0.55; font-size:0.75rem;">MÉTODO</span><br>' . strtoupper($captureSource) . '</div>';

                                        // ID da transação (wallet_transaction_id ou capture_payload)
                                        $txId = $record->wallet_transaction_id ?? null;
                                        if (! $txId && $record->capture_payload) {
                                            $payload = is_array($record->capture_payload)
                                                ? $record->capture_payload
                                                : json_decode($record->capture_payload, true);
                                            $txId = $payload['transaction_id'] ?? $payload['payment_id'] ?? $payload['id'] ?? null;
                                        }

                                        if ($txId) {
                                            $txIdStr = htmlspecialchars((string) $txId);
                                            $html .= '<div style="display:flex; align-items:center; gap:8px;">';
                                            $html .= '<div><span style="opacity:0.55; font-size:0.75rem;">ID TRANSAÇÃO</span><br>'
                                                . '<span style="font-family:monospace; font-size:0.8rem;">' . $txIdStr . '</span></div>';
                                            $html .= '<button onclick="navigator.clipboard.writeText(\'' . $txIdStr . '\').then(()=>{this.textContent=\'Copiado\'; setTimeout(()=>this.textContent=\'Copiar\',1500)});" '
                                                . 'style="font-size:0.7rem; padding:2px 8px; border-radius:4px; border:1px solid #d1d5db; cursor:pointer; background:#f9fafb; color:#374151; margin-top:14px; white-space:nowrap;">Copiar</button>';
                                            $html .= '</div>';
                                        } else {
                                            $html .= '<div><span style="opacity:0.55; font-size:0.75rem;">ID TRANSAÇÃO</span><br><span style="opacity:0.4;">N/A (legado)</span></div>';
                                        }

                                        // Repasse ao fornecedor
                                        if ($record->wallet_paid_at) {
                                            $html .= '<div><span style="opacity:0.55; font-size:0.75rem;">REPASSE FORNECEDOR</span><br>'
                                                . $record->wallet_paid_at->format('d/m/Y H:i:s') . '</div>';
                                        }

                                        // Valor pago
                                        $valorPago = (float) ($record->supplier_total ?? $record->total ?? 0);
                                        $html .= '<div><span style="opacity:0.55; font-size:0.75rem;">VALOR PAGO AO FORNECEDOR</span><br>'
                                            . '<strong>R$ ' . number_format($valorPago, 2, ',', '.') . '</strong></div>';

                                        $html .= '</div>';
                                        return new HtmlString($html);
                                    }),
                            ]),
                    ]),

                // ---------------------------------------------------------------
                // Dados básicos editáveis
                // ---------------------------------------------------------------
                Forms\Components\Section::make('Dados do Pedido')
                    ->schema([
                        // MUL-269 fase 2: label do seller vem do user (clients.company_name removido).
                        Forms\Components\Select::make('client_id')
                            ->label('Lojista (Seller)')
                            ->relationship('client', 'id')
                            ->getOptionLabelFromRecordUsing(fn ($record) => $record->company_name ?? ('Client #'.$record->id))
                            ->required()
                            ->searchable(),
                        Forms\Components\Select::make('supplier_id')
                            ->label('Fornecedor / Galpão')
                            ->relationship('supplier', 'company_name')
                            ->required()
                            ->searchable(),
                        Forms\Components\TextInput::make('order_number')
                            ->label('Número do Pedido')
                            ->maxLength(255)
                            ->helperText('Deixe em branco para geração automática.'),
                        Forms\Components\TextInput::make('external_order_id')
                            ->label('ID no Marketplace')
                            ->maxLength(255),
                        Forms\Components\Select::make('source')
                            ->label('Origem')
                            ->options([
                                'manual'       => 'Manual',
                                'mercadolivre' => 'Mercado Livre',
                                'shopee'       => 'Shopee',
                                'amazon'       => 'Amazon',
                                'b2w'          => 'B2W',
                                'magalu'       => 'Magazine Luiza',
                            ])
                            ->default('manual'),
                        // MUL-226-10: exibir a conta marketplace específica que originou a venda
                        Forms\Components\Placeholder::make('marketplace_display')
                            ->label('Marketplace')
                            ->content(function ($record) {
                                if (! $record) return '—';
                                if (! empty($record->marketplace_account_id) && $record->marketplaceAccount) {
                                    return $record->marketplaceAccount->account_name ?? '—';
                                }
                                $firstItem = $record->items->first();
                                if ($firstItem && $firstItem->clientProduct && $firstItem->clientProduct->marketplaceAccount) {
                                    return $firstItem->clientProduct->marketplaceAccount->account_name ?? '—';
                                }
                                return '—';
                            }),
                    ])->columns(2),

                Forms\Components\Section::make('Status')
                    ->schema([
                        // MUL-217: opções completas (17 status reais na base) — antes o Select
                        // required com lista parcial travava o save de pedidos shipped/delivered/etc
                        Forms\Components\Select::make('status')
                            ->label('Status do Pedido')
                            ->options([
                                'created'            => 'Criado',
                                'pending_payment'    => 'Aguardando Pagamento',
                                'unpaid'             => 'Não Pago',
                                'pending'            => 'Pendente',
                                'paid'               => 'Confirmado',
                                'processing'         => 'Processando',
                                'processed'          => 'Processado',
                                'ready_to_ship'      => 'Pronto p/ Envio',
                                'shipped'            => 'Enviado',
                                'to_confirm_receive' => 'Confirmar Recebimento',
                                'delivered'          => 'Entregue',
                                'completed'          => 'Concluído',
                                'in_cancel'          => 'Em Cancelamento',
                                'cancelled'          => 'Cancelado',
                                'refunded'           => 'Reembolsado',
                                'to_return'          => 'Em Devolução',
                                'returned'           => 'Devolvido',
                                'not_found'          => 'Inexistente (API)',
                            ])
                            ->required()
                            ->default('pending_payment'),
                        Forms\Components\Select::make('order_processing_status')
                            ->label('Status de Logística')
                            ->options([
                                'payment_pending'   => 'Aguardando Pagamento',
                                'awaiting_label'    => 'Aguardando Etiqueta',
                                'label_printed'     => 'Etiqueta Impressa',
                                'pending'           => 'Pendente',
                                'processing'        => 'Em Separação',
                                'separated'         => 'Separado',
                                'packed'            => 'Embalado',
                                'ready_for_pickup'  => 'Pronto para Coleta',
                                'awaiting_dispatch' => 'Pronto para Envio',
                                'shipped'           => 'Expedido',
                                'delivered'         => 'Entregue',
                                'cancelled'         => 'Cancelado',
                            ])
                            ->required()
                            ->default('awaiting_label'),
                    ])->columns(2),

                // ---------------------------------------------------------------
                // MUL-226-08: Obs. Expedição + ações Trocar Produto / Bloquear Pedido
                // ---------------------------------------------------------------
                Forms\Components\Section::make('Expedição')
                    ->schema([
                        Forms\Components\Placeholder::make('blocked_banner')
                            ->label('')
                            ->visible(fn ($record) => $record?->blocked_at !== null)
                            ->content(function ($record) {
                                $por = $record->blocked_by ? (\App\Models\User::find($record->blocked_by)?->name ?? 'usuário #' . $record->blocked_by) : 'sistema';
                                return new HtmlString(
                                    '<div style="background:#fef2f2;border:1px solid #ef4444;color:#b91c1c;padding:10px 14px;border-radius:8px;font-weight:600;">'
                                    . '🔒 PEDIDO BLOQUEADO em ' . $record->blocked_at->format('d/m/Y H:i')
                                    . ' por ' . e($por)
                                    . ($record->block_reason ? '<br><span style="font-weight:400;">Motivo: ' . e($record->block_reason) . '</span>' : '')
                                    . '</div>'
                                );
                            })
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('expedition_note')
                            ->label('Obs. Expedição')
                            ->placeholder('Observações para a equipe de expedição/separação...')
                            ->rows(2)
                            ->maxLength(1000),

                        Forms\Components\Actions::make([
                            Forms\Components\Actions\Action::make('trocarProduto')
                                ->label('Trocar Produto')
                                ->icon('heroicon-o-arrow-path-rounded-square')
                                ->color('warning')
                                ->visible(fn ($record) => $record !== null)
                                ->form(fn ($record) => [
                                    Forms\Components\Select::make('order_item_id')
                                        ->label('Item do pedido')
                                        ->options(
                                            $record->items->mapWithKeys(fn ($i) => [
                                                $i->id => '#' . ($i->sku ?? '?') . ' — ' . mb_strimwidth((string) ($i->name ?? 'Item'), 0, 60, '…') . ' (x' . $i->quantity . ')',
                                            ])->toArray()
                                        )
                                        ->required(),
                                    Forms\Components\Select::make('new_product_id')
                                        ->label('Novo produto (do mesmo fornecedor)')
                                        ->searchable()
                                        ->getSearchResultsUsing(fn (string $search) => \App\Models\Product::query()
                                            ->where('supplier_id', $record->supplier_id)
                                            ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%"))
                                            ->limit(20)
                                            ->get()
                                            ->mapWithKeys(fn ($p) => [$p->id => '#' . $p->sku . ' — ' . mb_strimwidth((string) $p->name, 0, 60, '…')])
                                            ->toArray())
                                        ->getOptionLabelUsing(fn ($value) => \App\Models\Product::find($value)?->name)
                                        ->required(),
                                ])
                                ->modalHeading('Trocar produto do pedido')
                                ->modalDescription('Altera produto/SKU do item selecionado. Valores do pedido NÃO são recalculados. Ação registrada em auditoria.')
                                ->modalSubmitActionLabel('Confirmar troca')
                                ->action(function (array $data, $record) {
                                    $item = $record->items()->find($data['order_item_id'] ?? null);
                                    $novo = \App\Models\Product::find($data['new_product_id'] ?? null);
                                    if (! $item || ! $novo) {
                                        \Filament\Notifications\Notification::make()->title('Item ou produto inválido')->danger()->send();
                                        return;
                                    }
                                    $antes = ['product_id' => $item->product_id, 'sku' => $item->sku, 'name' => $item->name];
                                    $item->update([
                                        'product_id' => $novo->id,
                                        'sku'        => $novo->sku,
                                        'name'       => $novo->name,
                                    ]);
                                    \Illuminate\Support\Facades\DB::table('order_audit_log')->insert([
                                        'order_id'   => $record->id,
                                        'actor_type' => 'admin_user',
                                        'actor_id'   => auth()->id(),
                                        'action'     => 'product_swapped',
                                        'from_state' => $antes['sku'],
                                        'to_state'   => $novo->sku,
                                        'metadata'   => json_encode([
                                            'order_item_id' => $item->id,
                                            'antes'         => $antes,
                                            'depois'        => ['product_id' => $novo->id, 'sku' => $novo->sku, 'name' => $novo->name],
                                        ], JSON_UNESCAPED_UNICODE),
                                        'at'         => now(),
                                    ]);
                                    \Filament\Notifications\Notification::make()
                                        ->title('Produto trocado')
                                        ->body("Item #{$antes['sku']} → #{$novo->sku}. Auditoria registrada.")
                                        ->success()
                                        ->send();
                                }),

                            Forms\Components\Actions\Action::make('bloquearPedido')
                                ->label(fn ($record) => $record?->blocked_at ? 'Desbloquear Pedido' : 'Bloquear Pedido')
                                ->icon(fn ($record) => $record?->blocked_at ? 'heroicon-o-lock-open' : 'heroicon-o-lock-closed')
                                ->color(fn ($record) => $record?->blocked_at ? 'success' : 'danger')
                                ->visible(fn ($record) => $record !== null)
                                ->form(fn ($record) => $record?->blocked_at ? [] : [
                                    Forms\Components\Textarea::make('reason')
                                        ->label('Motivo do bloqueio')
                                        ->required()
                                        ->rows(2)
                                        ->maxLength(500),
                                ])
                                ->requiresConfirmation()
                                ->modalHeading(fn ($record) => $record?->blocked_at ? 'Desbloquear este pedido?' : 'Bloquear este pedido?')
                                ->modalDescription('Pedido bloqueado sai da fila de impressão de etiquetas e alerta na bipagem. Ação registrada em auditoria.')
                                ->action(function (array $data, $record, $livewire) {
                                    if ($record->blocked_at) {
                                        $motivoAntigo = $record->block_reason;
                                        $record->update(['blocked_at' => null, 'blocked_by' => null, 'block_reason' => null]);
                                        $action = 'order_unblocked';
                                        $meta   = ['motivo_anterior' => $motivoAntigo];
                                        $titulo = 'Pedido desbloqueado';
                                    } else {
                                        $record->update([
                                            'blocked_at'   => now(),
                                            'blocked_by'   => auth()->id(),
                                            'block_reason' => $data['reason'] ?? null,
                                        ]);
                                        $action = 'order_blocked';
                                        $meta   = ['motivo' => $data['reason'] ?? null];
                                        $titulo = 'Pedido bloqueado';
                                    }
                                    \Illuminate\Support\Facades\DB::table('order_audit_log')->insert([
                                        'order_id'   => $record->id,
                                        'actor_type' => 'admin_user',
                                        'actor_id'   => auth()->id(),
                                        'action'     => $action,
                                        'from_state' => null,
                                        'to_state'   => null,
                                        'metadata'   => json_encode($meta, JSON_UNESCAPED_UNICODE),
                                        'at'         => now(),
                                    ]);
                                    \Filament\Notifications\Notification::make()->title($titulo)->success()->send();
                                    if (method_exists($livewire, 'refreshFormData')) {
                                        $livewire->refreshFormData(['blocked_at', 'blocked_by', 'block_reason']);
                                    }
                                }),
                        ])->columnSpanFull(),
                    ])
                    ->columns(1),

                Forms\Components\Section::make('Valores')
                    ->description('Custo (o quanto o lojista paga) vem antes da Venda (o quanto entrou).')
                    ->schema([
                        Forms\Components\TextInput::make('supplier_total')
                            ->label('Custo Fornecedor')
                            ->numeric()
                            ->prefix('R$')
                            ->default(0.00),
                        Forms\Components\TextInput::make('subtotal')
                            ->label('Subtotal Venda')
                            ->numeric()
                            ->prefix('R$')
                            ->default(0.00),
                        Forms\Components\TextInput::make('total')
                            ->label('Valor Total Pago')
                            ->required()
                            ->numeric()
                            ->prefix('R$')
                            ->default(0.00),
                        // MUL-226-08: custo efetivo calculado dos itens, ao lado do total
                        Forms\Components\Placeholder::make('custo_efetivo')
                            ->label('Custo Efetivo (soma dos itens)')
                            ->content(function ($record) {
                                if (! $record) return '—';
                                $soma = $record->items->sum(fn ($i) => (float) $i->supplier_unit_cost * max(1, (int) $i->quantity));
                                return 'R$ ' . number_format($soma, 2, ',', '.');
                            }),
                        Forms\Components\TextInput::make('shipping_cost')
                            ->label('Frete')
                            ->numeric()
                            ->prefix('R$')
                            ->default(0.00),
                        Forms\Components\TextInput::make('marketplace_fee')
                            ->label('Taxa Marketplace')
                            ->numeric()
                            ->prefix('R$')
                            ->default(0.00),
                    ])->columns(3),

                // ---------------------------------------------------------------
                // MUL-217: Itens do pedido com SKU editável
                // ---------------------------------------------------------------
                Forms\Components\Section::make('Itens do Pedido')
                    ->description('SKU editável (persiste em order_items.sku). Demais campos somente leitura. Alterar SKU não recalcula custo automaticamente.')
                    ->schema([
                        Forms\Components\Repeater::make('items')
                            ->relationship()
                            ->label('')
                            ->schema([
                                // MUL-226-08: imagem do produto ao lado do título
                                Forms\Components\Placeholder::make('item_image')
                                    ->label('Foto')
                                    ->content(function ($record) {
                                        $url = null;
                                        if ($record?->product) {
                                            $media = $record->product->media()
                                                ->where('type', 'image')
                                                ->orderByDesc('is_cover')
                                                ->orderBy('position')
                                                ->first();
                                            $url = $media?->url;
                                            if ($url && !str_starts_with($url, 'http')) {
                                                $url = asset($url);
                                            }
                                        }
                                        $sku = $record?->sku ?? '?';
                                        $fallback = 'https://ui-avatars.com/api/?name=' . urlencode(substr($sku, 0, 2)) . '&background=1e293b&color=94a3b8&size=80';
                                        $src = $url ?: $fallback;
                                        return new HtmlString('<img src="' . e($src) . '" style="width:48px;height:48px;border-radius:8px;object-fit:cover;border:1px solid #e5e7eb;" onerror="this.src=\'' . $fallback . '\'">');
                                    }),
                                Forms\Components\TextInput::make('sku')
                                    ->label('SKU')
                                    ->maxLength(255),
                                // MUL-226-08: título limitado a 60 chars com tooltip do título completo
                                Forms\Components\TextInput::make('name')
                                    ->label('Produto')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->afterStateHydrated(function (Forms\Components\TextInput $component, $record): void {
                                        if ($record && $record->name) {
                                            $component->state(mb_strimwidth((string) $record->name, 0, 60, '…'));
                                        }
                                    })
                                    ->extraInputAttributes(fn ($record) => ['title' => (string) ($record->name ?? '')]),
                                Forms\Components\TextInput::make('quantity')
                                    ->label('Qtd')
                                    ->disabled()
                                    ->dehydrated(false),
                                Forms\Components\TextInput::make('unit_price')
                                    ->label('Preço Venda')
                                    ->prefix('R$')
                                    ->disabled()
                                    ->dehydrated(false),
                                Forms\Components\TextInput::make('supplier_unit_cost')
                                    ->label('Custo Unit.')
                                    ->prefix('R$')
                                    ->disabled()
                                    ->dehydrated(false)
                                    // supplier_unit_cost esta em $hidden no model (nao vem
                                    // no attributesToArray) — hidrata direto do record
                                    ->afterStateHydrated(function (Forms\Components\TextInput $component, $record): void {
                                        if ($record) {
                                            $component->state($record->supplier_unit_cost);
                                        }
                                    }),
                            ])
                            ->columns(6)
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->columnSpanFull(),
                    ])
                    ->collapsible(),

                // ---------------------------------------------------------------
                // ITEM 1: Histórico de Etiquetas
                // ---------------------------------------------------------------
                Forms\Components\Section::make('Histórico de Etiquetas')
                    ->description('Logs de impressão de etiquetas em lote relacionados a este pedido.')
                    ->schema([
                        Forms\Components\Placeholder::make('label_history')
                            ->label('')
                            ->content(function (Order $record): HtmlString {
                                $supplierId = $record->supplier_id;
                                $logs = LabelPrintLog::where('supplier_id', $supplierId)
                                    ->whereJsonContains('order_ids', $record->id)
                                    ->orderByDesc('printed_at')
                                    ->limit(20)
                                    ->get();

                                if ($logs->isEmpty()) {
                                    return new HtmlString(
                                        '<p style="opacity:0.5; font-size:0.85rem; padding:4px 0;">Nenhuma etiqueta impressa para este pedido.</p>'
                                    );
                                }

                                $html = '<div style="display:flex; flex-direction:column; gap:8px;">';
                                foreach ($logs as $log) {
                                    $dt        = $log->printed_at
                                        ? $log->printed_at->format('d/m/Y H:i:s')
                                        : ($log->created_at ? $log->created_at->format('d/m/Y H:i:s') : 'N/A');
                                    $user      = $log->user?->name ?? 'Sistema';
                                    $batchSize = $log->batch_size ?? count((array) $log->order_ids);
                                    $printer   = $log->printer_type ?? 'padrão';
                                    $mkt       = $log->marketplace ? ' | ' . strtoupper($log->marketplace) : '';

                                    $html .= '<div style="padding:8px 12px; border-radius:6px; border:1px solid rgba(0,0,0,0.08); background:rgba(0,0,0,0.02);">';
                                    $html .= '<div style="font-weight:600; font-size:0.82rem;">🖨️ ' . $dt . '</div>';
                                    $html .= '<div style="font-size:0.75rem; opacity:0.65;">Por: ' . htmlspecialchars($user)
                                        . ' | Lote: ' . $batchSize . ' etiquetas'
                                        . ' | Impressora: ' . htmlspecialchars($printer) . $mkt . '</div>';
                                    $html .= '</div>';
                                }
                                $html .= '</div>';

                                return new HtmlString($html);
                            })
                            ->columnSpanFull(),
                    ])
                    ->collapsible(),
            ]);
    }

    // =========================================================================
    // TABLE — List view
    // =========================================================================

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // NOV-183: imagem do 1º item do pedido
                Tables\Columns\ImageColumn::make('item_image')
                    ->label('')
                    ->getStateUsing(fn (Order $record): ?string => $record->items->first()?->product_image ?: null)
                    ->square()
                    ->size(88)
                    ->defaultImageUrl('https://placehold.co/88x88/e5e7eb/9ca3af?text=%20'),

                Tables\Columns\TextColumn::make('order_number')
                    ->label('Nº Pedido')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function (Builder $q) use ($search) {
                            $q->where('order_number', 'like', "%{$search}%")
                                ->orWhere('external_order_id', 'like', "%{$search}%")
                                ->orWhere('marketplace_order_id', 'like', "%{$search}%");
                        });
                    })
                    ->sortable(),

                // NOV-183: WL de origem (tenant_slug real; origin_tenant_slug esta NULL na base)
                Tables\Columns\TextColumn::make('tenant_slug')
                    ->label('WL')
                    ->badge()
                    ->default('_unassigned')
                    ->formatStateUsing(fn (?string $state): string => match (true) {
                        in_array($state, ['multdrop', 'multdrop.app'], true) => 'MultDrop',
                        $state === 'mestoredrop'                             => 'MEStoreDrop',
                        $state === 'fornecefy'                               => 'Fornecefy',
                        $state === 'dropautopecas'                           => 'Drop Autopeças',
                        $state === 'hubai'                                   => 'HubAI',
                        $state === null, $state === '', $state === '_unassigned' => 'Sem WL',
                        default                                              => ucfirst($state),
                    })
                    ->color(fn (?string $state): string => match (true) {
                        in_array($state, ['multdrop', 'multdrop.app'], true) => 'success',
                        $state === 'mestoredrop'                             => 'danger',
                        $state === 'fornecefy'                               => 'warning',
                        $state === 'hubai'                                   => 'primary',
                        $state === null, $state === '', $state === '_unassigned' => 'gray',
                        default                                              => 'info',
                    })
                    ->sortable(),

                // MUL-269 fase 2: client.company_name via accessor; busca no user conectado.
                // Sortable removido: nao ha coluna fisica pra ordenar (accessor nao sortable).
                Tables\Columns\TextColumn::make('client.company_name')
                    ->label('Lojista')
                    ->searchable(query: fn ($query, $search) => $query->whereHas('client.user', fn ($u) => $u->where('name', 'like', "%{$search}%")->orWhere('full_name', 'like', "%{$search}%"))),

                // ITEM 2: E-mail do seller
                Tables\Columns\TextColumn::make('seller_email')
                    ->label('E-mail Seller')
                    ->getStateUsing(function (Order $record): string {
                        return $record->client?->user?->email
                            ?? $record->client?->email
                            ?? $record->customer_email
                            ?? '—';
                    })
                    ->copyable()
                    ->copyMessage('E-mail copiado!')
                    ->icon('heroicon-o-envelope')
                    ->iconColor('primary')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('client.user', fn ($q) => $q->where('email', 'like', "%{$search}%"))
                            ->orWhere('customer_email', 'like', "%{$search}%");
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                // NOV-183: fornecedor visivel por padrao
                Tables\Columns\TextColumn::make('supplier.company_name')
                    ->label('Fornecedor')
                    ->searchable()
                    ->limit(20)
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('source')
                    ->label('Canal')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'mercadolivre' => 'Mercado Livre',
                        'shopee'       => 'Shopee',
                        'amazon'       => 'Amazon',
                        'manual'       => 'Manual',
                        default        => $state ?? 'N/A',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'mercadolivre' => 'warning',
                        'shopee'       => 'danger',
                        'amazon'       => 'info',
                        default        => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending_payment' => 'Ag. Pagamento',
                        'pending'         => 'Pendente',
                        'paid'            => 'Confirmado',
                        'cancelled'       => 'Cancelado',
                        'refunded'        => 'Reembolsado',
                        'not_found'       => 'Inexistente (API)',
                        default           => $state,
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'paid'      => 'success',
                        'cancelled' => 'danger',
                        'refunded'  => 'warning',
                        default     => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('wallet_paid_at')
                    ->label('Pag. Fornecedor')
                    ->badge()
                    ->getStateUsing(fn(Order $record): string => $record->wallet_paid_at
                        ? 'Pago ' . \Carbon\Carbon::parse($record->wallet_paid_at)->format('d/m H:i')
                        : ($record->status === 'cancelled' ? '—' : 'Pendente'))
                    ->color(fn(Order $record): string => $record->wallet_paid_at
                        ? 'success'
                        : ($record->status === 'cancelled' ? 'gray' : 'warning'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('order_processing_status')
                    ->label('Status do Envio')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'awaiting_label'    => 'Aguardando Etiqueta',
                        'awaiting_dispatch' => 'Pronto para Envio',
                        'payment_pending'   => 'Aguardando Pagamento',
                        'pending'           => 'Separacao',
                        'processing'        => 'Conferindo',
                        'separated'         => 'Separado',
                        'packed'            => 'Embalado',
                        'ready_for_pickup'  => 'Coleta',
                        'shipped'           => 'Expedido',
                        'delivered'         => 'Entregue',
                        default             => $state,
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'shipped', 'delivered'                  => 'success',
                        'awaiting_label', 'payment_pending'     => 'warning',
                        'cancelled'                             => 'danger',
                        default                                 => 'gray',
                    })
                    ->sortable(),

                // ITEM 2: Custo do pedido
                Tables\Columns\TextColumn::make('supplier_total')
                    ->label('Custo')
                    ->money('BRL')
                    ->color('danger')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('total')
                    ->label('Venda')
                    ->money('BRL')
                    ->color('success')
                    ->weight('bold')
                    ->sortable(),

                Tables\Columns\TextColumn::make('margem')
                    ->label('Margem')
                    ->getStateUsing(function (Order $record): ?string {
                        $venda = (float) $record->total;
                        $custo = (float) $record->supplier_total;
                        if ($venda <= 0) {
                            return null;
                        }
                        $margem = (($venda - $custo) / $venda) * 100;
                        return number_format($margem, 1, ',', '.') . '%';
                    })
                    ->badge()
                    ->color(function (Order $record): string {
                        $venda = (float) $record->total;
                        $custo = (float) $record->supplier_total;
                        if ($venda <= 0) {
                            return 'gray';
                        }
                        $margem = (($venda - $custo) / $venda) * 100;
                        return $margem >= 30 ? 'success' : ($margem >= 10 ? 'warning' : 'danger');
                    })
                    ->placeholder('—'),

                // ITEM 2: Datas validadas
                Tables\Columns\TextColumn::make('paid_at')
                    ->label('Pago em')
                    ->dateTime('d/m H:i')
                    ->sortable()
                    ->placeholder('—')
                    ->color('success'),

                // MUL-237: data real da venda no marketplace com fallback em created_at
                Tables\Columns\TextColumn::make('marketplace_created_at')
                    ->label('Data Venda')
                    ->state(fn ($record) => $record->marketplace_created_at ?? $record->created_at)
                    // MUL-299: com ANO — pedido de 2025 aparecia como "18/12" e parecia deste ano
                    ->dateTime('d/m/Y H:i')
                    ->description(fn ($record) => $record->marketplace_created_at ? null : 'sem data de venda — importacao')
                    // MUL-299: ordena pelo mesmo valor que exibe (com o fallback), nao pela coluna crua
                    ->sortable(query: fn ($query, string $direction) => $query->orderByRaw('COALESCE(marketplace_created_at, created_at) ' . ($direction === 'asc' ? 'asc' : 'desc')))
                    ->toggleable(isToggledHiddenByDefault: false),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Importado em')
                    ->dateTime('d/m H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('shipped_at')
                    ->label('Enviado em')
                    ->dateTime('d/m H:i')
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                // NOV-171-E: Badge de origem da federacao
                // Visivel apenas quando origin_tenant_slug preenchido e diferente de 'hubai' (pedido nativo hub)
                Tables\Columns\TextColumn::make('origin_tenant_slug')
                    ->label('Origem')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'multdrop'    => 'MultDrop',
                        'fornecefy'   => 'Fornecefy',
                        'mestoredrop' => 'MEStoreDrop',
                        default       => ucfirst($state ?? ''),
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'multdrop'    => 'success',
                        'fornecefy'   => 'warning',
                        'mestoredrop' => 'danger',
                        default       => 'gray',
                    })
                    ->placeholder('')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status do Pedido')
                    ->options([
                        'pending_payment' => 'Aguardando Pagamento',
                        'pending'         => 'Pendente',
                        'paid'            => 'Confirmado',
                        'cancelled'       => 'Cancelado',
                        'refunded'        => 'Reembolsado',
                    ]),

                Tables\Filters\SelectFilter::make('order_processing_status')
                    ->label('Status de Envio')
                    ->options([
                        'awaiting_label'   => 'Aguardando Etiqueta',
                        'pending'          => 'Pendente',
                        'processing'       => 'Em Separação',
                        'separated'        => 'Separado',
                        'ready_for_pickup' => 'Pronto para Coleta',
                        'shipped'          => 'Expedido',
                        'delivered'        => 'Entregue',
                    ]),

                Tables\Filters\SelectFilter::make('source')
                    ->label('Canal')
                    ->options([
                        'mercadolivre' => 'Mercado Livre',
                        'shopee'       => 'Shopee',
                        'manual'       => 'Manual',
                        'amazon'       => 'Amazon',
                    ]),

                // NOV-183: filtro por WL (multdrop tem 2 grafias na base; sem_wl = NULL/_unassigned)
                Tables\Filters\SelectFilter::make('wl')
                    ->label('WL')
                    ->options([
                        'hubai'         => 'HubAI',
                        'multdrop'      => 'MultDrop',
                        'mestoredrop'   => 'MEStoreDrop',
                        'fornecefy'     => 'Fornecefy',
                        'dropautopecas' => 'Drop Autopeças',
                        'sem_wl'        => 'Sem WL',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'multdrop' => $query->whereIn('tenant_slug', ['multdrop', 'multdrop.app']),
                            'sem_wl'   => $query->where(fn (Builder $q) => $q
                                ->whereNull('tenant_slug')
                                ->orWhere('tenant_slug', '')
                                ->orWhere('tenant_slug', '_unassigned')),
                            null, ''   => $query,
                            default    => $query->where('tenant_slug', $data['value']),
                        };
                    }),

                // NOV-183: filtro por fornecedor
                Tables\Filters\SelectFilter::make('supplier_id')
                    ->label('Fornecedor')
                    ->relationship('supplier', 'company_name')
                    ->searchable()
                    ->preload(),

                // NOV-183: pedidos sem custo (base imperfeita pra repasse as WLs)
                Tables\Filters\Filter::make('sem_custo')
                    ->label('Sem Custo')
                    ->query(fn (Builder $query) => $query->where(fn (Builder $q) => $q
                        ->whereNull('supplier_total')
                        ->orWhere('supplier_total', '<=', 0))),

                // ITEM 2: Filtros rápidos de data
                // MUL-237: filtro por data real da venda no marketplace
                Tables\Filters\Filter::make('sold_today')
                    ->label('Vendas Hoje')
                    ->query(fn (Builder $query) => $query->whereDate('marketplace_created_at', today())),

                Tables\Filters\Filter::make('created_today')
                    ->label('Criados Hoje')
                    ->query(fn (Builder $query) => $query->whereDate('created_at', today())),

                // NOV-204b: pagamento ao FORNECEDOR (wallet_paid_at), nao marketplace (paid_at)
                Tables\Filters\Filter::make('paid_orders')
                    ->label('Pagos ao Fornecedor')
                    ->query(fn (Builder $query) => $query->whereNotNull('wallet_paid_at')),

                Tables\Filters\Filter::make('unpaid_orders')
                    ->label('Nao Pagos ao Fornecedor')
                    ->query(fn (Builder $query) => $query->whereNull('wallet_paid_at')),

                // MUL-202-C v2: rascunhos (incompletos: guards custo zero + observer_safety_net).
                // Default=false esconde rascunhos, "true" mostra somente rascunhos, "vazio" mostra todos.
                Tables\Filters\TernaryFilter::make('is_draft')
                    ->label('Rascunhos')
                    ->placeholder('Todos')
                    ->trueLabel('Somente rascunhos')
                    ->falseLabel('Sem rascunhos (padrao)')
                    ->default(false)
                    ->queries(
                        true:  fn (Builder $q) => $q->where('is_draft', 1),
                        false: fn (Builder $q) => $q->where('is_draft', 0),
                        blank: fn (Builder $q) => $q,
                    ),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Detalhes'),

                // INF-036: reprocessar rascunho — dispara o enrich job correto
                // por source, ignorando cooldown e teto de tentativas (--force).
                Tables\Actions\Action::make('reprocessarRascunho')
                    ->label('Reprocessar rascunho')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn ($record) => (bool) $record->is_draft)
                    ->requiresConfirmation()
                    ->modalDescription('Vai buscar os dados do pedido na API do marketplace novamente e tentar promover. Ignora cooldown e teto de tentativas.')
                    ->action(function ($record): void {
                        $dispatched = false;
                        // Bling nao usa marketplace_account_id (conta em erp_accounts via supplier_id)
                        $canApi = match ($record->source) {
                            'shopee', 'mercadolivre' => $record->marketplace_order_id && $record->marketplace_account_id,
                            'bling'                  => (bool) $record->external_order_id,
                            default                  => false,
                        };
                        if ($canApi) {
                            switch ($record->source) {
                                case 'shopee':
                                    \App\Jobs\EnrichShopeeOrderJob::dispatch($record->id);
                                    $dispatched = true;
                                    break;
                                case 'mercadolivre':
                                    \App\Jobs\EnrichMercadoLivreOrderJob::dispatch($record->id);
                                    $dispatched = true;
                                    break;
                                case 'bling':
                                    \App\Jobs\EnrichBlingOrderJob::dispatch($record->id);
                                    $dispatched = true;
                                    break;
                            }
                        }

                        if (! $dispatched) {
                            // Fallback: recheck com promoter (sem API)
                            try {
                                [$ok] = app(\App\Services\Orders\DraftOrderPromoter::class)
                                    ->promote($record, 'manual_recheck');
                                \Filament\Notifications\Notification::make()
                                    ->title($ok ? 'Pedido promovido' : 'Ainda incompleto')
                                    ->body($ok ? 'Recheck sem API resolveu.' : 'Sem source suportada ou conta faltando — recheck nao promoveu.')
                                    ->{$ok ? 'success' : 'warning'}()
                                    ->send();
                            } catch (\Throwable $e) {
                                \Filament\Notifications\Notification::make()
                                    ->title('Erro no recheck')->body($e->getMessage())->danger()->send();
                            }
                            return;
                        }

                        // Zerar last_enriched_at pra job rodar imediato (bypass cooldown)
                        $record->last_enriched_at = null;
                        $record->saveQuietly();

                        \Filament\Notifications\Notification::make()
                            ->title('Reprocessamento enfileirado')
                            ->body("Job {$record->source} despachado para pedido {$record->order_number}. Atualize em alguns segundos.")
                            ->success()
                            ->send();
                    }),

                // NOV-120: registrar devolução de itens
                Tables\Actions\Action::make('registrarRetorno')
                    ->label('Registrar Retorno')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->visible(fn ($record) => in_array($record->status, ['shipped', 'delivered', 'completed']))
                    ->form(function ($record) {
                        $items = $record->items()->with('product')->get();
                        return [
                            \Filament\Forms\Components\Textarea::make('reason')
                                ->label('Motivo (obrigatório)')
                                ->required()
                                ->rows(2),
                            \Filament\Forms\Components\Repeater::make('items')
                                ->label('Itens devolvidos')
                                ->default($items->map(fn ($i) => [
                                    'order_item_id' => $i->id,
                                    'sku'           => $i->product?->sku ?? '-',
                                    'name'          => $i->product?->name ?? ('item #' . $i->id),
                                    'max_qty'       => $i->quantity,
                                    'qty_returned'  => 0,
                                ])->all())
                                ->schema([
                                    \Filament\Forms\Components\Hidden::make('order_item_id'),
                                    \Filament\Forms\Components\TextInput::make('sku')->label('SKU')->disabled(),
                                    \Filament\Forms\Components\TextInput::make('name')->label('Produto')->disabled(),
                                    \Filament\Forms\Components\TextInput::make('max_qty')->label('Qtd pedido')->disabled(),
                                    \Filament\Forms\Components\TextInput::make('qty_returned')
                                        ->label('Qtd devolvida')
                                        ->numeric()
                                        ->minValue(0)
                                        ->required(),
                                ])
                                ->columns(4)
                                ->addable(false)
                                ->deletable(false),
                        ];
                    })
                    ->action(function ($record, array $data): void {
                        $items = collect($data['items'] ?? [])
                            ->filter(fn ($i) => (int) ($i['qty_returned'] ?? 0) > 0)
                            ->map(fn ($i) => [
                                'order_item_id' => (int) $i['order_item_id'],
                                'qty_returned'  => (int) $i['qty_returned'],
                            ])
                            ->all();

                        if (empty($items)) {
                            \Filament\Notifications\Notification::make()
                                ->title('Informe ao menos 1 item com qty > 0')
                                ->warning()->send();
                            return;
                        }

                        try {
                            $svc     = app(\App\Services\Orders\OrderReturnService::class);
                            $returns = $svc->register($record, $items, $data['reason'] ?? null, auth()->id());
                            \Filament\Notifications\Notification::make()
                                ->title('Retorno registrado')
                                ->body(count($returns) . ' itens devolvidos. Estoque reposto automaticamente. Status do pedido: ' . $record->fresh()->status)
                                ->success()->send();
                        } catch (\Throwable $e) {
                            \Filament\Notifications\Notification::make()
                                ->title('Falha ao registrar retorno')
                                ->body($e->getMessage())
                                ->danger()->send();
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    // NOV-119: marcar como enviado em lote (apenas pedidos paid)
                    Tables\Actions\BulkAction::make('marcarEnviado')
                        ->label('Marcar como enviado')
                        ->icon('heroicon-o-truck')
                        ->color('success')
                        ->requiresConfirmation()
                        ->form([
                            \Filament\Forms\Components\TextInput::make('tracking_code')
                                ->label('Código de rastreio comum (opcional)')
                                ->helperText('Deixe vazio para manter o tracking_code já existente em cada pedido.'),
                        ])
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records, array $data): void {
                            $tracking = $data['tracking_code'] ?? null;
                            $ok       = 0;
                            $skipped  = 0;
                            foreach ($records as $order) {
                                if (! in_array($order->status, ['paid', 'processing'])) {
                                    $skipped++;
                                    continue;
                                }
                                $order->status     = 'shipped';
                                $order->shipped_at = now();
                                if ($tracking) {
                                    $order->tracking_code = $tracking;
                                }
                                $order->save();
                                $ok++;
                            }
                            \Filament\Notifications\Notification::make()
                                ->title("{$ok} pedidos marcados como enviados")
                                ->body($skipped > 0 ? "{$skipped} pedidos ignorados (status inválido)." : null)
                                ->success()
                                ->send();
                        }),

                    // NOV-119: imprimir etiquetas em lote
                    Tables\Actions\BulkAction::make('imprimirEtiquetasLote')
                        ->label('Imprimir etiquetas')
                        ->icon('heroicon-o-printer')
                        ->color('info')
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records): void {
                            $orderIds = $records->pluck('id')->all();
                            if (empty($orderIds)) {
                                \Filament\Notifications\Notification::make()->title('Nenhum pedido selecionado')->warning()->send();
                                return;
                            }
                            foreach ($orderIds as $id) {
                                if (class_exists(\App\Jobs\FetchShippingLabelJob::class)) {
                                    \App\Jobs\FetchShippingLabelJob::dispatch($id)->onQueue('default');
                                }
                            }
                            \Filament\Notifications\Notification::make()
                                ->title('Etiquetas em processamento')
                                ->body(count($orderIds) . ' pedidos enviados para geração de etiquetas. Atualize a tela em 1-2 minutos para baixar os PDFs.')
                                ->success()
                                ->send();
                        }),

                    // NOV-119: pagar em lote 100% saldo
                    Tables\Actions\BulkAction::make('pagarLote')
                        ->label('Pagar em lote (saldo)')
                        ->icon('heroicon-o-credit-card')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalDescription('Pagamento 100% via saldo (ClientSupplierBalance). Pedidos com gateway MercadoPago serão bloqueados. Para PIX use o detalhe do pedido.')
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records): void {
                            $eligible = $records->filter(fn ($o) => in_array($o->status, ['pending', 'partial']))->values();
                            if ($eligible->isEmpty()) {
                                \Filament\Notifications\Notification::make()->title('Nenhum pedido elegível (status pending/partial)')->warning()->send();
                                return;
                            }
                            try {
                                $orderIds   = $eligible->pluck('id')->all();
                                $controller = app(\App\Http\Controllers\Api\V1\WalletController::class);
                                $request    = new \Illuminate\Http\Request();
                                $request->merge(['order_ids' => $orderIds]);
                                $response = $controller->payWithBalance($request);
                                $data     = $response->getData(true);
                                \Filament\Notifications\Notification::make()
                                    ->title('Pagamento em lote concluído')
                                    ->body(json_encode($data, JSON_UNESCAPED_UNICODE))
                                    ->success()
                                    ->send();
                            } catch (\Throwable $e) {
                                \Filament\Notifications\Notification::make()
                                    ->title('Falha no pagamento em lote')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        }),

                    // NOV-122: marcar como pago manualmente em lote
                    Tables\Actions\BulkAction::make('marcarComoPago')
                        ->label('Confirmar pagamento manualmente')
                        ->icon('heroicon-o-check-badge')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalDescription('Marca os pedidos selecionados como PAGOS sem gerar PIX. Use apenas quando você tem certeza que o pagamento ja foi recebido por fora. Registra audit log.')
                        ->form([
                            \Filament\Forms\Components\Textarea::make('reason')
                                ->label('Justificativa (obrigatorio)')
                                ->required()
                                ->rows(2)
                                ->placeholder('Ex: deposito recebido via TED dia 27/06, comprovante anexo no ticket SAC #1234'),
                        ])
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records, array $data): void {
                            $ok      = 0;
                            $skipped = 0;
                            $reason  = $data['reason'];
                            foreach ($records as $order) {
                                if (! in_array($order->status, ['pending', 'pending_payment', 'partial'])) {
                                    $skipped++;
                                    continue;
                                }
                                \DB::transaction(function () use ($order, $reason) {
                                    $order->status  = 'paid';
                                    $order->paid_at = now();
                                    $order->save();
                                    \DB::table('audit_logs')->insert([
                                        'user_id'        => auth()->id(),
                                        'event'          => 'order.manual_payment',
                                        'auditable_type' => \App\Models\Order::class,
                                        'auditable_id'   => $order->id,
                                        'metadata'       => json_encode(['reason' => $reason, 'order_number' => $order->order_number]),
                                        'created_at'     => now(),
                                        'updated_at'     => now(),
                                    ]);
                                });
                                $ok++;
                            }
                            \Filament\Notifications\Notification::make()
                                ->title("{$ok} pedidos confirmados como pagos")
                                ->body($skipped > 0 ? "{$skipped} ignorados (status invalido)." : 'Audit log registrado.')
                                ->success()
                                ->send();
                        }),

                    // INF-036: reprocessar rascunhos em lote (max 200 por vez)
                    Tables\Actions\BulkAction::make('reprocessarRascunhosLote')
                        ->label('Reprocessar rascunhos (lote)')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalDescription('Vai enfileirar reprocessamento dos rascunhos selecionados. Ignora cooldown. Nao-rascunhos e sem source suportada sao ignorados.')
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records): void {
                            $dispatched = 0;
                            $skipped    = 0;
                            $index      = 0;
                            foreach ($records as $order) {
                                $canApi = match ($order->source) {
                                    'shopee', 'mercadolivre' => $order->marketplace_order_id && $order->marketplace_account_id,
                                    'bling'                  => (bool) $order->external_order_id,
                                    default                  => false,
                                };
                                if (! $order->is_draft || ! $canApi) {
                                    $skipped++;
                                    continue;
                                }
                                $delaySec = 2 * $index; // espaça pra nao estourar rate limit
                                switch ($order->source) {
                                    case 'shopee':
                                        \App\Jobs\EnrichShopeeOrderJob::dispatch($order->id)
                                            ->delay(now()->addSeconds($delaySec));
                                        break;
                                    case 'mercadolivre':
                                        \App\Jobs\EnrichMercadoLivreOrderJob::dispatch($order->id)
                                            ->delay(now()->addSeconds($delaySec));
                                        break;
                                    case 'bling':
                                        \App\Jobs\EnrichBlingOrderJob::dispatch($order->id)
                                            ->delay(now()->addSeconds($delaySec));
                                        break;
                                    default:
                                        $skipped++;
                                        continue 2;
                                }
                                $order->last_enriched_at = null;
                                $order->saveQuietly();
                                $dispatched++;
                                $index++;
                            }
                            \Filament\Notifications\Notification::make()
                                ->title("{$dispatched} reprocessamentos enfileirados")
                                ->body($skipped > 0 ? "{$skipped} ignorados (nao-rascunho, sem source ou conta faltando)." : 'Todos foram enfileirados.')
                                ->success()
                                ->send();
                        }),

                    Tables\Actions\DeleteBulkAction::make()->label('Excluir'),
                ]),
            ])
            // MUL-299: ordena pela data da VENDA, caindo em created_at so quando nao existe
            ->defaultSort(fn ($query) => $query->orderByRaw('COALESCE(marketplace_created_at, created_at) DESC'));
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\EventsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListOrders::route('/'),
            'create' => Pages\CreateOrder::route('/create'),
            'edit'   => Pages\EditOrder::route('/{record}/edit'),
        ];
    }
}
