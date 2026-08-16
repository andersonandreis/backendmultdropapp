<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\ClientProductResource\Pages;
use App\Models\ClientProduct;
use App\Jobs\PublishClientProductToMLJob;
use App\Services\AIProductContentService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

class ClientProductResource extends Resource
{
    protected static ?string $model = ClientProduct::class;
    protected static ?string $slug = 'meus-produtos';
    protected static ?string $modelLabel = 'Produto do Catálogo';

    // Ocultar do menu global — acesso apenas via Loja (MarketplaceAccount)
    protected static bool $shouldRegisterNavigation = false;

    // -------------------------------------------------------------------------
    // Autorização — limite de SKUs por plano
    // -------------------------------------------------------------------------

    /**
     * Retorna info do plano do cliente logado para canCreate() e banner upsell.
     */
    public static function planInfo(): array
    {
        $user   = auth()->user();
        $client = $user?->client;

        if (! $client) {
            return ['plan_name' => null, 'max_skus' => 0, 'current' => 0, 'has_sub' => false];
        }

        $sub = \App\Models\Subscription::where('client_id', $client->id)
            ->whereIn('status', ['active', 'trialing'])
            ->with('plan')
            ->latest()
            ->first();

        $maxSkus = (int) ($sub?->plan?->max_skus ?? 0);
        $current = Cache::remember(
            'client_product_count_' . $client->id,
            60,
            fn () => \App\Models\ClientProduct::where('client_id', $client->id)
                ->where('excluido', 0)
                ->count()
        );

        $rawName  = $sub?->plan?->name ?? 'Sem plano';
        $price    = $sub?->plan?->price_monthly ?? 0;
        $planName = $price > 0
            ? $rawName . ' R$' . number_format($price, 2, ',', '.')
            : $rawName;

        return [
            'plan_name' => $planName,
            'max_skus'  => $maxSkus,
            'current'   => $current,
            'has_sub'   => (bool) $sub,
        ];
    }

    /**
     * Bloqueia criação quando:
     *  - user sem client registrado
     *  - client sem assinatura ativa/trialing
     *  - contagem de produtos >= max_skus do plano (max_skus=0 = ilimitado)
     */
    public static function canCreate(): bool
    {
        $user   = auth()->user();
        $client = $user?->client;

        if (! $client) {
            return false;
        }

        $info = self::planInfo();

        if (! $info['has_sub']) {
            return false;
        }

        if ($info['max_skus'] === 0) {
            return true;
        }

        return $info['current'] < $info['max_skus'];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        $storeId = request()->query('store_id');
        if ($storeId) {
            $query->where('marketplace_account_id', $storeId);
        } else {
            $user = auth()->user();
            if ($user && $user->client) {
                $storeIds = \App\Models\MarketplaceAccount::where('client_id', $user->client->id)->pluck('id');
                $query->whereIn('marketplace_account_id', $storeIds);
            }
        }

        return $query->withCount(['orderItems', 'autoListingQueueItems']);
    }

    // -------------------------------------------------------------------------
    // FORM — usado tanto no Create (página) quanto no Edit (slide-over)
    // -------------------------------------------------------------------------

    public static function form(Form $form): Form
    {
        return $form->schema([
            // Script Alpine.js para efeito de digitação da IA
            Forms\Components\View::make('filament.components.ai-typing-effect')
                ->columnSpanFull(),

            Forms\Components\Grid::make(12)
                ->schema([
                    // ---- COLUNA ESQUERDA: Info somente-leitura do produto original ----
                    Forms\Components\Section::make('Produto Original')
                        ->columnSpan(['default' => 12, 'lg' => 5])
                        ->schema([
                            // Galeria de imagens do fornecedor (read-only)
                            Forms\Components\Placeholder::make('supplier_images_gallery')
                                ->label('Imagens do Fornecedor')
                                ->content(function (?ClientProduct $record) {
                                    if (!$record?->product) {
                                        return new \Illuminate\Support\HtmlString('<p class="text-sm text-gray-400">Sem imagens do fornecedor.</p>');
                                    }
                                    $urls = $record->product->media()->pluck('url')->take(6);
                                    if ($urls->isEmpty()) {
                                        return new \Illuminate\Support\HtmlString('<p class="text-sm text-gray-400">Sem imagens cadastradas.</p>');
                                    }
                                    $imgs = $urls->map(fn($url) => "<img src=\"{$url}\" class=\"w-20 h-20 object-cover rounded-lg border border-gray-200 dark:border-gray-700\" />")->implode('');
                                    return new \Illuminate\Support\HtmlString("<div class=\"flex flex-wrap gap-2\">{$imgs}</div>");
                                })
                                ->columnSpanFull()
                                ->hidden(fn(?ClientProduct $record) => $record === null),

                            // Status da integração — painel completo
                            Forms\Components\Placeholder::make('sync_status_badge')
                                ->label('Status da Integração')
                                ->content(function (?ClientProduct $record) {
                                    if (!$record) return '—';

                                    $map = [
                                        'synced'  => ['label' => 'Publicado',  'bg' => 'bg-green-100 dark:bg-green-900', 'text' => 'text-green-800 dark:text-green-200', 'border' => 'border-green-200 dark:border-green-800', 'icon' => '✓'],
                                        'pending' => ['label' => 'Pendente',   'bg' => 'bg-yellow-50 dark:bg-yellow-900', 'text' => 'text-yellow-800 dark:text-yellow-200', 'border' => 'border-yellow-200 dark:border-yellow-800', 'icon' => '⏳'],
                                        'error'   => ['label' => 'Erro',       'bg' => 'bg-red-50 dark:bg-red-900', 'text' => 'text-red-800 dark:text-red-200', 'border' => 'border-red-200 dark:border-red-800', 'icon' => '✗'],
                                        'paused'  => ['label' => 'Pausado',    'bg' => 'bg-gray-100 dark:bg-gray-700', 'text' => 'text-gray-700 dark:text-gray-300', 'border' => 'border-gray-200 dark:border-gray-600', 'icon' => '⏸'],
                                        'draft'   => ['label' => 'Rascunho',   'bg' => 'bg-gray-50 dark:bg-gray-800', 'text' => 'text-gray-600 dark:text-gray-400', 'border' => 'border-gray-200 dark:border-gray-700', 'icon' => '○'],
                                    ];

                                    $status = $record->sync_status ?? 'draft';
                                    $cfg = $map[$status] ?? $map['draft'];

                                    $html = "<div class=\"rounded-xl border {$cfg['border']} {$cfg['bg']} p-3 space-y-2\">";

                                    $html .= "<div class=\"flex items-center gap-2\">";
                                    $html .= "<span class=\"text-lg\">{$cfg['icon']}</span>";
                                    $html .= "<span class=\"font-semibold {$cfg['text']}\">{$cfg['label']}</span>";
                                    $html .= "</div>";

                                    if ($record->external_listing_id) {
                                        $mlbId = preg_replace('/^MLB(\d+)$/', 'MLB-$1', $record->external_listing_id);
                                        $mlUrl = "https://produto.mercadolivre.com.br/{$mlbId}";
                                        $html .= "<div class=\"text-xs text-gray-600 dark:text-gray-400 flex items-center gap-2\">";
                                        $html .= "ID ML: <a href=\"{$mlUrl}\" target=\"_blank\" class=\"underline text-blue-600 dark:text-blue-400 font-semibold\">{$record->external_listing_id} &#8599;</a>";
                                        $html .= "</div>";
                                        $html .= "<div class=\"mt-1\">";
                                        $html .= "<a href=\"{$mlUrl}\" target=\"_blank\" class=\"inline-flex items-center gap-1 px-3 py-1 rounded-lg bg-yellow-400 hover:bg-yellow-500 text-xs font-bold text-gray-900 transition\">Ver Anúncio no Mercado Livre &#8599;</a>";
                                        $html .= "</div>";
                                    }

                                    if ($record->last_sync_at) {
                                        $html .= "<div class=\"text-xs text-gray-500 dark:text-gray-400\">";
                                        $html .= "Última sync: " . $record->last_sync_at->format('d/m/Y H:i:s');
                                        $html .= "</div>";
                                    }

                                    if ($status === 'error' && $record->last_sync_error) {
                                        $html .= "<div class=\"mt-1 p-2 rounded-lg bg-red-100 dark:bg-red-900/50 text-xs text-red-700 dark:text-red-300 border border-red-200 dark:border-red-800\">";
                                        $html .= "<strong>Erro:</strong> " . e($record->last_sync_error);
                                        $html .= "</div>";
                                    }

                                    $html .= "</div>";

                                    return new \Illuminate\Support\HtmlString($html);
                                })
                                ->hidden(fn(?ClientProduct $record) => $record === null)
                                ->columnSpanFull(),

                            // Botão de retry quando há erro — visível dentro do slide-over
                            Forms\Components\Actions::make([
                                Forms\Components\Actions\Action::make('retry_publish')
                                    ->label('Já corrigi o problema, tentar novamente')
                                    ->icon('heroicon-o-arrow-path')
                                    ->color('success')
                                    ->size('lg')
                                    ->requiresConfirmation()
                                    ->modalHeading('Reenviar para o Marketplace?')
                                    ->modalDescription('Confirme que você já corrigiu o problema na sua conta do Mercado Livre. O produto será reenviado.')
                                    ->action(function (?ClientProduct $record) {
                                        if (!$record) return;

                                        $record->update([
                                            'sync_status'     => 'pending',
                                            'last_sync_error' => null,
                                        ]);

                                        // Limpa bloqueio da conta
                                        if ($record->marketplaceAccount) {
                                            $record->marketplaceAccount->update(['sync_blocked_at' => null]);
                                        }

                                        PublishClientProductToMLJob::dispatch($record->id);

                                        Notification::make()
                                            ->title('Produto reenviado para publicação!')
                                            ->body('Aguarde alguns segundos e reabra este produto para ver o resultado.')
                                            ->success()
                                            ->duration(8000)
                                            ->send();
                                    }),
                            ])
                            ->hidden(fn(?ClientProduct $record) => !$record || $record->sync_status !== 'error')
                            ->columnSpanFull(),

                            // Histórico de sync
                            Forms\Components\Placeholder::make('sync_history')
                                ->label('Histórico de Sync')
                                ->content(function (?ClientProduct $record) {
                                    if (!$record) return '—';

                                    try {
                                        $logs = \DB::table('sync_logs')
                                            ->where('client_product_id', $record->id)
                                            ->orderByDesc('created_at')
                                            ->limit(5)
                                            ->get();
                                    } catch (\Throwable $e) {
                                        return new \Illuminate\Support\HtmlString('<p class="text-xs text-gray-400">Sem histórico disponível.</p>');
                                    }

                                    if ($logs->isEmpty()) {
                                        return new \Illuminate\Support\HtmlString('<p class="text-xs text-gray-400">Nenhuma tentativa de sync registrada.</p>');
                                    }

                                    $html = '<div class="space-y-2 max-h-48 overflow-y-auto">';
                                    foreach ($logs as $log) {
                                        $isError  = $log->status === 'error';
                                        $bgClass  = $isError ? 'bg-red-50 dark:bg-red-900/30 border-red-200 dark:border-red-800' : 'bg-green-50 dark:bg-green-900/30 border-green-200 dark:border-green-800';
                                        $icon     = $isError ? '✗' : '✓';
                                        $textClass = $isError ? 'text-red-700 dark:text-red-300' : 'text-green-700 dark:text-green-300';
                                        $time     = \Carbon\Carbon::parse($log->created_at)->format('d/m H:i');

                                        $html .= "<div class=\"p-2 rounded-lg border {$bgClass} text-xs\">";
                                        $html .= "<div class=\"flex items-center justify-between\">";
                                        $html .= "<span class=\"font-semibold {$textClass}\">{$icon} " . ucfirst(e($log->action)) . "</span>";
                                        $html .= "<span class=\"text-gray-400\">{$time}</span>";
                                        $html .= "</div>";
                                        $html .= "<p class=\"mt-1 {$textClass}\">" . e(\Illuminate\Support\Str::limit($log->error_message ?? $log->message ?? "", 200)) . "</p>";
                                        $html .= "</div>";
                                    }
                                    $html .= '</div>';

                                    return new \Illuminate\Support\HtmlString($html);
                                })
                                ->hidden(fn(?ClientProduct $record) => $record === null)
                                ->columnSpanFull(),

                            // Info do fornecedor
                            Forms\Components\Placeholder::make('supplier_info')
                                ->label('Produto do Fornecedor')
                                ->content(function (?ClientProduct $record) {
                                    if (!$record?->product) return '—';
                                    $p = $record->product;
                                    $price = 'R$ ' . number_format((float) $p->price, 2, ',', '.');
                                    $stock = $p->effective_stock ?? 0;
                                    return new \Illuminate\Support\HtmlString(
                                        '<div class="space-y-1 text-sm">'
                                        . '<div><span class="font-semibold text-gray-700 dark:text-gray-300">Nome:</span> ' . e($p->name) . '</div>'
                                        . '<div><span class="font-semibold text-gray-700 dark:text-gray-300">Preço base:</span> ' . $price . '</div>'
                                        . '<div><span class="font-semibold text-gray-700 dark:text-gray-300">Estoque:</span> ' . $stock . ' unidades</div>'
                                        . '</div>'
                                    );
                                })
                                ->hidden(fn(?ClientProduct $record) => $record === null),

                            // Campos somente-leitura visíveis na criação
                            // CREATE mode: checkboxes para multi-marketplace
                            Forms\Components\CheckboxList::make('marketplace_account_ids')
                                ->label('Lojas de Destino (marque uma ou mais)')
                                ->options(fn() => \App\Models\MarketplaceAccount::where('client_id', auth()->user()->client?->id)->pluck('account_name', 'id'))
                                ->required()
                                ->hidden(fn(?ClientProduct $record) => $record !== null)
                                ->columnSpanFull(),

                            // EDIT mode: loja atual (read-only)
                            Forms\Components\Select::make('marketplace_account_id')
                                ->label('Loja Integrada')
                                ->options(fn() => \App\Models\MarketplaceAccount::all()->pluck('account_name', 'id'))
                                ->disabled()
                                ->hidden(fn(?ClientProduct $record) => $record === null)
                                ->columnSpanFull(),

                            Forms\Components\TextInput::make('supplier_product_sku')
                                ->label('SKU Original (Ref Fornecedor)')
                                ->disabled()
                                ->columnSpanFull(),

                            Forms\Components\Select::make('product_id')
                                ->label('Produto do Catálogo')
                                ->options(fn() => \App\Models\Product::all()->pluck('name', 'id'))
                                ->disabled()
                                ->columnSpanFull(),

                            Forms\Components\TextInput::make('custom_sku')
                                ->label('Meu Sub-SKU (Rastreio)')
                                ->required()
                                ->maxLength(255)
                                ->unique(
                                    table: 'client_products',
                                    column: 'custom_sku',
                                    ignorable: fn(?ClientProduct $record) => $record,
                                    modifyRuleUsing: fn($rule) => $rule->where('client_id', auth()->user()->client?->id)
                                )
                                ->columnSpanFull(),
                        ]),

                    // ---- COLUNA DIREITA: Campos editáveis ----
                    Forms\Components\Group::make()
                        ->columnSpan(['default' => 12, 'lg' => 7])
                        ->schema([
                            // -- Section: Anúncio --
                            Forms\Components\Section::make('Anúncio')
                                ->schema([
                                    Forms\Components\TextInput::make('custom_title')
                                        ->label('Título do Anúncio')
                                        ->required()
                                        ->maxLength(255)
                                        ->extraInputAttributes(['id' => 'ai-title-input'])
                                        ->suffixAction(
                                            Forms\Components\Actions\Action::make('generate_ai_title')
                                                ->label('Gerar com IA')
                                                ->icon('heroicon-o-sparkles')
                                                ->color('info')
                                                ->action(function (Set $set, Get $get, ?ClientProduct $record, \Livewire\Component $livewire) {
                                                    if (!$record) {
                                                        Notification::make()
                                                            ->title('Salve o produto primeiro para usar a IA.')
                                                            ->warning()
                                                            ->send();
                                                        return;
                                                    }
                                                    try {
                                                        $service = app(AIProductContentService::class);
                                                        $title = $service->generateTitleForClientProduct($record);
                                                        // Efeito de digitação via Alpine.js
                                                        $livewire->dispatch('ai-type-effect', targetId: 'ai-title-input', text: $title);
                                                        Notification::make()
                                                            ->title('Título gerado com sucesso!')
                                                            ->success()
                                                            ->send();
                                                    } catch (\Throwable $e) {
                                                        Notification::make()
                                                            ->title('Erro ao chamar IA: ' . $e->getMessage())
                                                            ->danger()
                                                            ->send();
                                                    }
                                                })
                                        )
                                        ->columnSpanFull(),

                                    Forms\Components\Textarea::make('custom_description')
                                        ->label('Descrição do Anúncio')
                                        ->rows(6)
                                        ->columnSpanFull()
                                        ->extraInputAttributes(['id' => 'ai-desc-input'])
                                        ->hintAction(
                                            Forms\Components\Actions\Action::make('generate_ai_description')
                                                ->label('Gerar com IA')
                                                ->icon('heroicon-o-sparkles')
                                                ->color('info')
                                                ->action(function (Set $set, Get $get, ?ClientProduct $record, \Livewire\Component $livewire) {
                                                    if (!$record) {
                                                        Notification::make()
                                                            ->title('Salve o produto primeiro para usar a IA.')
                                                            ->warning()
                                                            ->send();
                                                        return;
                                                    }
                                                    try {
                                                        $service = app(AIProductContentService::class);
                                                        $desc = $service->generateDescriptionForClientProduct($record);
                                                        // Efeito de digitação via Alpine.js
                                                        $livewire->dispatch('ai-type-effect', targetId: 'ai-desc-input', text: $desc);
                                                        Notification::make()
                                                            ->title('Descrição gerada com sucesso!')
                                                            ->success()
                                                            ->send();
                                                    } catch (\Throwable $e) {
                                                        Notification::make()
                                                            ->title('Erro ao chamar IA: ' . $e->getMessage())
                                                            ->danger()
                                                            ->send();
                                                    }
                                                })
                                        ),

                                    Forms\Components\Textarea::make('ai_bullet_points')
                                        ->label('Bullet Points (Benefícios)')
                                        ->rows(5)
                                        ->columnSpanFull()
                                        ->afterStateHydrated(function ($state, $set, ?ClientProduct $record) {
                                            if ($record?->product?->ai_bullet_points) {
                                                $bullets = $record->product->ai_bullet_points;
                                                if (is_array($bullets)) {
                                                    $set('ai_bullet_points', implode("
", array_map(fn($b) => "• " . $b, $bullets)));
                                                } else {
                                                    $set('ai_bullet_points', $bullets);
                                                }
                                            }
                                        })
                                        ->hintAction(
                                            Forms\Components\Actions\Action::make('generate_ai_bullets')
                                                ->label('Gerar com IA')
                                                ->icon('heroicon-o-sparkles')
                                                ->color('info')
                                                ->action(function (Set $set, ?ClientProduct $record) {
                                                    if (!$record?->product) {
                                                        Notification::make()
                                                            ->title('Salve o produto primeiro para usar a IA.')
                                                            ->warning()
                                                            ->send();
                                                        return;
                                                    }
                                                    try {
                                                        $service = app(AIProductContentService::class);
                                                        $bullets = $service->generateBulletPoints($record->product);
                                                        $text = implode("
", array_map(fn($b) => "• " . $b, $bullets));
                                                        $set('ai_bullet_points', $text);
                                                        // Persiste imediatamente no produto base
                                                        $record->product->update(['ai_bullet_points' => $bullets]);
                                                        Notification::make()
                                                            ->title('Bullets gerados!')
                                                            ->success()
                                                            ->send();
                                                    } catch (\Throwable $e) {
                                                        Notification::make()
                                                            ->title('Erro: ' . $e->getMessage())
                                                            ->danger()
                                                            ->send();
                                                    }
                                                })
                                        ),
                                ]),

                            // -- Section: Preço --
                            Forms\Components\Section::make('Preço')
                                ->columns(2)
                                ->schema([
                                    Forms\Components\TextInput::make('custom_price')
                                        ->label('Meu Preço de Venda')
                                        ->required()
                                        ->numeric()
                                        ->prefix('R$'),
                                    Forms\Components\Select::make('pricing_mode')
                                        ->label('Regra de Precificação')
                                        ->options([
                                            'manual' => 'Manual (Sem Auto-Sincronização)',
                                            'margin' => 'Margem Fixa (Auto-Sincroniza Preço)',
                                        ])
                                        ->required()
                                        ->default('manual'),
                                    Forms\Components\Toggle::make('is_active')
                                        ->label('Ativo para Venda')
                                        ->default(true)
                                        ->columnSpanFull(),
                                ]),

                            // -- Section: Marketplace --
                            Forms\Components\Section::make('Configuração do Marketplace')
                                ->schema([
                                    Forms\Components\Grid::make(2)
                                        ->schema([
                                            Forms\Components\Select::make('external_category_id')
                                                ->label('Categoria ML')
                                                ->searchable()
                                                ->getSearchResultsUsing(function (string $search, ?ClientProduct $record): array {
                                                    if (mb_strlen($search) < 3) return [];
                                                    try {
                                                        $account = $record?->marketplaceAccount;
                                                        if (!$account?->ml_access_token) return [];
                                                        $service = app(\App\Services\MercadoLivreService::class);
                                                        $token = $service->getValidToken($account);
                                                        $results = $service->predictCategory($token, $search);
                                                        return collect($results)->take(8)->mapWithKeys(fn($r) => [
                                                            $r['category_id'] => "{$r['category_name']} ({$r['category_id']})",
                                                        ])->toArray();
                                                    } catch (\Throwable $e) {
                                                        return [];
                                                    }
                                                })
                                                ->getOptionLabelUsing(function (?string $value, ?ClientProduct $record): string {
                                                    if (!$value) return '';
                                                    try {
                                                        $account = $record?->marketplaceAccount;
                                                        if ($account?->ml_access_token) {
                                                            $service = app(\App\Services\MercadoLivreService::class);
                                                            $token = $service->getValidToken($account);
                                                            $path = $service->getCategoryPath($token, $value);
                                                            return "{$path} ({$value})";
                                                        }
                                                    } catch (\Throwable $e) {}
                                                    return $value;
                                                })
                                                ->helperText('Digite o nome do produto para buscar categorias do Mercado Livre, ou clique em "Sugerir com IA".')
                                                ->hintAction(
                                                    Forms\Components\Actions\Action::make('suggest_category')
                                                        ->label('Sugerir com IA')
                                                        ->icon('heroicon-o-sparkles')
                                                        ->color('info')
                                                        ->action(function (Set $set, Get $get, ?ClientProduct $record) {
                                                            if (!$record?->marketplaceAccount?->ml_access_token) {
                                                                Notification::make()->title('Conecte a loja ao Mercado Livre primeiro.')->warning()->send();
                                                                return;
                                                            }

                                                            $title = $get('custom_title') ?? $record->product?->name ?? '';
                                                            if (!$title) {
                                                                Notification::make()->title('Preencha o título do anúncio primeiro.')->warning()->send();
                                                                return;
                                                            }

                                                            try {
                                                                $service = app(\App\Services\MercadoLivreService::class);
                                                                $token = $service->getValidToken($record->marketplaceAccount);
                                                                $suggestions = $service->predictCategory($token, $title);

                                                                if (empty($suggestions)) {
                                                                    Notification::make()->title('Nenhuma categoria encontrada para esse título.')->warning()->send();
                                                                    return;
                                                                }

                                                                $best = $suggestions[0];
                                                                $set('external_category_id', $best['category_id']);

                                                                $list = collect($suggestions)->take(3)
                                                                    ->map(fn($s) => "{$s['category_name']} ({$s['category_id']})")
                                                                    ->implode("\n");

                                                                Notification::make()
                                                                    ->title("Categoria: {$best['category_name']}")
                                                                    ->body("Outras opções:\n{$list}")
                                                                    ->success()
                                                                    ->duration(10000)
                                                                    ->send();
                                                            } catch (\Throwable $e) {
                                                                Notification::make()->title('Erro: ' . $e->getMessage())->danger()->send();
                                                            }
                                                        })
                                                ),

                                            Forms\Components\Select::make('listing_type_id')
                                                ->label('Tipo de Anúncio')
                                                ->options([
                                                    'gold_special' => 'Clássico (gold_special)',
                                                    'gold_premium' => 'Premium (gold_premium)',
                                                    'gold_pro'     => 'Premium Full (gold_pro)',
                                                    'bronze'       => 'Gratuito (bronze)',
                                                ])
                                                ->default('gold_special'),
                                        ]),
                                ])
                                ->hidden(fn(?ClientProduct $record) => $record === null),

                            // -- Section: Características --
                            Forms\Components\Section::make('Características')
                                ->columns(2)
                                ->schema([
                                    Forms\Components\TextInput::make('custom_brand')->label('Marca')->maxLength(255),
                                    Forms\Components\TextInput::make('custom_model')->label('Modelo')->maxLength(255),
                                    Forms\Components\TextInput::make('custom_gtin')->label('Código de Barras (GTIN/EAN)')->maxLength(255),
                                    Forms\Components\Select::make('custom_condition')
                                        ->label('Condição')
                                        ->options([
                                            'new'         => 'Novo',
                                            'used'        => 'Usado',
                                            'refurbished' => 'Recondicionado',
                                        ])
                                        ->default('new'),
                                    Forms\Components\TextInput::make('custom_warranty_type')->label('Tipo de Garantia')->maxLength(255),
                                    Forms\Components\TextInput::make('custom_warranty_days')->label('Dias de Garantia')->numeric(),
                                ]),

                            // -- Section: Embalagem --
                            Forms\Components\Section::make('Embalagem e Medidas')
                                ->columns(2)
                                ->schema([
                                    Forms\Components\TextInput::make('custom_weight_kg')->label('Peso (Kg)')->numeric()->step('0.001'),
                                    Forms\Components\TextInput::make('custom_height_cm')->label('Altura (cm)')->numeric(),
                                    Forms\Components\TextInput::make('custom_width_cm')->label('Largura (cm)')->numeric(),
                                    Forms\Components\TextInput::make('custom_length_cm')->label('Comprimento (cm)')->numeric(),
                                ]),

                            // -- Section: Fotos do anúncio --
                            Forms\Components\Section::make('Fotos do Meu Anúncio')
                                ->schema([
                                    Forms\Components\FileUpload::make('custom_images')
                                        ->label('Imagens Personalizadas')
                                        ->image()
                                        ->multiple()
                                        ->reorderable()
                                        ->columnSpanFull(),
                                ]),

                            // -- Botão Publicar no Marketplace (dentro do form) --
                            Forms\Components\Actions::make([
                                Forms\Components\Actions\Action::make('publish_to_marketplace_form')
                                    ->label('Salvar e Publicar no Marketplace')
                                    ->icon('heroicon-o-rocket-launch')
                                    ->color('success')
                                    ->size('lg')
                                    ->requiresConfirmation()
                                    ->modalHeading('Publicar no Marketplace?')
                                    ->modalDescription('Os dados serão salvos e o produto será enviado para o marketplace.')
                                    ->action(function (Get $get, ?ClientProduct $record) {
                                        if (!$record) {
                                            Notification::make()->title('Salve o produto primeiro.')->warning()->send();
                                            return;
                                        }

                                        // Bloqueio: se há erro de conta ativo, não permite publicar
                                        if ($record->sync_status === 'error' && static::isAccountError($record->last_sync_error)) {
                                            Notification::make()
                                                ->title('Resolva o problema da sua conta antes de publicar')
                                                ->body($record->last_sync_error)
                                                ->danger()
                                                ->persistent()
                                                ->send();
                                            return;
                                        }

                                        // Bloqueio: conta marcada como bloqueada
                                        if ($record->marketplaceAccount?->sync_blocked_at !== null) {
                                            Notification::make()
                                                ->title('Conta bloqueada para sincronização')
                                                ->body('Resolva o problema da sua conta no Mercado Livre e clique em "Já resolvi, tentar novamente" na listagem de produtos.')
                                                ->danger()
                                                ->persistent()
                                                ->send();
                                            return;
                                        }

                                        // Validação: categoria obrigatória para o ML (usa valor do form, não do banco)
                                        $formCategoryId = $get('external_category_id');
                                        if (!$record->external_listing_id && !$formCategoryId) {
                                            $hasCategory = $record->product?->category?->external_id ?? null;
                                            if (!$hasCategory) {
                                                Notification::make()
                                                    ->title('Categoria obrigatória!')
                                                    ->body('O Mercado Livre exige uma categoria para criar o anúncio. Configure a categoria no campo "Categoria ML" antes de publicar.')
                                                    ->danger()
                                                    ->persistent()
                                                    ->send();
                                                return;
                                            }
                                        }

                                        // Salva os dados do form
                                        $fieldsToSave = [
                                            'custom_title', 'custom_description', 'custom_price',
                                            'pricing_mode', 'is_active', 'custom_brand', 'custom_model',
                                            'custom_gtin', 'custom_condition', 'custom_warranty_type',
                                            'custom_warranty_days', 'custom_weight_kg', 'custom_height_cm',
                                            'custom_width_cm', 'custom_length_cm', 'custom_images',
                                            'external_category_id', 'listing_type_id',
                                        ];

                                        $data = [];
                                        foreach ($fieldsToSave as $field) {
                                            $val = $get($field);
                                            if ($val !== null) {
                                                $data[$field] = $val;
                                            }
                                        }

                                        $record->update($data);

                                        // Dispara o job de publicação
                                        PublishClientProductToMLJob::dispatch($record->id);

                                        $record->update(['sync_status' => 'pending']);

                                        Notification::make()
                                            ->title('Produto salvo e enviado para publicação!')
                                            ->body('O anúncio está sendo processado. Recarregue a página em alguns segundos para ver o status atualizado.')
                                            ->success()
                                            ->duration(8000)
                                            ->send();
                                    }),
                            ])
                            ->hidden(fn(?ClientProduct $record) => !$record
                                || !$record->marketplaceAccount
                                || (
                                    $record->marketplaceAccount->status !== 'active'
                                    && empty($record->marketplaceAccount->ml_access_token)
                                )
                            )
                            ->columnSpanFull(),

                        // Aviso: conta ML não conectada
                        Forms\Components\Placeholder::make('ml_not_connected_warning')
                            ->label('')
                            ->content(fn(?ClientProduct $record) => new \Illuminate\Support\HtmlString(
                                '<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:16px;color:#b91c1c;">'
                                . '<strong>&#9888; Conta Mercado Livre não conectada</strong><br>'
                                . 'Para publicar produtos, vá em <strong>Integrações &rarr; Mercado Livre</strong> e conecte sua conta.'
                                . '</div>'
                            ))
                            ->visible(fn(?ClientProduct $record) => $record
                                && (
                                    !$record->marketplaceAccount
                                    || (
                                        $record->marketplaceAccount->status !== 'active'
                                        && empty($record->marketplaceAccount->ml_access_token)
                                    )
                                )
                            )
                            ->columnSpanFull(),
                        ]),
                ]),
        ]);
    }

    // -------------------------------------------------------------------------
    // TABLE
    // -------------------------------------------------------------------------

    public static function table(Table $table): Table
    {
        return $table
            ->checkIfRecordIsSelectableUsing(
                fn(\Illuminate\Database\Eloquent\Model $record, \Livewire\Component $livewire): bool => !($livewire->isGridLayout ?? false)
            )
            ->heading(new \Illuminate\Support\HtmlString(view('filament.components.nexus-search-heading')->render()))
            ->content(fn(\Livewire\Component $livewire) => ($livewire->isGridLayout ?? false) ? view('filament.components.product-grid-body') : null)
            ->defaultPaginationPageOption(50)
            ->headerActions([
                Tables\Actions\Action::make('toggleGrid')
                    ->label(fn(\Livewire\Component $livewire) => ($livewire->isGridLayout ?? false) ? 'Ver em Lista' : 'Ver em Grade')
                    ->hiddenLabel()
                    ->tooltip(fn(\Livewire\Component $livewire) => ($livewire->isGridLayout ?? false) ? 'Ver Tabela' : 'Ver Cards')
                    ->icon(fn(\Livewire\Component $livewire) => ($livewire->isGridLayout ?? false) ? 'heroicon-m-list-bullet' : 'heroicon-m-squares-2x2')
                    ->color('gray')
                    ->action(function (\Livewire\Component $livewire) {
                        $livewire->isGridLayout = !($livewire->isGridLayout ?? false);
                    }),
            ])
            ->columns([
                // -- MODO LISTA --
                Tables\Columns\ImageColumn::make('media_list')
                    ->label('Imagem')
                    ->getStateUsing(fn(ClientProduct $record) => $record->product?->media()->first()?->url)
                    ->size(80)
                    ->extraImgAttributes(['class' => 'rounded-md'])
                    ->visible(fn(\Livewire\Component $livewire) => !($livewire->isGridLayout ?? false)),

                Tables\Columns\TextColumn::make('custom_title_list')
                    ->getStateUsing(fn(ClientProduct $record) => $record->custom_title)
                    ->label('Produto')
                    ->sortable(['custom_title'])
                    ->weight('bold')
                    ->description(fn(ClientProduct $record) => '#' . $record->custom_sku)
                    ->limit(40)
                    ->visible(fn(\Livewire\Component $livewire) => !($livewire->isGridLayout ?? false)),

                Tables\Columns\TextColumn::make('custom_price_list')
                    ->getStateUsing(fn(ClientProduct $record) => $record->custom_price)
                    ->label('Preço Base')
                    ->money('BRL')
                    ->sortable(['custom_price'])
                    ->weight('bold')
                    ->color('success')
                    ->visible(fn(\Livewire\Component $livewire) => !($livewire->isGridLayout ?? false)),

                Tables\Columns\TextColumn::make('product_cost_list')
                    ->getStateUsing(fn(ClientProduct $record) => $record->product?->price)
                    ->label('Custo')
                    ->formatStateUsing(fn($state) => $state !== null ? 'R$ ' . number_format((float)$state, 2, ',', '.') : '—')
                    ->color('gray')
                    ->size(\Filament\Tables\Columns\TextColumn\TextColumnSize::Small)
                    ->visible(fn(\Livewire\Component $livewire) => !($livewire->isGridLayout ?? false)),

                Tables\Columns\TextColumn::make('marketplace_list')
                    ->getStateUsing(fn(ClientProduct $record) => $record->marketplaceAccount?->account_name)
                    ->label('Marketplace')
                    ->badge()
                    ->color(function (ClientProduct $record) {
                        $platform = $record->marketplaceAccount?->platform ?? '';
                        return match($platform) {
                            'shopee'       => 'warning',
                            'mercadolivre' => 'success',
                            'bling'        => 'info',
                            default        => 'gray',
                        };
                    })
                    ->formatStateUsing(function (ClientProduct $record) {
                        $name = $record->marketplaceAccount?->account_name ?? '—';
                        $platform = $record->marketplaceAccount?->platform ?? '';
                        $icon = match($platform) {
                            'shopee'       => '🛒 ',
                            'mercadolivre' => '🏷️ ',
                            'bling'        => '📦 ',
                            default        => '',
                        };
                        return $icon . $name;
                    })
                    ->visible(fn(\Livewire\Component $livewire) => !($livewire->isGridLayout ?? false)),

                // Coluna sync_status — TAREFA 6
                Tables\Columns\TextColumn::make('sync_status')
                    ->label('Status Sync')
                    ->badge()
                    ->formatStateUsing(fn(?string $state): string => match ($state) {
                        'synced'  => 'Publicado',
                        'pending' => 'Pendente',
                        'error'   => 'Erro',
                        'paused'  => 'Pausado',
                        default   => 'Rascunho',
                    })
                    ->color(fn(?string $state): string => match ($state) {
                        'synced'  => 'success',
                        'pending' => 'warning',
                        'error'   => 'danger',
                        'paused'  => 'gray',
                        default   => 'gray',
                    })
                    ->tooltip(fn(ClientProduct $record): ?string => $record->sync_status === 'error' ? $record->last_sync_error : null)
                    ->visible(fn(\Livewire\Component $livewire) => !($livewire->isGridLayout ?? false)),

                Tables\Columns\TextColumn::make('pricing_mode_list')
                    ->getStateUsing(fn(ClientProduct $record) => $record->pricing_mode)
                    ->label('Regra')
                    ->badge()
                    ->color('gray')
                    ->visible(fn(\Livewire\Component $livewire) => !($livewire->isGridLayout ?? false)),

                Tables\Columns\IconColumn::make('is_active_list')
                    ->getStateUsing(fn(ClientProduct $record) => $record->is_active)
                    ->label('Ativo')
                    ->boolean()
                    ->visible(fn(\Livewire\Component $livewire) => !($livewire->isGridLayout ?? false)),

                Tables\Columns\TextColumn::make('external_listing_id')
                    ->label('Anúncio ML')
                    ->getStateUsing(fn(ClientProduct $record) => $record->external_listing_id)
                    ->formatStateUsing(function (?string $state): \Illuminate\Support\HtmlString {
                        if (!$state) {
                            return new \Illuminate\Support\HtmlString('<span class="text-xs text-gray-400 italic">Não publicado</span>');
                        }
                        $mlbId = preg_replace('/^MLB(\d+)$/', 'MLB-$1', $state);
                        $url = "https://produto.mercadolivre.com.br/{$mlbId}";
                        return new \Illuminate\Support\HtmlString(
                            "<a href=\"{$url}\" target=\"_blank\" title=\"Ver anúncio no Mercado Livre\" "
                            . "class=\"inline-flex items-center gap-1 px-2 py-0.5 rounded bg-yellow-400 hover:bg-yellow-500 text-xs font-bold text-gray-900 transition\">"
                            . "{$state} &#8599;"
                            . "</a>"
                        );
                    })
                    ->html()
                    ->visible(fn(\Livewire\Component $livewire) => !($livewire->isGridLayout ?? false)),

                // -- Qualidade do anúncio --
                Tables\Columns\TextColumn::make('listing_quality_score')
                    ->label('Qualidade')
                    ->badge()
                    ->formatStateUsing(fn($state) => $state ? $state . '%' : '—')
                    ->color(fn($state) => match(true) {
                        $state >= 80 => 'success',
                        $state >= 60 => 'warning',
                        default => 'danger',
                    })
                    ->tooltip(fn(ClientProduct $record) => $record->listing_quality_issues ? implode(', ', (array)json_decode($record->listing_quality_issues, true)) : null)
                    ->visible(fn(\Livewire\Component $livewire) => !($livewire->isGridLayout ?? false)),

                // -- Vendas (contagem de order items) --
                Tables\Columns\TextColumn::make('order_items_count')
                    ->label('Vendas')
                    ->counts('orderItems')
                    ->badge()
                    ->color('success')
                    ->formatStateUsing(fn($state) => $state . ' venda' . ($state != 1 ? 's' : ''))
                    ->visible(fn(\Livewire\Component $livewire) => !($livewire->isGridLayout ?? false)),

                // -- Origem: Bot/IA vs Manual --
                Tables\Columns\IconColumn::make('auto_listed')
                    ->label('Origem')
                    ->getStateUsing(fn(ClientProduct $record) => $record->auto_listing_queue_items_count > 0)
                    ->tooltip(fn(ClientProduct $record) => $record->auto_listing_queue_items_count > 0 ? 'Cadastrado pelo Bot/IA' : 'Cadastro Manual')
                    ->icon(fn(bool $state) => $state ? 'heroicon-o-cpu-chip' : 'heroicon-o-hand-raised')
                    ->color(fn(bool $state) => $state ? 'info' : 'gray')
                    ->visible(fn(\Livewire\Component $livewire) => !($livewire->isGridLayout ?? false)),

                // -- Anúncio Shopee --
                Tables\Columns\TextColumn::make('shopee_external_item_id')
                    ->label('Anúncio Shopee')
                    ->formatStateUsing(function (?string $state) {
                        if (!$state) return new \Illuminate\Support\HtmlString('<span class="text-xs text-gray-400 italic">Não publicado</span>');
                        $url = "https://shopee.com.br/product/{$state}";
                        return new \Illuminate\Support\HtmlString(
                            "<a href=\"{$url}\" target=\"_blank\" class=\"inline-flex items-center gap-1 px-2 py-0.5 rounded bg-orange-400 hover:bg-orange-500 text-xs font-bold text-white transition\">"
                            . "{$state} &#8599;"
                            . "</a>"
                        );
                    })
                    ->html()
                    ->visible(fn(\Livewire\Component $livewire) => !($livewire->isGridLayout ?? false)),

                // -- Data de cadastro --
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Cadastrado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->color('gray')
                    ->size(\Filament\Tables\Columns\TextColumn\TextColumnSize::Small)
                    ->visible(fn(\Livewire\Component $livewire) => !($livewire->isGridLayout ?? false)),
            ])
            ->groups([
                Tables\Grouping\Group::make('product.name')
                    ->label('Produto Base')
                    ->collapsible()
                    ->titlePrefixedWithLabel(false),
            ])
            ->filters([
                \Filament\Tables\Filters\Filter::make('custom_search')
                    ->form([
                        \Filament\Forms\Components\Hidden::make('search_by')->default('name'),
                        \Filament\Forms\Components\Hidden::make('search_term'),
                    ])
                    ->query(function (\Illuminate\Database\Eloquent\Builder $query, array $data) {
                        if (!empty($data['search_term']) && !empty($data['search_by'])) {
                            $term = '%' . trim($data['search_term']) . '%';
                            if ($data['search_by'] === 'name') {
                                $query->where(function ($q) use ($term) {
                                    $q->where('custom_title', 'like', $term)
                                        ->orWhereHas('product', fn($q2) => $q2->where('name', 'like', $term));
                                });
                            } elseif ($data['search_by'] === 'sku') {
                                $query->where(function ($q) use ($term) {
                                    $q->where('custom_sku', 'like', $term)
                                        ->orWhereHas('product', fn($q2) => $q2->where('sku', 'like', $term));
                                });
                            }
                        }
                        return $query;
                    }),
            ])
            ->filtersLayout(\Filament\Tables\Enums\FiltersLayout::Dropdown)
            ->actions([
                // ViewAction — mantém o modal de detalhes com carousel
                Tables\Actions\ViewAction::make()
                    ->label('Detalhes')
                    ->modalHeading('Ficha do Produto Na Loja')
                    ->modalWidth('5xl')
                    ->icon('heroicon-m-eye')
                    ->color('info')
                    ->infolist([
                        \Filament\Infolists\Components\Grid::make(12)
                            ->schema([
                                \Filament\Infolists\Components\Section::make('Imagens')
                                    ->schema([
                                        \Filament\Infolists\Components\ViewEntry::make('gallery')
                                            ->label('')
                                            ->view('filament.components.product-carousel')
                                            ->getStateUsing(function (ClientProduct $record) {
                                                return $record->product
                                                    ? $record->product->media()->pluck('url')->toArray()
                                                    : [];
                                            }),
                                    ])
                                    ->columnSpan(['default' => 12, 'md' => 5]),

                                \Filament\Infolists\Components\Section::make('Dados Locais e de Fornecedor')
                                    ->schema([
                                        \Filament\Infolists\Components\TextEntry::make('custom_title')->label('Título do Anúncio (Local)')->weight('bold')->size('lg')->columnSpanFull(),
                                        \Filament\Infolists\Components\TextEntry::make('custom_sku')->label('Meu SKU')->badge(),
                                        \Filament\Infolists\Components\TextEntry::make('custom_price')->label('Meu Preço de Venda')->money('BRL')->color('success')->weight('bold')->size('xl'),
                                        \Filament\Infolists\Components\TextEntry::make('marketplaceAccount.account_name')->label('Loja Integrada')->badge()->color('info'),
                                        \Filament\Infolists\Components\TextEntry::make('external_listing_url')
                                            ->label('Link no Marketplace')
                                            ->url(fn(ClientProduct $record) => $record->external_listing_url)
                                            ->openUrlInNewTab()
                                            ->visible(fn(ClientProduct $record) => !empty($record->external_listing_url))
                                            ->color('info'),
                                        \Filament\Infolists\Components\TextEntry::make('product.name')->label('Título Original (Fornecedor)')->color('gray')->columnSpanFull(),
                                        \Filament\Infolists\Components\TextEntry::make('product.sku')->label('Master SKU')->color('gray'),
                                        \Filament\Infolists\Components\TextEntry::make('supplier_product_sku')->label('Ref Auxiliar')->color('gray'),
                                        \Filament\Infolists\Components\TextEntry::make('custom_description')->label('Descrição do Anúncio')->html()->columnSpanFull(),
                                    ])
                                    ->columns(2)
                                    ->columnSpan(['default' => 12, 'md' => 7]),
                            ]),
                    ]),

                // Edição inline via SlideOver (Action genérico — não depende da rota edit)
                Tables\Actions\Action::make('edit_inline')
                    ->label('Editar')
                    ->icon('heroicon-m-pencil-square')
                    ->color('primary')
                    ->slideOver()
                    ->modalWidth('7xl')
                    ->modalHeading(fn(ClientProduct $record) => 'Editar: ' . $record->custom_title)
                    ->modalSubmitActionLabel('Salvar')
                    ->fillForm(fn(ClientProduct $record): array => $record->toArray())
                    ->form(fn(Form $form) => static::form($form))
                    ->action(function (ClientProduct $record, array $data) {
                        // Remove campos de placeholder que não existem no model
                        unset($data['supplier_images_gallery'], $data['sync_status_badge'], $data['supplier_info'], $data['stock_view']);

                        // Remove campos read-only que não devem ser sobrescritos na edição
                        unset($data['marketplace_account_id'], $data['marketplace_account_ids'], $data['supplier_product_sku'], $data['product_id']);

                        $record->update($data);

                        // Marca como pendente se estava sincronizado
                        if ($record->sync_status === 'synced') {
                            $record->update(['sync_status' => 'pending']);
                        }

                        Notification::make()
                            ->title('Produto salvo com sucesso!')
                            ->success()
                            ->send();
                    }),

                // Publicar direto (usado pelo botão foguete no grid de cards)
                Tables\Actions\Action::make('publish_to_marketplace_grid')
                    ->label('Publicar no Marketplace')
                    ->icon('heroicon-o-rocket-launch')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Publicar no Marketplace?')
                    ->modalDescription('O produto será enviado para o marketplace com os dados atuais.')
                    ->visible(fn(ClientProduct $record) => $record->marketplaceAccount
                        && ($record->marketplaceAccount->status === 'active' || !empty($record->marketplaceAccount->ml_access_token))
                    )
                    ->action(function (ClientProduct $record) {
                        // Bloqueio: se há erro de conta ativo, não permite publicar
                        if ($record->sync_status === 'error' && static::isAccountError($record->last_sync_error)) {
                            Notification::make()
                                ->title('Resolva o problema da sua conta antes de publicar')
                                ->body($record->last_sync_error)
                                ->danger()
                                ->persistent()
                                ->send();
                            return;
                        }

                        // Bloqueio: conta marcada como bloqueada
                        if ($record->marketplaceAccount?->sync_blocked_at !== null) {
                            Notification::make()
                                ->title('Conta bloqueada para sincronização')
                                ->body('Resolva o problema da sua conta no Mercado Livre e use o botão "Já resolvi, tentar novamente" na listagem.')
                                ->danger()
                                ->persistent()
                                ->send();
                            return;
                        }

                        $record->update(['sync_status' => 'pending']);
                        PublishClientProductToMLJob::dispatch($record->id);

                        Notification::make()
                            ->title('Produto enviado para publicação!')
                            ->body('Acompanhe o status na coluna "Status Sync".')
                            ->success()
                            ->send();
                    }),

                // Pausar anúncio no ML
                Tables\Actions\Action::make('pause_ml')
                    ->label('Pausar no ML')
                    ->icon('heroicon-o-pause-circle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Pausar anúncio?')
                    ->modalDescription('O anúncio ficará invisível no Mercado Livre mas poderá ser reativado depois.')
                    ->visible(fn(ClientProduct $record) => $record->external_listing_id && $record->sync_status === 'synced')
                    ->action(function (ClientProduct $record) {
                        try {
                            $mlService = app(\App\Services\MercadoLivreService::class);
                            $token = $mlService->getValidToken($record->marketplaceAccount);
                            $ok = PublishClientProductToMLJob::pauseItem($record, $token);
                            Notification::make()
                                ->title($ok ? 'Anúncio pausado!' : 'Falha ao pausar.')
                                ->icon($ok ? 'heroicon-o-check' : 'heroicon-o-x-mark')
                                ->color($ok ? 'success' : 'danger')
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('Erro: ' . $e->getMessage())->danger()->send();
                        }
                    }),

                // Reativar anúncio no ML
                Tables\Actions\Action::make('activate_ml')
                    ->label('Reativar no ML')
                    ->icon('heroicon-o-play-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn(ClientProduct $record) => $record->external_listing_id && $record->sync_status === 'paused')
                    ->action(function (ClientProduct $record) {
                        try {
                            $mlService = app(\App\Services\MercadoLivreService::class);
                            $token = $mlService->getValidToken($record->marketplaceAccount);
                            $ok = PublishClientProductToMLJob::activateItem($record, $token);
                            Notification::make()
                                ->title($ok ? 'Anúncio reativado!' : 'Falha ao reativar.')
                                ->color($ok ? 'success' : 'danger')
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('Erro: ' . $e->getMessage())->danger()->send();
                        }
                    }),

                // Excluir anúncio do ML (permanente)
                Tables\Actions\Action::make('delete_ml')
                    ->label('Excluir do ML')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Excluir anúncio permanentemente?')
                    ->modalDescription('O anúncio será removido do Mercado Livre de forma irreversível. O produto continuará no seu catálogo local.')
                    ->visible(fn(ClientProduct $record) => $record->external_listing_id !== null)
                    ->action(function (ClientProduct $record) {
                        try {
                            $mlService = app(\App\Services\MercadoLivreService::class);
                            $token = $mlService->getValidToken($record->marketplaceAccount);
                            $ok = PublishClientProductToMLJob::deleteItem($record, $token);
                            Notification::make()
                                ->title($ok ? 'Anúncio excluído do Mercado Livre!' : 'Falha ao excluir.')
                                ->color($ok ? 'success' : 'danger')
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('Erro: ' . $e->getMessage())->danger()->send();
                        }
                    }),

                // Ver Anúncio no Mercado Livre
                Tables\Actions\Action::make('view_ml_listing')
                    ->label(fn(ClientProduct $record) => $record->external_listing_id ? 'Ver Anúncio' : 'Não publicado')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('warning')
                    ->tooltip(fn(ClientProduct $record) => $record->external_listing_id
                        ? 'Abrir anúncio no Mercado Livre'
                        : 'Produto ainda não publicado no Mercado Livre'
                    )
                    ->disabled(fn(ClientProduct $record) => !$record->external_listing_id)
                    ->url(function (ClientProduct $record): ?string {
                        if (!$record->external_listing_id) return null;
                        $mlbId = preg_replace('/^MLB(\d+)$/', 'MLB-$1', $record->external_listing_id);
                        return "https://produto.mercadolivre.com.br/{$mlbId}";
                    })
                    ->openUrlInNewTab(),

                // Duplicar
                Tables\Actions\Action::make('duplicate')
                    ->label('Duplicar')
                    ->icon('heroicon-m-document-duplicate')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function (ClientProduct $record) {
                        $newProduct = $record->replicate();
                        $newProduct->custom_sku = $record->custom_sku . '-COPIA-' . rand(100, 999);
                        $newProduct->sync_status = 'draft';
                        $newProduct->external_listing_id = null;
                        $newProduct->save();

                        Notification::make()
                            ->title('Anúncio Duplicado')
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('Excluir do Sub-Catálogo'),
                ]),
            ]);
    }

    // -------------------------------------------------------------------------
    // Helper: detecta se o erro é de conta (não de dados do produto)
    // -------------------------------------------------------------------------

    protected static function isAccountError(?string $error): bool
    {
        if (!$error) {
            return false;
        }
        $accountPatterns = [
            'Endereço não cadastrado',
            'Conta não habilitada',
            'Token expirado',
            'Sem permissão',
            'address_pending',
            'seller.unable_to_list',
            'invalid_token',
            'reconecte',
        ];
        foreach ($accountPatterns as $pattern) {
            if (stripos($error, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListClientProducts::route('/'),
            'create' => Pages\CreateClientProduct::route('/create'),
            // Página de edição separada removida — edição agora é via SlideOver inline
        ];
    }
}
