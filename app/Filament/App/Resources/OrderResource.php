<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\OrderResource\Pages;
use App\Jobs\FetchShippingLabelJob;
use App\Models\Order;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;
    protected static ?string $slug = 'pedidos';

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';
    protected static ?int $navigationSort = 2;
    protected static ?string $navigationLabel = 'Meus Pedidos';
    protected static ?string $modelLabel = 'Pedido';


    public static function canCreate(): bool { return false; }
    public static function canEdit($record): bool { return false; }
    public static function canDelete($record): bool { return false; }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $client = auth()->user()?->client;
        if ($client) {
            $query->where("client_id", $client->id);
        }
        // INF-036: pedido inexistente na API do marketplace fica oculto no painel do cliente.
        $query->where('status', '!=', \App\Enums\OrderStatus::NOT_FOUND->value);
        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Progresso do Pedido')
                    ->schema([
                        Forms\Components\Placeholder::make('timeline')
                            ->label('')
                            ->content(function (Order $record): HtmlString {
                                return new HtmlString(
                                    view('filament.components.order-timeline', ['record' => $record])->render()
                                );
                            })
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Histórico do Pedido e Qualidade (SLA)')
                    ->description('Acompanhe o fluxo logístico e a previsão de despacho do pedido.')
                    ->schema([
                        Forms\Components\Placeholder::make('historico')
                            ->label('')
                            ->content(function (Order $record) {
                                $paid = $record->paid_at;
                                $slaNote = '';

                                if ($paid) {
                                    $hour = (int) $paid->format('H');
                                    if ($hour < 12) {
                                        $slaNote = '<span style="color:green; font-weight:bold;">Prazo de Envio: Hoje</span>';
                                    } else {
                                        $slaNote = '<span style="color:orange; font-weight:bold;">Prazo de Envio: Amanha</span>';
                                    }
                                } else {
                                    $slaNote = '<span style="color:red; font-weight:bold;">Aguardando Pagamento do Frete. O Fornecedor ainda nao recebeu este pedido.</span>';
                                }

                                $html = '<div style="padding:15px; border-radius:8px; margin-bottom: 10px;">';
                                $html .= '<h4>Status de Envio: ' . $slaNote . '</h4><br/>';
                                $html .= '<ul style="list-style-type: none; padding-left: 0; line-height: 1.8;">';

                                // MUL-299: data da venda = marketplace_created_at; created_at e a importacao
                                $dtVenda = $record->marketplace_created_at ?? $record->created_at;
                                $html .= '<li>🛍️ <b>[' . ($dtVenda ? $dtVenda->format('H:i:s d/m/Y') : 'N/A') . ']</b> Venda cai no Marketplace (' . $record->source . ').</li>';
                                if ($record->marketplace_created_at && $record->created_at
                                    && $record->marketplace_created_at->format('Y-m-d') !== $record->created_at->format('Y-m-d')) {
                                    $html .= '<li>📥 <b>[' . $record->created_at->format('H:i:s d/m/Y') . ']</b> Importado para o sistema (nao e a data da venda).</li>';
                                }

                                if ($record->paid_at) {
                                    $html .= '<li>💸 <b>[' . $record->paid_at->format('H:i:s d/m/Y') . ']</b> Lojista pagou o Produto/Frete.</li>';
                                }

                                if ($record->separated_at) {
                                    $html .= '<li>📦 <b>[' . $record->separated_at->format('H:i:s d/m/Y') . ']</b> Fornecedor iniciou separação.</li>';
                                }

                                if ($record->label_printed_at) {
                                    $html .= '<li>🖨️ <b>[' . $record->label_printed_at->format('H:i:s d/m/Y') . ']</b> Etiqueta Impressa (Processo Logístico).</li>';
                                }

                                if ($record->shipped_at) {
                                    $html .= '<li>🚚 <b>[' . $record->shipped_at->format('H:i:s d/m/Y') . ']</b> Despachado (Entregue à Transportadora).</li>';
                                }

                                $html .= '</ul></div>';

                                return new HtmlString($html);
                            })
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Produtos do Pedido')
                    ->schema([
                        Forms\Components\Placeholder::make('items_list')
                            ->label('')
                            ->content(function (Order $record): HtmlString {
                                $html = '<div style="display:flex; flex-direction:column; gap:12px;">';
                                foreach ($record->items as $item) {
                                    $mediaUrl = null;
                                    if ($item->product_id) {
                                        $media = \App\Models\ProductMedia::where('product_id', $item->product_id)->first();
                                        $mediaUrl = $media ? asset($media->url) : null;
                                    }
                                    $img = $mediaUrl ?? 'https://ui-avatars.com/api/?name=' . urlencode(substr($item->sku ?? '?', 0, 2)) . '&background=1e293b&color=94a3b8&size=80';

                                    $html .= '<div style="display:flex; align-items:center; gap:12px; padding:10px; background:var(--fi-body-bg, rgba(255,255,255,0.03)); border-radius:10px; border:1px solid color-mix(in srgb, currentColor 8%, transparent);">';
                                    $html .= '<img src="' . $img . '" style="width:56px; height:56px; border-radius:8px; object-fit:cover; border:1px solid color-mix(in srgb, currentColor 10%, transparent);">';
                                    $html .= '<div style="flex:1; min-width:0;">';
                                    $html .= '<div style="font-weight:600; font-size:0.9rem;">' . htmlspecialchars($item->name ?? 'Item') . '</div>';
                                    $html .= '<div style="font-size:0.8rem; opacity:0.6; font-family:monospace;">SKU: ' . ($item->sku ?? 'N/A') . '</div>';
                                    $html .= '</div>';
                                    $html .= '<div style="text-align:right; white-space:nowrap;">';
                                    $html .= '<div style="font-weight:700;">R$ ' . number_format($item->unit_price, 2, ',', '.') . '</div>';
                                    $html .= '<div style="font-size:0.8rem; opacity:0.6;">x' . $item->quantity . ' = R$ ' . number_format($item->total, 2, ',', '.') . '</div>';
                                    // Custo visível somente para Pro/Scale (max_skus > 30) e admins.
                                    $clientModel = auth()->user()?->client;
                                    $userRole = auth()->user()?->role ?? '';
                                    $isAdmin = in_array($userRole, ['super_admin', 'admin', 'staff']);
                                    $canSeeCost = $isAdmin || ($clientModel && ! $clientModel->isStartPlan());
                                    if ($canSeeCost) {
                                        $html .= '<div style="font-size:0.75rem; opacity:0.45;">Custo: R$ ' . number_format($item->supplier_unit_cost, 2, ',', '.') . '</div>';
                                    }
                                    $html .= '</div>';
                                    $html .= '</div>';
                                }
                                $html .= '</div>';
                                return new HtmlString($html);
                            })
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Detalhes Comerciais')
                    ->schema([
                        Forms\Components\TextInput::make('order_number')->label('ID Interno')->disabled(),
                        Forms\Components\TextInput::make('external_order_id')->label('ID Integração')->disabled(),
                        Forms\Components\TextInput::make('source')->label('Canal (MercadoLivre/Shopee)')->disabled(),
                        // MUL-226-10: exibir a conta marketplace específica que originou a venda
                        Forms\Components\Placeholder::make('marketplace_account')
                            ->label('Marketplace')
                            ->content(function (?Order $record) {
                                if (! $record) return '—';
                                $firstItem = $record->items->first();
                                if ($firstItem && $firstItem->clientProduct && $firstItem->clientProduct->marketplaceAccount) {
                                    return $firstItem->clientProduct->marketplaceAccount->account_name ?? '—';
                                }
                                return '—';
                            }),
                        Forms\Components\TextInput::make('total')->label('Total R$')->disabled(),
                        Forms\Components\TextInput::make('tracking_number')->label('Código de Rastreio')->disabled(),
                    ])->columns(2),

                Forms\Components\Section::make('Simulador de Lucratividade (Estimativa)')
                    ->description('Cálculo gerencial baseado nos custos do produto, imposto base e política de taxas por item (Fixo + Comissão) dos canais de venda.')
                    ->schema([
                        Forms\Components\Placeholder::make('calculo_lucro')
                            ->label('')
                            ->content(function (Order $record) {
                                $source = strtolower($record->source);
                                $receitaBruta = (float) $record->total;
                    
                                // Custo do Fornecedor (O quanto o Lojista paga à HubAI/Fornecedor)
                                $custoFornecedor = 0;
                                $totalItems = 0;
                                foreach ($record->items as $item) {
                                    $custoFornecedor += (float) $item->supplier_total_cost;
                                    $totalItems += $item->quantity;
                                }

                                // Definir Regras do Marketplace
                                $mktFeePercent = 0;
                                $mktFeeFixed = 0;
                                $impostoPercent = 0.06; // Estimativa Simples Nacional (6%)
                                $ticketMedio = $totalItems > 0 ? ($receitaBruta / $totalItems) : 0;

                                if (str_contains($source, 'shopee')) {
                                    $mktFeePercent = 0.20; // 20% Padrão Programa Frete Grátis
                                    $mktFeeFixed = 4.00 * $totalItems; // Taxa Fixa por item vendido
                                } elseif (str_contains($source, 'mercadolivre') || str_contains($source, 'mercado livre')) {
                                    $mktFeePercent = 0.16; // 16% média (Classico/Premium)
                                    if ($ticketMedio < 79) {
                                        $mktFeeFixed = 6.00 * $totalItems; // Taxa Fixa de R$ 6 por unidade (Abaixo de R$79)
                                    }
                                    // Se acima de 79, o frete grátis mandatório seria cobrado do Lojista, mas assumimos que o ERP ou Hubai ajusta o subtotal ou tarifa na precificação.
                                } else {
                                    $mktFeePercent = 0.05; // Outros canais, gateway
                                }

                                $comissaoMkt = $receitaBruta * $mktFeePercent;
                                $totalTaxasMkt = $comissaoMkt + $mktFeeFixed;
                                $impostoValor = $receitaBruta * $impostoPercent;

                                $lucroLiquido = $receitaBruta - $custoFornecedor - $totalTaxasMkt - $impostoValor;
                                $margemLiquida = $receitaBruta > 0 ? ($lucroLiquido / $receitaBruta) * 100 : 0;

                                $corLucro = $lucroLiquido >= 0 ? '#10b981' : '#ef4444'; // Verde ou Vermelho
                    
                                $html = '<div style="padding:20px; border-radius:8px; border:1px solid color-mix(in srgb, currentColor 12%, transparent); display:flex; flex-direction:column; gap:8px;">';
                                $html .= '<div style="display:flex; justify-content:space-between; border-bottom:1px dashed color-mix(in srgb, currentColor 15%, transparent); padding-bottom:8px;">
                                            <span style="opacity:0.6;">Receita Bruta (Venda Produtos):</span>
                                            <span style="font-weight:bold;">R$ ' . number_format($receitaBruta, 2, ',', '.') . '</span>
                                          </div>';

                                $html .= '<div style="display:flex; justify-content:space-between; color:#ef4444;">
                                            <span>(-) Custo Fornecedor HubAI:</span>
                                            <span>R$ ' . number_format($custoFornecedor, 2, ',', '.') . '</span>
                                          </div>';

                                $html .= '<div style="display:flex; justify-content:space-between; color:#ef4444;">
                                            <span>(-) Comissão Marketplace (' . ($mktFeePercent * 100) . '%):</span>
                                            <span>R$ ' . number_format($comissaoMkt, 2, ',', '.') . '</span>
                                          </div>';

                                $html .= '<div style="display:flex; justify-content:space-between; color:#ef4444;">
                                            <span>(-) Taxa Fixa por Produto (' . $totalItems . ' unid):</span>
                                            <span>R$ ' . number_format($mktFeeFixed, 2, ',', '.') . '</span>
                                          </div>';

                                $html .= '<div style="display:flex; justify-content:space-between; color:#ef4444; border-bottom:1px solid color-mix(in srgb, currentColor 12%, transparent); padding-bottom:10px;">
                                            <span>(-) Impostos Estimados (' . ($impostoPercent * 100) . '% SN):</span>
                                            <span>R$ ' . number_format($impostoValor, 2, ',', '.') . '</span>
                                          </div>';

                                $html .= '<div style="display:flex; justify-content:space-between; align-items:center; margin-top:10px;">
                                            <span style="font-size:1.1rem; font-weight:bold;">💰 Lucro Líquido Estimado:</span>
                                            <div style="text-align:right;">
                                                <div style="font-size:1.25rem; font-weight:900; color:' . $corLucro . ';">R$ ' . number_format($lucroLiquido, 2, ',', '.') . '</div>
                                                <div style="font-size:0.85rem; font-weight:bold; color:' . $corLucro . ';">Margem: ' . number_format($margemLiquida, 1, ',', '.') . '%</div>
                                            </div>
                                          </div>';

                                $html .= '</div>';
                                return new HtmlString($html);
                            })
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Ações do Lojista')
                    ->schema([
                        Forms\Components\Actions::make([
                            Forms\Components\Actions\Action::make('ver_etiqueta')
                                ->label('Ver Etiqueta')
                                ->icon('heroicon-o-printer')
                                ->color('success')
                                ->url(fn(Order $record) => route('orders.print-label', $record))
                                ->openUrlInNewTab()
                                ->visible(fn(Order $record) => $record->paid_at !== null && in_array($record->order_processing_status, ['awaiting_label', 'preparing'])),

                            Forms\Components\Actions\Action::make('enviar_nfe_form')
                                ->label('Enviar NF-e')
                                ->icon('heroicon-o-document-arrow-up')
                                ->color('warning')
                                ->visible(fn(Order $record) =>
                                    strtolower($record->source ?? '') === 'shopee'
                                    && empty($record->invoice_access_key)
                                )
                                ->form([
                                    \Filament\Forms\Components\FileUpload::make('invoice_xml')
                                        ->label('XML da NF-e')
                                        ->acceptedFileTypes(['application/xml', 'text/xml'])
                                        ->directory('invoices/xml'),

                                    \Filament\Forms\Components\TextInput::make('invoice_access_key')
                                        ->label('Chave de Acesso da NF-e')
                                        ->maxLength(44),

                                    \Filament\Forms\Components\FileUpload::make('invoice_pdf')
                                        ->label('DANFE (PDF) - Opcional')
                                        ->acceptedFileTypes(['application/pdf'])
                                        ->directory('invoices/danfe'),
                                ])
                                ->action(function (Order $record, array $data) {
                                    $accessKey = $data['invoice_access_key'] ?? null;
                                    $invoiceNumber = null;
                                    $invoiceSeries = null;

                                    if (!empty($data['invoice_xml'])) {
                                        try {
                                            $xmlPath = storage_path('app/public/' . $data['invoice_xml']);
                                            if (file_exists($xmlPath)) {
                                                $xml = simplexml_load_string(file_get_contents($xmlPath));
                                                if ($xml !== false) {
                                                    $namespaces = $xml->getNamespaces(true);
                                                    $ns = $namespaces[''] ?? $namespaces['nfe'] ?? null;
                                                    if ($ns) {
                                                        $xml->registerXPathNamespace('nfe', $ns);
                                                        $ide = $xml->xpath('//nfe:ide');
                                                        if (!empty($ide)) {
                                                            $invoiceNumber = (string) ($ide[0]->nNF ?? '');
                                                            $invoiceSeries = (string) ($ide[0]->serie ?? '');
                                                        }
                                                        $infNFe = $xml->xpath('//nfe:infNFe');
                                                        if (!empty($infNFe) && empty($accessKey)) {
                                                            $accessKey = preg_replace('/^NFe/', '', (string) ($infNFe[0]['Id'] ?? ''));
                                                        }
                                                    }
                                                }
                                            }
                                        } catch (\Exception $e) {
                                            // Silently continue
                                        }
                                    }

                                    if (empty($accessKey)) {
                                        \Filament\Notifications\Notification::make()->title('Chave de Acesso Obrigatoria')->danger()->send();
                                        return;
                                    }

                                    $accessKey = preg_replace('/\s+/', '', $accessKey);
                                    $updateData = ['invoice_access_key' => $accessKey, 'invoice_issued_at' => now()];
                                    if ($invoiceNumber) $updateData['invoice_number'] = $invoiceNumber;
                                    if ($invoiceSeries) $updateData['invoice_series'] = $invoiceSeries;
                                    if (!empty($data['invoice_xml'])) $updateData['invoice_xml_url'] = $data['invoice_xml'];
                                    if (!empty($data['invoice_pdf'])) $updateData['invoice_url'] = $data['invoice_pdf'];
                                    $record->update($updateData);

                                    $record->events()->create([
                                        'event_type' => 'nfe_enviada',
                                        'description' => "NF-e enviada. Chave: {$accessKey}",
                                        'user_id' => auth()->id(),
                                    ]);

                                    if (strtolower($record->source) === 'shopee' && in_array($record->order_processing_status, ['pending', 'awaiting_label', 'payment_pending'])) {
                                        FetchShippingLabelJob::dispatch($record->id, 'nfe_upload');
                                    }

                                    \Filament\Notifications\Notification::make()->title('NF-e Salva com Sucesso')->success()->send();
                                }),

                            Forms\Components\Actions\Action::make('informar_devolucao')
                                ->label('Informar Devolução')
                                ->icon('heroicon-o-arrow-path')
                                ->color('danger')
                                ->visible(fn(Order $record) => in_array($record->order_processing_status, ['shipped', 'delivered']) && !$record->return_code)
                                ->form([
                                    \Filament\Forms\Components\TextInput::make('return_code')
                                        ->label('Código de Logística Reversa')
                                        ->required()
                                ])
                                ->action(function (Order $record, array $data) {
                                    $record->update([
                                        'return_code' => $data['return_code'],
                                        'return_status' => 'requested',
                                    ]);

                                    $record->events()->create([
                                        'event_type' => 'devolucao_solicitada',
                                        'description' => "Devolução solicitada. Código reverso: {$data['return_code']}",
                                        'user_id' => auth()->id()
                                    ]);

                                    \Filament\Notifications\Notification::make()->title('Devolução Registrada')->success()->send();
                                }),
                        ]),
                    ])
                    ->visible(fn(Order $record) => $record->paid_at !== null),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('loja')
                    ->label('Loja')
                    ->getStateUsing(function (Order $record) {
                        $firstItem = $record->items->first();
                        if ($firstItem && $firstItem->clientProduct && $firstItem->clientProduct->marketplaceAccount) {
                            return $firstItem->clientProduct->marketplaceAccount->account_name ?? 'Nome Indisponível';
                        }
                        return $record->source === 'Venda Manual' ? 'Venda Manual' : 'N/A';
                    })
                    ->html()
                    ->formatStateUsing(fn (string $state) => '<span style="font-size:0.78rem; opacity:0.7;">' . e($state) . '</span>')
                    ->width('200px')
                    ->size('lg')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('items.clientProduct.marketplaceAccount', function ($q) use ($search) {
                            $q->where('account_name', 'like', "%{$search}%");
                        });
                    }),
                Tables\Columns\TextColumn::make('external_order_id')
                    ->label('ID Pedido / Produto')
                    ->searchable()
                    ->sortable()
                    ->html()
                    ->formatStateUsing(function (string $state, Order $record) {
                        $item = $record->items->first();
                        $sku = $item?->variation_sku ?? $item?->sku ?? 'N/A';
                        $productName = $item?->name ?? 'Produto Indisponível';

                        // Resolve product image: ProductMedia → order_item.product_image → ui-avatars
                        $mediaUrl = null;
                        if ($item && $item->product_id) {
                            $media = \App\Models\ProductMedia::where('product_id', $item->product_id)->first();
                            if ($media) {
                                $mediaUrl = $media->url;
                            }
                        }
                        // Fallback: imagem salva no order_item (vinda do marketplace ou legado)
                        if (!$mediaUrl && $item && !empty($item->product_image)) {
                            $mediaUrl = $item->product_image;
                        }

                        // Fallback image if relations aren't populated yet
                        if ($mediaUrl && !str_starts_with($mediaUrl, 'http')) {
                            $mediaUrl = asset($mediaUrl);
                        }
                        $imageUrl = $mediaUrl ?? 'https://ui-avatars.com/api/?name=' . urlencode(substr($sku, 0, 2)) . '&background=1e293b&color=94a3b8&size=80';

                        return new \Illuminate\Support\HtmlString('
                            <div style="display:flex; align-items:center; gap:10px;">
                                <img src="' . $imageUrl . '" style="width:80px; height:80px; border-radius:12px; border:1px solid color-mix(in srgb, currentColor 10%, transparent); object-fit:contain; background:white; padding:3px;">
                                <div style="display:flex; flex-direction:column; line-height:1.4; max-width: 180px;">
                                    <span style="font-weight:600; font-size:0.82rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="' . htmlspecialchars($productName) . '">' . htmlspecialchars($productName) . '</span>
                                    <span style="font-size:0.75rem; opacity:0.6;">#' . $state . '</span>
                                    <span style="font-size:0.7rem; opacity:0.45; font-family: monospace;">SKU: ' . $sku . '</span>
                                </div>
                            </div>
                        ');
                    }),
                Tables\Columns\TextColumn::make('source')
                    ->label('Canal')
                    ->badge()
                    ->size('lg')
                    ->formatStateUsing(fn(?string $state): string => match ($state) {
                        'mercadolivre' => 'Mercado Livre',
                        'shopee'       => 'Shopee',
                        'amazon'       => 'Amazon',
                        'Venda Manual' => 'Venda Manual',
                        default        => $state ?? 'N/A',
                    })
                    ->color(fn(?string $state): string => match ($state) {
                        'mercadolivre' => 'warning',
                        'shopee'       => 'danger',
                        'amazon'       => 'info',
                        default        => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('total')
                    ->label('Total')
                    ->money('BRL')
                    ->sortable(),
                Tables\Columns\TextColumn::make('items_count')
                    ->label('Itens')
                    ->getStateUsing(fn (Order $record) => $record->items->sum('quantity'))
                    ->badge()
                    ->color('info'),
                Tables\Columns\TextColumn::make('order_processing_status')
                    ->label('Status do Envio')
                    ->badge()
                    ->formatStateUsing(fn(?string $state): string => match ($state) {
                        'pending'           => 'Pendente',
                        'awaiting_label'    => 'Aguardando Etiqueta',
                        'awaiting_dispatch' => 'Pronto p/ Despacho',
                        'separated'         => 'Separado',
                        'processing'        => 'Processando',
                        'preparing'         => 'Em Preparação',
                        'packed'            => 'Embalado',
                        'shipped'           => 'Enviado',
                        'delivered'         => 'Entregue',
                        'returned'          => 'Devolvido',
                        'payment_pending'   => 'Aguardando Pagamento',
                        default             => $state ? ucfirst($state) : 'Novo',
                    })
                    ->color(fn(?string $state): string => match ($state) {
                        'pending'           => 'gray',
                        'awaiting_label'    => 'warning',
                        'awaiting_dispatch' => 'info',
                        'separated'         => 'primary',
                        'processing'        => 'info',
                        'preparing'         => 'info',
                        'packed'            => 'primary',
                        'shipped'           => 'success',
                        'delivered'         => 'success',
                        'returned'          => 'danger',
                        'payment_pending'   => 'danger',
                        default             => 'gray',
                    })
                    ->sortable(),

                // MES-046-A: motivo padronizado da falha de etiqueta
                Tables\Columns\TextColumn::make('label_status_reason')
                    ->label('Motivo Etiqueta')
                    ->formatStateUsing(fn(?string $state): string => match ($state) {
                        'missing_marketplace_account' => 'Sem conta marketplace',
                        'awaiting_marketplace'        => 'Aguardando marketplace',
                        'payment_pending'             => 'Pagamento pendente',
                        'invoice_required'            => 'NF-e necessaria',
                        'token_error'                 => 'Token expirado',
                        'fiscal_data_missing'         => 'Dados fiscais incompletos',
                        default => $state ? ucfirst(str_replace('_', ' ', $state)) : '-',
                    })
                    ->badge()
                    ->color(fn(?string $state): string => match ($state) {
                        'missing_marketplace_account' => 'gray',
                        'awaiting_marketplace'        => 'warning',
                        'payment_pending'             => 'danger',
                        'invoice_required'            => 'info',
                        'token_error'                 => 'danger',
                        'fiscal_data_missing'         => 'warning',
                        default => 'gray',
                    })
                    ->visible(fn(?string $state): bool => !empty($state))
                    ->toggleable(isToggledHiddenByDefault: true),

                // MUL-237: data real da venda no marketplace com fallback em created_at
                Tables\Columns\TextColumn::make('marketplace_created_at')
                    ->label('Data Venda')
                    ->state(fn ($record) => $record->marketplace_created_at ?? $record->created_at)
                    // MUL-299: com ANO — pedido de 2025 aparecia como "18/12" e parecia deste ano
                    ->dateTime('d/m/Y H:i')
                    ->description(fn ($record) => $record->marketplace_created_at ? null : 'sem data de venda — importacao')
                    ->sortable(query: fn ($query, string $direction) => $query->orderByRaw('COALESCE(marketplace_created_at, created_at) ' . ($direction === 'asc' ? 'asc' : 'desc'))),

                // MUL-299: a data de importacao existe, mas escondida e com nome honesto
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Importado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),


            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status do Pagamento')
                    ->options(\App\Enums\OrderStatus::class),

                Tables\Filters\SelectFilter::make('order_processing_status')
                    ->label('Status do Envio')
                    ->options([
                        'pending'           => 'Pendente',
                        'awaiting_label'    => 'Aguardando Etiqueta',
                        'awaiting_dispatch' => 'Pronto p/ Despacho',
                        'separated'         => 'Separado',
                        'preparing'         => 'Em Preparação',
                        'packed'            => 'Embalado',
                        'shipped'           => 'Enviado',
                        'delivered'         => 'Entregue',
                        'returned'          => 'Devolvido',
                        'payment_pending'   => 'Aguardando Pagamento',
                    ]),
            ])
            ->headerActions([])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('')
                    ->iconButton()
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->tooltip('Ver detalhes')
                    ->modalWidth('5xl')
                    ->form(fn(Forms\Form $form) => static::form($form)),

                Tables\Actions\Action::make('pagar_shipay')
                    ->label(fn(Order $record) => $record->paid_at ? 'Pago' : 'Pagar Pedido')
                    ->icon(fn(Order $record) => $record->paid_at ? 'heroicon-o-check-circle' : 'heroicon-o-currency-dollar')
                    ->color(fn(Order $record) => $record->paid_at ? 'success' : 'primary')
                    ->button()->size('lg')
                    ->extraAttributes(['style' => 'min-width: 160px; justify-content: center;'])
                    ->disabled(fn(Order $record) => $record->paid_at !== null)
                    ->modalHeading('Finalizar Pagamento')
                    ->modalDescription(function (Order $record) {
                        $simulator = app(\App\Services\ShippingLabelService::class);
                        $statusDesc = $simulator->checkLabelStatus($record);
                        if (!$statusDesc['ready'] && str_contains($statusDesc['reason'], 'Invoice')) {
                            return 'O marketplace exige a Nota Fiscal (XML) antes de liberar a etiqueta. Envie o XML para desbloquear o pagamento.';
                        }
                        return 'Deseja gerar o PIX Shipay para liberar o pedido no Galpão Fornecedor?';
                    })
                    ->form(function (Order $record) {
                        $simulator = app(\App\Services\ShippingLabelService::class);
                        $statusDesc = $simulator->checkLabelStatus($record);
                        if (!$statusDesc['ready'] && str_contains($statusDesc['reason'], 'Invoice')) {
                            return [
                                \Filament\Forms\Components\FileUpload::make('invoice_xml')
                                    ->label('Upload XML da NF-e')
                                    ->acceptedFileTypes(['application/xml', 'text/xml'])
                                    ->directory('invoices')
                                    ->required(),
                            ];
                        }
                        return [];
                    })
                    ->modalSubmitActionLabel(function (Order $record) {
                        $simulator = app(\App\Services\ShippingLabelService::class);
                        $statusDesc = $simulator->checkLabelStatus($record);
                        if (!$statusDesc['ready'] && str_contains($statusDesc['reason'], 'Invoice')) {
                            return 'Enviar NF-e e Pagar';
                        }
                        return 'Gerar PIX Shipay';
                    })
                    ->action(function (Order $record, array $data) {
                        try {
                            if (isset($data['invoice_xml'])) {
                                $record->update(['invoice_xml_url' => $data['invoice_xml']]);
                                $record->events()->create([
                                    'event_type'  => 'nf_validada',
                                    'description' => 'Nota fiscal anexada (via painel do lojista)',
                                    'user_id'     => auth()->id(),
                                ]);
                            }

                            $supplier = $record->supplier;
                            if (! $supplier) {
                                throw new \Exception('Fornecedor não encontrado para este pedido.');
                            }

                            $paymentService = app(\App\Services\Financial\OrderPaymentService::class);
                            $result = $paymentService->payOrder($record, $supplier);

                            if ($result['status'] === 'paid') {
                                // 100% pago com saldo da carteira
                                \Filament\Notifications\Notification::make()
                                    ->title('Pedido Pago com Saldo!')
                                    ->body('Saldo da carteira cobriu 100% do pedido. Liberado para o Fornecedor.')
                                    ->success()
                                    ->send();
                                return;
                            }

                            // PIX gerado
                            $pixTx      = $result['pix_transaction'];
                            // PagarMe: qr_code = texto copia-e-cola, qr_code_text = URL da imagem QR
                            $pixCopyText = $pixTx->qr_code      ?? '';
                            $pixImgUrl   = $pixTx->qr_code_text ?? '';
                            $pixNeeded   = number_format((float) ($result['pix_needed'] ?? 0), 2, ',', '.');
                            $walletUsed  = (float) ($result['wallet_used'] ?? 0);

                            $walletMsg = $walletUsed > 0
                                ? '<p style="color:#16a34a;font-size:12px;margin-bottom:8px;">✓ R$ ' . number_format($walletUsed, 2, ',', '.') . ' abatidos da sua carteira.</p>'
                                : '';

                            // Imagem QR: usa qr_code_text (URL da imagem do PagarMe/Shipay)
                            $hasImg  = $pixImgUrl && $pixImgUrl !== $pixCopyText;
                            $imgHtml = $hasImg
                                ? '<img src="' . e($pixImgUrl) . '" style="width:220px;height:220px;display:block;margin:0 auto 12px;" alt="QR PIX">'
                                : '';

                            // Copia e Cola: usa qr_code (texto PIX bruto)
                            $codeHtml = $pixCopyText
                                ? '<div style="background:#f1f5f9;border-radius:6px;padding:8px;margin-top:8px;">'
                                    . '<p style="font-size:10px;color:#64748b;margin-bottom:4px;">PIX Copia e Cola:</p>'
                                    . '<p style="font-size:10px;word-break:break-all;font-family:monospace;">' . e($pixCopyText) . '</p>'
                                . '</div>'
                                : '';

                            $bodyHtml = '<div style="text-align:center;">'
                                . $walletMsg
                                . '<p style="font-weight:600;margin-bottom:8px;">Valor: R$ ' . $pixNeeded . '</p>'
                                . $imgHtml
                                . $codeHtml
                                . '</div>';

                            \Filament\Notifications\Notification::make()
                                ->title('PIX Gerado — Escaneie ou copie o código')
                                ->body(new \Illuminate\Support\HtmlString($bodyHtml))
                                ->success()
                                ->persistent()
                                ->send();

                        } catch (\Exception $e) {
                            \Filament\Notifications\Notification::make()
                                ->title('Erro ao gerar pagamento')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('enviar_nfe')
                    ->label('Enviar NF-e')
                    ->icon('heroicon-o-document-arrow-up')
                    ->color('warning')
                    ->button()
                    ->size('lg')
                    ->visible(fn(Order $record) =>
                        strtolower($record->source ?? '') === 'shopee'
                        && empty($record->invoice_access_key)
                    )
                    ->modalHeading('Enviar Nota Fiscal Eletronica (NF-e)')
                    ->modalDescription('Para pedidos Shopee com logistica xd_drop_off, a NF-e deve ser enviada antes da liberacao da etiqueta. Faca o upload do XML ou informe a chave de acesso manualmente.')
                    ->form([
                        Forms\Components\FileUpload::make('invoice_xml')
                            ->label('XML da NF-e')
                            ->acceptedFileTypes(['application/xml', 'text/xml'])
                            ->directory('invoices/xml')
                            ->helperText('Faca upload do arquivo XML da nota fiscal emitido pelo seu sistema ou Bling.'),

                        Forms\Components\TextInput::make('invoice_access_key')
                            ->label('Chave de Acesso da NF-e')
                            ->placeholder('0000 0000 0000 0000 0000 0000 0000 0000 0000 0000 0000')
                            ->maxLength(44)
                            ->helperText('44 digitos da chave de acesso. Se enviar o XML, sera preenchido automaticamente.'),

                        Forms\Components\FileUpload::make('invoice_pdf')
                            ->label('DANFE (PDF) - Opcional')
                            ->acceptedFileTypes(['application/pdf'])
                            ->directory('invoices/danfe')
                            ->helperText('Upload opcional do PDF da DANFE.'),
                    ])
                    ->modalSubmitActionLabel('Salvar NF-e')
                    ->action(function (Order $record, array $data) {
                        $invoiceNumber = null;
                        $invoiceSeries = null;
                        $accessKey = $data['invoice_access_key'] ?? null;

                        // Tenta parsear o XML para extrair dados da NF-e
                        if (!empty($data['invoice_xml'])) {
                            try {
                                $xmlPath = storage_path('app/public/' . $data['invoice_xml']);
                                if (file_exists($xmlPath)) {
                                    $xmlContent = file_get_contents($xmlPath);
                                    $xml = simplexml_load_string($xmlContent);

                                    if ($xml !== false) {
                                        // Registra namespaces NFe
                                        $namespaces = $xml->getNamespaces(true);
                                        $ns = $namespaces[''] ?? $namespaces['nfe'] ?? null;

                                        if ($ns) {
                                            $xml->registerXPathNamespace('nfe', $ns);
                                            // Tenta extrair de NFe/infNFe/ide
                                            $ide = $xml->xpath('//nfe:ide');
                                            if (!empty($ide)) {
                                                $invoiceNumber = (string) ($ide[0]->nNF ?? '');
                                                $invoiceSeries = (string) ($ide[0]->serie ?? '');
                                            }
                                            // Tenta extrair chave de acesso de infNFe@Id
                                            $infNFe = $xml->xpath('//nfe:infNFe');
                                            if (!empty($infNFe) && empty($accessKey)) {
                                                $nfeId = (string) ($infNFe[0]['Id'] ?? '');
                                                // Remove prefixo "NFe" se presente
                                                $accessKey = preg_replace('/^NFe/', '', $nfeId);
                                            }
                                        } else {
                                            // XML sem namespace
                                            $ide = $xml->xpath('//ide');
                                            if (!empty($ide)) {
                                                $invoiceNumber = (string) ($ide[0]->nNF ?? '');
                                                $invoiceSeries = (string) ($ide[0]->serie ?? '');
                                            }
                                            $infNFe = $xml->xpath('//infNFe');
                                            if (!empty($infNFe) && empty($accessKey)) {
                                                $nfeId = (string) ($infNFe[0]['Id'] ?? '');
                                                $accessKey = preg_replace('/^NFe/', '', $nfeId);
                                            }
                                        }
                                    }
                                }
                            } catch (\Exception $e) {
                                Log::warning("[NF-e Upload] Erro ao parsear XML", [
                                    'order_id' => $record->id,
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }

                        // Validacao: precisa de pelo menos a chave de acesso
                        if (empty($accessKey)) {
                            \Filament\Notifications\Notification::make()
                                ->title('Chave de Acesso Obrigatoria')
                                ->body('Informe a chave de acesso da NF-e manualmente ou envie um XML valido.')
                                ->danger()
                                ->send();
                            return;
                        }

                        // Sanitiza a chave (remove espacos)
                        $accessKey = preg_replace('/\s+/', '', $accessKey);

                        // Atualiza o pedido
                        $updateData = [
                            'invoice_access_key' => $accessKey,
                            'invoice_issued_at' => now(),
                        ];

                        if ($invoiceNumber) {
                            $updateData['invoice_number'] = $invoiceNumber;
                        }
                        if ($invoiceSeries) {
                            $updateData['invoice_series'] = $invoiceSeries;
                        }
                        if (!empty($data['invoice_xml'])) {
                            $updateData['invoice_xml_url'] = $data['invoice_xml'];
                        }
                        if (!empty($data['invoice_pdf'])) {
                            $updateData['invoice_url'] = $data['invoice_pdf'];
                        }

                        $record->update($updateData);

                        // Registra evento
                        $record->events()->create([
                            'event_type' => 'nfe_enviada',
                            'description' => "NF-e enviada pelo lojista. Chave: {$accessKey}" .
                                ($invoiceNumber ? " | Numero: {$invoiceNumber}" : '') .
                                ($invoiceSeries ? " | Serie: {$invoiceSeries}" : ''),
                            'user_id' => auth()->id(),
                        ]);

                        // Se o pedido e Shopee e esta aguardando NF-e para etiqueta, dispara fetch
                        if (
                            strtolower($record->source) === 'shopee'
                            && in_array($record->order_processing_status, ['pending', 'awaiting_label', 'payment_pending'])
                        ) {
                            FetchShippingLabelJob::dispatch($record->id, 'nfe_upload');
                            Log::info("[NF-e Upload] FetchShippingLabelJob disparado apos upload NF-e", [
                                'order_id' => $record->id,
                            ]);
                        }

                        \Filament\Notifications\Notification::make()
                            ->title('NF-e Salva com Sucesso')
                            ->body("Chave de acesso registrada: {$accessKey}" .
                                ($invoiceNumber ? " | NF #{$invoiceNumber}" : ''))
                            ->success()
                            ->send();
                    }),

            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            // MUL-299: ordena pela data da VENDA, caindo em created_at so quando nao existe
            ->defaultSort(fn ($query) => $query->orderByRaw('COALESCE(marketplace_created_at, created_at) DESC'));
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
        ];
    }
}
