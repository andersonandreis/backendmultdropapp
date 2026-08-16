<?php

namespace App\Filament\App\Resources\OrderResource\Pages;

use App\Filament\App\Resources\OrderResource;
use App\Models\ClientProduct;
use App\Models\ClientSupplierBalance;
use App\Models\MissedOrderAlert;
use App\Models\Tutorial;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Wizard;
use Filament\Forms\Components\Wizard\Step;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\On;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    public array $searchPrefill = [];

    #[On('open-manual-wizard')]
    public function openManualWizard(): void
    {
        $this->mountAction('registrar_venda_manual');
    }

    protected function getHeaderWidgets(): array
    {
        return [
            \App\Filament\App\Widgets\FirstSaleGuideWidget::class,
            \App\Filament\App\Widgets\MissedOrdersBannerWidget::class,
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers de plano / tenant
    // -------------------------------------------------------------------------

    protected function isStartPlan(): bool
    {
        $client = auth()->user()?->client;
        if (! $client) {
            return true;
        }

        $subscription = $client->subscriptions()
            ->whereIn('status', ['active', 'trialing'])
            ->with('plan')
            ->latest()
            ->first();

        if (! $subscription || ! $subscription->plan) {
            return true;
        }

        return (int) $subscription->plan->max_skus <= 30;
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

    protected function getClientSupplierId(): ?int
    {
        $client = auth()->user()?->client;
        if (! $client) {
            return null;
        }

        $balance = ClientSupplierBalance::where('client_id', $client->id)->first();
        if ($balance) {
            return $balance->supplier_id;
        }

        $suppliers = $client->effectiveSuppliers;
        return $suppliers->first()?->id;
    }

    protected function getApiToken(): string
    {
        $user = auth()->user();
        $user->tokens()->where('name', 'filament_internal')->delete();
        return $user->createToken('filament_internal')->plainTextToken;
    }

    // -------------------------------------------------------------------------
    // Pré-preenchimento via ?fromAlert={id}
    // -------------------------------------------------------------------------

    protected function getAlertDefaults(): array
    {
        $alertId = request()->query('fromAlert');
        if (! $alertId) {
            return [];
        }

        $client = auth()->user()?->client;
        if (! $client) {
            return [];
        }

        $alert = MissedOrderAlert::forClient($client->id)
            ->where('id', $alertId)
            ->first();

        if (! $alert) {
            return [];
        }

        $marketplace = match (strtolower($alert->marketplace ?? '')) {
            'shopee'        => 'shopee',
            'mercado_livre',
            'mercadolivre'  => 'mercado_livre',
            'magalu',
            'magazine_luiza' => 'magalu',
            'shopify'       => 'shopify',
            default         => 'outro',
        };

        return [
            'buyer_name'  => $alert->buyer_name ?? '',
            'buyer_doc'   => $alert->buyer_doc ?? '',
            'marketplace' => $marketplace,
            '_alert_id'   => $alert->id,
        ];
    }

    // -------------------------------------------------------------------------
    // markAlertConverted
    // -------------------------------------------------------------------------

    protected function markAlertConverted(int $alertId, int $orderId): void
    {
        $client = auth()->user()?->client;
        if (! $client) {
            return;
        }

        MissedOrderAlert::forClient($client->id)
            ->where('id', $alertId)
            ->whereNull('dismissed_at')
            ->whereNull('converted_to_order_id')
            ->update([
                'dismissed_at'         => now(),
                'dismiss_reason'       => 'converted',
                'converted_to_order_id' => $orderId,
            ]);
    }

    // -------------------------------------------------------------------------
    // Busca marketplace — "Pedido nao caiu?"
    // -------------------------------------------------------------------------

    protected function searchMarketplaceOrder(string $marketplace, string $orderNumber): array
    {
        $token   = $this->getApiToken();
        $baseUrl = config('app.url');

        try {
            $response = Http::withToken($token)
                ->post("{$baseUrl}/api/v1/orders/search-marketplace", [
                    'marketplace'  => $marketplace,
                    'order_number' => $orderNumber,
                ]);
        } catch (\Exception $e) {
            return ['found' => false, 'error' => 'Erro de conexao: ' . $e->getMessage(), 'fallback' => 'manual_order'];
        }

        if ($response->status() === 404) {
            return $response->json() ?? ['found' => false, 'error' => 'Pedido nao encontrado', 'fallback' => 'manual_order'];
        }

        if ($response->successful()) {
            return $response->json();
        }

        return ['found' => false, 'error' => 'Erro inesperado: ' . $response->status(), 'fallback' => 'manual_order'];
    }

    // -------------------------------------------------------------------------
    // Header actions
    // -------------------------------------------------------------------------

    protected function getHeaderActions(): array
    {
        $isStart  = $this->isStartPlan();
        $planName = $this->getPlanName();
        $defaults = $this->getAlertDefaults();

        $actions = [];

        // ---------------------------------------------------------------------
        // ACTION: "Pedido nao caiu?" — buscar no marketplace
        // ---------------------------------------------------------------------

        if ($isStart) {
            $actions[] = Action::make('buscar_pedido_marketplace')
                ->label('Pedido nao caiu?')
                ->icon('heroicon-o-lock-closed')
                ->color('gray')
                ->outlined()
                ->tooltip("Disponivel no plano Pro. Seu plano atual: {$planName}.")
                ->modalWidth('md')
                ->modalHeading('Funcionalidade Exclusiva do Plano Pro')
                ->modalDescription('')
                ->modalContent(new HtmlString(
                    '<div class="text-center py-4">'
                    . '<div class="text-4xl mb-4">&#128274;</div>'
                    . '<h3 class="text-lg font-semibold mb-2">Buscar Pedido no Marketplace</h3>'
                    . '<p class="text-gray-500 dark:text-gray-400 mb-4 text-sm">'
                    . 'Busque pedidos diretamente no marketplace pelo numero para importa-los automaticamente. '
                    . 'Disponivel a partir do plano <strong>Pro</strong>.'
                    . '</p>'
                    . '<p class="text-sm text-gray-400 dark:text-gray-500">Seu plano atual: <strong>' . e($planName) . '</strong></p>'
                    . '</div>'
                ))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Fechar');
        } else {
            $actions[] = Action::make('buscar_pedido_marketplace')
                ->label('Pedido nao caiu?')
                ->icon('heroicon-o-magnifying-glass')
                ->color('gray')
                ->outlined()
                ->modalWidth('2xl')
                ->modalHeading('Buscar Pedido no Marketplace')
                ->modalDescription('Informe o marketplace e o numero do pedido para localiza-lo.')
                ->form([
                    Select::make('marketplace')
                        ->label('Marketplace')
                        ->required()
                        ->options([
                            'shopee'       => 'Shopee',
                            'mercadolivre' => 'Mercado Livre',
                            'bling'        => 'Bling',
                        ])
                        ->placeholder('Selecione o marketplace'),

                    TextInput::make('order_number')
                        ->label('Numero do Pedido')
                        ->required()
                        ->maxLength(100)
                        ->placeholder('Ex: 2412345678901234')
                        ->helperText('Digite o numero exato como aparece no marketplace'),
                ])
                ->modalSubmitActionLabel('Buscar')
                ->action(function (array $data) {
                    $marketplace = $data['marketplace'];
                    $orderNumber = trim($data['order_number']);

                    $result = $this->searchMarketplaceOrder($marketplace, $orderNumber);

                    $marketplaceLabel = match ($marketplace) {
                        'shopee'       => 'Shopee',
                        'mercadolivre' => 'Mercado Livre',
                        'bling'        => 'Bling',
                        default        => ucfirst($marketplace),
                    };

                    if (! ($result['found'] ?? false)) {
                        $errorMsg = $result['error'] ?? 'Pedido nao localizado.';

                        Notification::make()
                            ->title("Pedido nao encontrado em {$marketplaceLabel}")
                            ->body($errorMsg . ' Utilize o registro manual para criar o pedido.')
                            ->warning()
                            ->persistent()
                            ->send();

                        $this->unmountAction(false);
                        $this->dispatch('open-manual-wizard');
                    }

                    // 200 found — montar notificacao e abrir wizard manual pre-preenchido
                    $buyerName = $result['buyer_name'] ?? '';
                    $buyerDoc  = $result['buyer_doc'] ?? '';
                    $orderId   = e($result['order_id'] ?? '-');
                    $amountFmt = 'R$ ' . number_format(($result['amount_cents'] ?? 0) / 100, 2, ',', '.');

                    Notification::make()
                        ->title("Pedido encontrado em {$marketplaceLabel}!")
                        ->body(
                            "#{$orderId} — " . e($buyerName) . " — {$amountFmt}. "
                            . 'Importacao automatica em desenvolvimento. Abrindo registro manual pre-preenchido.'
                        )
                        ->success()
                        ->persistent()
                        ->send();

                    // Armazena prefill, desmonta modal atual e abre wizard manual
                    $this->searchPrefill = [
                        'buyer_name'  => $buyerName,
                        'buyer_doc'   => $buyerDoc,
                        'marketplace' => $marketplace === 'mercadolivre' ? 'mercado_livre' : $marketplace,
                    ];

                    $this->unmountAction(false);
                    $this->dispatch('open-manual-wizard');
                });
        }

        // ---------------------------------------------------------------------
        // ACTION: Registrar Venda Manualmente
        // ---------------------------------------------------------------------

        if ($isStart) {
            // Upsell modal para plano Start
            $actions[] = Action::make('registrar_venda_manual')
                ->label('Registrar Venda Manualmente')
                ->icon('heroicon-o-lock-closed')
                ->color('gray')
                ->tooltip("Disponivel no plano Pro. Seu plano atual: {$planName}.")
                ->modalWidth('md')
                ->modalHeading('Funcionalidade Exclusiva do Plano Pro')
                ->modalDescription('')
                ->modalContent(new HtmlString(
                    '<div class="text-center py-4">'
                    . '<div class="text-4xl mb-4">🔒</div>'
                    . '<h3 class="text-lg font-semibold mb-2">Registrar Venda Manualmente</h3>'
                    . '<p class="text-gray-500 dark:text-gray-400 mb-4 text-sm">'
                    . 'Esta funcionalidade permite registrar vendas que nao foram capturadas automaticamente pelo sistema. '
                    . 'Disponivel a partir do plano <strong>Pro</strong>.'
                    . '</p>'
                    . '<p class="text-sm text-gray-400 dark:text-gray-500">Seu plano atual: <strong>' . e($planName) . '</strong></p>'
                    . '</div>'
                ))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Fechar');
        } else {
            // Wizard simplificado — submit unico
            $actions[] = Action::make('registrar_venda_manual')
                ->label('Registrar Venda Manualmente')
                ->icon('heroicon-o-plus-circle')
                ->color('primary')
                ->modalWidth('3xl')
                ->modalHeading('Registrar Venda Manual')
                ->modalDescription('Registre uma venda que nao foi capturada automaticamente pelo sistema.')
                ->fillForm(function () {
                    $defaults = $this->getAlertDefaults();
                    if (! empty($this->searchPrefill)) {
                        $data = array_merge($defaults, $this->searchPrefill);
                        $this->searchPrefill = [];
                        return $data;
                    }
                    return $defaults;
                })
                ->form([
                    Wizard::make([

                        // ------------------------------------------------------
                        // PASSO 1 — Produto
                        // ------------------------------------------------------
                        Step::make('Produto')
                            ->icon('heroicon-o-shopping-bag')
                            ->description('Selecione os produtos vendidos')
                            ->schema([
                                Repeater::make('items')
                                    ->label('Itens da Venda')
                                    ->schema([
                                        Select::make('client_product_id')
                                            ->label('Produto')
                                            ->options(function () {
                                                $client = auth()->user()?->client;
                                                if (! $client) {
                                                    return [];
                                                }

                                                return ClientProduct::where('client_id', $client->id)
                                                    ->where('is_active', true)
                                                    ->with('product:id,name,sku')
                                                    ->get()
                                                    ->mapWithKeys(function ($cp) {
                                                        $label = $cp->custom_title
                                                            ?? $cp->product?->name
                                                            ?? "ClientProduct #{$cp->id}";
                                                        $sku = $cp->custom_sku
                                                            ?? $cp->product?->sku
                                                            ?? '';
                                                        return [$cp->id => "{$label}" . ($sku ? " [{$sku}]" : '')];
                                                    });
                                            })
                                            ->searchable()
                                            ->required()
                                            ->placeholder('Busque pelo nome ou SKU...')
                                            ->columnSpan(2),

                                        TextInput::make('qty')
                                            ->label('Quantidade')
                                            ->numeric()
                                            ->default(1)
                                            ->minValue(1)
                                            ->required()
                                            ->columnSpan(1),
                                    ])
                                    ->columns(3)
                                    ->minItems(1)
                                    ->addActionLabel('Adicionar produto')
                                    ->defaultItems(1),

                                Textarea::make('reason')
                                    ->label('Motivo (opcional)')
                                    ->placeholder('Venda nao capturada pelo sistema')
                                    ->default('Venda nao capturada pelo sistema')
                                    ->maxLength(255)
                                    ->rows(2),
                            ]),

                        // ------------------------------------------------------
                        // PASSO 2 — Comprador + Endereco
                        // ------------------------------------------------------
                        Step::make('Comprador')
                            ->icon('heroicon-o-user')
                            ->description('Dados do comprador e entrega')
                            ->schema([
                                Grid::make(2)->schema([
                                    TextInput::make('buyer_name')
                                        ->label('Nome do Comprador')
                                        ->required()
                                        ->maxLength(255)
                                        ->placeholder('Ex: Joao da Silva'),

                                    TextInput::make('buyer_doc')
                                        ->label('CPF / CNPJ')
                                        ->required()
                                        ->maxLength(18)
                                        ->placeholder('000.000.000-00'),
                                ]),

                                Select::make('marketplace')
                                    ->label('Marketplace de Origem')
                                    ->required()
                                    ->options([
                                        'shopee'        => 'Shopee',
                                        'mercado_livre' => 'Mercado Livre',
                                        'magalu'        => 'Magalu',
                                        'shopify'       => 'Shopify',
                                        'outro'         => 'Outro',
                                    ])
                                    ->placeholder('Selecione o marketplace'),

                                Grid::make(3)->schema([
                                    TextInput::make('address_street')
                                        ->label('Rua / Logradouro')
                                        ->required()
                                        ->maxLength(255)
                                        ->columnSpan(2),

                                    TextInput::make('address_number')
                                        ->label('Numero')
                                        ->required()
                                        ->maxLength(20)
                                        ->columnSpan(1),
                                ]),

                                Grid::make(2)->schema([
                                    TextInput::make('address_complement')
                                        ->label('Complemento')
                                        ->maxLength(100)
                                        ->placeholder('Apto, bloco, etc.'),

                                    TextInput::make('address_neighborhood')
                                        ->label('Bairro')
                                        ->required()
                                        ->maxLength(100),
                                ]),

                                Grid::make(3)->schema([
                                    TextInput::make('address_city')
                                        ->label('Cidade')
                                        ->required()
                                        ->maxLength(100)
                                        ->columnSpan(1),

                                    TextInput::make('address_state')
                                        ->label('UF')
                                        ->required()
                                        ->maxLength(2)
                                        ->placeholder('RJ')
                                        ->columnSpan(1),

                                    TextInput::make('address_cep')
                                        ->label('CEP')
                                        ->required()
                                        ->maxLength(9)
                                        ->placeholder('00000-000')
                                        ->columnSpan(1),
                                ]),
                            ]),

                        // ------------------------------------------------------
                        // PASSO 3 — Revisao + submit
                        // ------------------------------------------------------
                        Step::make('Revisao')
                            ->icon('heroicon-o-clipboard-document-list')
                            ->description('Confira os dados antes de criar o pedido')
                            ->schema([
                                Placeholder::make('revisao_info')
                                    ->label('')
                                    ->content(new HtmlString(
                                        '<div class="rounded-lg border border-blue-200 bg-blue-50 dark:bg-blue-950 dark:border-blue-700 p-3 text-sm text-blue-800 dark:text-blue-200">'
                                        . '<strong>Atencao:</strong> O custo total sera calculado pelo servidor ao confirmar. '
                                        . 'O pagamento sera processado automaticamente pelo sistema legado via saldo ou PIX. '
                                        . 'A etiqueta de envio sera gerada automaticamente apos a confirmacao.'
                                        . '</div>'
                                    )),

                                Placeholder::make('revisao_dados')
                                    ->label('Dados do Pedido')
                                    ->content(new HtmlString(
                                        '<p class="text-sm text-gray-500 dark:text-gray-400">'
                                        . 'Revise os dados preenchidos nas etapas anteriores. '
                                        . 'Ao clicar em <strong>Criar Pedido</strong>, o pedido sera enviado ao sistema.'
                                        . '</p>'
                                    )),
                            ]),

                    ])->submitAction(new HtmlString(
                        '<button type="submit" class="fi-btn fi-btn-size-md fi-color-primary fi-btn-color-primary px-3 py-2 rounded-lg text-sm font-semibold">'
                        . 'Criar Pedido'
                        . '</button>'
                    )),
                ])
                ->action(function (array $data) {
                    $this->processManualSale($data);
                });
        }


        // ---------------------------------------------------------------
        // ACTION: Buscar Pedido por ID (fetch-by-id)
        // ---------------------------------------------------------------
        $actions[] = Action::make('buscar_pedido_por_id')
            ->label('Importar Pedido por ID')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('warning')
            ->outlined()
            ->modalWidth('lg')
            ->modalHeading('Importar Pedido por ID do Marketplace')
            ->modalDescription('Informe a conta e o ID exato do pedido no marketplace para importa-lo manualmente.')
            ->form(function () {
                $client = auth()->user()?->client;
                $accountOptions = [];
                if ($client) {
                    $accounts = \App\Models\MarketplaceAccount::where('client_id', $client->id)
                        ->whereIn('platform', ['shopee', 'mercadolivre'])
                        ->whereIn('status', ['active', 'imported'])
                        ->orderBy('platform')
                        ->get(['id', 'platform', 'account_name']);
                    foreach ($accounts as $acc) {
                        $platformLabel = match($acc->platform) {
                            'shopee'       => 'Shopee',
                            'mercadolivre' => 'Mercado Livre',
                            default        => ucfirst($acc->platform),
                        };
                        $accountOptions[$acc->id] = "{$platformLabel} — {$acc->account_name}";
                    }
                }
                return [
                    Select::make('account_id')
                        ->label('Conta do Marketplace')
                        ->options($accountOptions)
                        ->required()
                        ->placeholder('Selecione a conta')
                        ->helperText('Apenas contas Shopee e Mercado Livre sao suportadas'),

                    TextInput::make('order_id')
                        ->label('ID do Pedido')
                        ->required()
                        ->maxLength(100)
                        ->placeholder('Ex: 241234567890 (Shopee) ou 3234567890 (ML)')
                        ->helperText('Digite o ID exato como aparece no marketplace'),
                ];
            })
            ->modalSubmitActionLabel('Buscar e Importar')
            ->action(function (array $data) {
                $response = \Illuminate\Support\Facades\Http::withToken($this->getApiToken())
                    ->post(config('app.url') . '/api/v1/orders/fetch-by-id', [
                        'order_id'   => trim($data['order_id']),
                        'account_id' => (int) $data['account_id'],
                    ]);

                $status = $response->status();
                $body   = $response->json();

                if ($status === 201) {
                    Notification::make()
                        ->title('Pedido importado com sucesso!')
                        ->body('O pedido #' . ($body['data']['marketplace_id'] ?? $data['order_id']) . ' foi importado e ja aparece na lista.')
                        ->success()
                        ->persistent()
                        ->send();
                    $this->dispatch('$refresh');
                    return;
                }

                if ($status === 200) {
                    $orderId = $body['data']['order_id'] ?? null;
                    Notification::make()
                        ->title('Pedido ja existe')
                        ->body('Este pedido ja foi importado anteriormente.' . ($orderId ? ' ID interno: #' . $orderId : ''))
                        ->warning()
                        ->send();
                    return;
                }

                $errorMsg = $body['error'] ?? 'Erro desconhecido ao importar pedido.';
                Notification::make()
                    ->title('Erro ao importar pedido')
                    ->body($errorMsg)
                    ->danger()
                    ->persistent()
                    ->send();
            });

        // ---------------------------------------------------------------
        // Tutorials Hub University
        // ---------------------------------------------------------------
        $tutorials = Tutorial::where('target_page', 'orders')
            ->where('is_active', true)
            ->get();

        foreach ($tutorials as $tutorial) {
            $actions[] = Actions\Action::make('tutorial_' . $tutorial->id)
                ->label('Aula: ' . $tutorial->title)
                ->icon('heroicon-o-play-circle')
                ->color('info')
                ->modalHeading($tutorial->title)
                ->modalDescription($tutorial->description)
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Fechar')
                ->modalContent(function () use ($tutorial) {
                    $url = $tutorial->video_url ?? '';
                    return new HtmlString('
                        <div class="aspect-w-16 aspect-h-9">
                            <iframe src="' . $url . '" frameborder="0" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen style="width:100%; height:400px; border-radius:8px;"></iframe>
                        </div>
                    ');
                });
        }

        return $actions;
    }

    // -------------------------------------------------------------------------
    // Submit unico — cria pedido via POST /api/v1/orders/manual
    // -------------------------------------------------------------------------

    protected function processManualSale(array $data): void
    {
        $token      = $this->getApiToken();
        $baseUrl    = config('app.url');
        $supplierId = $this->getClientSupplierId();
        $alertId    = $data['_alert_id'] ?? null;

        // Monta items
        $items = collect($data['items'] ?? [])->map(fn ($item) => [
            'client_product_id' => (int) $item['client_product_id'],
            'qty'               => (int) $item['qty'],
            'reason'            => $data['reason'] ?? 'Venda nao capturada pelo sistema',
        ])->values()->all();

        // Payload completo
        $payload = [
            'supplier_id' => $supplierId,
            'items'       => $items,
            'buyer_name'  => $data['buyer_name'],
            'buyer_doc'   => $data['buyer_doc'],
            'marketplace' => $data['marketplace'],
            'address'     => [
                'street'       => $data['address_street'],
                'number'       => $data['address_number'],
                'complement'   => $data['address_complement'] ?? null,
                'neighborhood' => $data['address_neighborhood'],
                'city'         => $data['address_city'],
                'state'        => strtoupper($data['address_state']),
                'cep'          => $data['address_cep'],
            ],
        ];

        // POST /api/v1/orders/manual
        try {
            $response = Http::withToken($token)
                ->post("{$baseUrl}/api/v1/orders/manual", $payload);
        } catch (\Exception $e) {
            Notification::make()
                ->title('Erro de conexao')
                ->body('Nao foi possivel conectar ao servidor: ' . $e->getMessage())
                ->danger()
                ->send();
            return;
        }

        if ($response->status() === 403) {
            Notification::make()
                ->title('Plano insuficiente')
                ->body('Venda manual nao disponivel no seu plano. Faca upgrade para o Pro.')
                ->danger()
                ->send();
            return;
        }

        if ($response->status() === 422) {
            $msg = $response->json('message') ?? 'Produto sem custo definido pelo fornecedor.';
            Notification::make()
                ->title('Erro de validacao')
                ->body($msg)
                ->danger()
                ->send();
            return;
        }

        if (! $response->successful()) {
            Notification::make()
                ->title('Erro ao criar pedido')
                ->body('Resposta inesperada do servidor: ' . $response->status())
                ->danger()
                ->send();
            return;
        }

        $orderData     = $response->json();
        $orderId       = $orderData['order_id'] ?? null;
        $legacyId      = $orderData['legacy_id'] ?? $orderId;
        $supplierTotal = $orderData['supplier_total'] ?? 0;

        if (! $orderId) {
            Notification::make()
                ->title('Erro inesperado')
                ->body('O servidor nao retornou o ID do pedido criado.')
                ->danger()
                ->send();
            return;
        }

        // Marcar alerta como convertido se veio de ?fromAlert
        if ($alertId) {
            $this->markAlertConverted((int) $alertId, (int) $orderId);
        }

        // Notificacao persistente de sucesso
        Notification::make()
            ->title("Pedido #{$legacyId} criado com sucesso!")
            ->body(
                "Pedido #{$legacyId} criado. "
                . 'Pagamento sera processado pelo sistema. '
                . 'Acompanhe na lista de pedidos.'
            )
            ->success()
            ->persistent()
            ->actions([
                \Filament\Notifications\Actions\Action::make('ver_pedido')
                    ->label('Ver Pedido')
                    ->url("/app/orders/{$orderId}")
                    ->button(),
            ])
            ->send();

        $this->resetTable();
    }
}
