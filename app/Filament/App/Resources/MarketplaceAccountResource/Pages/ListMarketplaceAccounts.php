<?php

namespace App\Filament\App\Resources\MarketplaceAccountResource\Pages;

use App\Filament\App\Resources\MarketplaceAccountResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMarketplaceAccounts extends ListRecords
{
    protected static string $resource = MarketplaceAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('connect')
                ->label('Nova Integracao OAuth')
                ->icon('heroicon-o-plus')
                ->form([
                    \Filament\Forms\Components\Select::make('platform')
                        ->label('Plataforma')
                        ->options(function () {
                            $options = [
                                'mercadolivre' => 'Mercado Livre',
                                'shopee' => 'Shopee',
                                'shopify' => 'Shopify',
                                'bling' => 'Bling ERP',
                                'magalu' => 'Magazine Luiza',
                                'amazon' => 'Amazon',
                                'tiktok' => 'TikTok Shop',
                            ];

                            $isSimulatorEnabled = \App\Models\Setting::where('key', 'hubaisimulator_enabled')->value('value');
                            if ($isSimulatorEnabled !== 'false' && $isSimulatorEnabled !== '0') {
                                $options['hubaisimulator'] = 'Simulador HubAI (Teste)';
                            }

                            return $options;
                        })
                        ->required()->live(),
                    \Filament\Forms\Components\TextInput::make('account_name')
                        ->label('Nome da Loja/Conexao')
                        ->placeholder('Ex: Minha Loja principal')
                        ->required()
                        ->helperText('Um nome para voce identificar essa conta no painel.'),
                    \Filament\Forms\Components\TextInput::make('shop_domain')
                        ->label('Dominio da Loja Shopify')
                        ->placeholder('Ex: minhaloja.myshopify.com')
                        ->helperText('Apenas para Shopify. Informe o dominio .myshopify.com da loja.')
                        ->visible(fn (callable $get) => $get('platform') === 'shopify'),
                    \Filament\Forms\Components\Select::make('supplier_id')
                        ->label('Roteamento de Pedidos (Qual Fornecedor atende?)')
                        ->options(function () {
                            $user = auth()->user();
                            $client = $user->client ?? null;
                            if (!$client) return [];

                            $planSupplierIds = [];
                            $activeSubscription = \App\Models\Subscription::where('client_id', $client->id)
                                ->where(function ($q) {
                                    $q->where('status', 'active')->orWhere('status', 'trialing');
                                })
                                ->with('plan')
                                ->latest('created_at')
                                ->first();

                            if ($activeSubscription && $activeSubscription->plan) {
                                $planSupplierIds = $activeSubscription->plan->suppliers()->pluck('suppliers.id')->toArray();
                            }

                            return \App\Models\Supplier::where('is_active', true)
                                ->where(function ($query) use ($planSupplierIds, $client) {
                                    $query->whereIn('id', $planSupplierIds)
                                        ->orWhere(function ($q) use ($client) {
                                            $q->where('owner_client_id', $client->id)->where('is_private', true);
                                        });
                                })
                                ->pluck('company_name', 'id');
                        })
                        ->required()
                        ->helperText('Todos os pedidos que entrarem por essa conta integrada serao faturados pelo fornecedor selecionado.'),
                ])
                ->action(function (array $data) {
                    $platform    = $data['platform'];
                    $supplierId  = $data['supplier_id'];
                    $accountName = $data['account_name'];

                    $user   = auth()->user();
                    $client = $user->client ?? null;

                    if (!$client) {
                        \Filament\Notifications\Notification::make()
                            ->title('Lojista nao encontrado para este usuario.')
                            ->danger()
                            ->send();
                        return;
                    }

                    // Verificar limite de conexoes do plano
                    $existingCount = \App\Models\MarketplaceAccount::where('client_id', $client->id)->count();
                    $activeSubscription = \App\Models\Subscription::where('client_id', $client->id)
                        ->where('status', 'active')
                        ->with('plan')
                        ->latest()
                        ->first();
                    $maxConnections = $activeSubscription?->plan?->max_marketplace_connections;

                    if ($maxConnections !== null && $existingCount >= $maxConnections) {
                        \Filament\Notifications\Notification::make()
                            ->title('Limite de conexoes atingido')
                            ->body("Seu plano permite {$maxConnections} conexoes. Faca upgrade para conectar mais marketplaces.")
                            ->warning()
                            ->send();
                        return;
                    }

                    // FOR-039: se ja existe conta ativa com supplier_id=null (legado pre-supplier_id obrigatorio),
                    // atualizar o supplier_id em vez de criar pending orfao.
                    $legacyActive = \App\Models\MarketplaceAccount::where('client_id', $client->id)
                        ->where('platform', $platform)
                        ->whereIn('status', ['active', 'needs_reauth'])
                        ->whereNull('supplier_id')
                        ->first();

                    if ($legacyActive) {
                        $legacyActive->update(['supplier_id' => $supplierId, 'account_name' => $accountName]);
                        $account = $legacyActive;
                    } else {
                        // Criar conta com status pending ANTES do redirect
                        $account = \App\Models\MarketplaceAccount::firstOrCreate(
                            [
                                'client_id'    => $client->id,
                                'supplier_id'  => $supplierId,
                                'platform'     => $platform,
                            ],
                            ['status' => 'pending', 'account_name' => $accountName]
                        );
                    }

                    // Redirecionar via OAuthController unificado (funciona pra todos: ML, Bling, Shopee, etc)
                    $shopDomain = $data['shop_domain'] ?? '';
                    $shopDomainParam = $shopDomain ? '&shop_domain=' . urlencode($shopDomain) : '';
                    // return_url = dominio atual do seller (suporta whitelabels)
                    $returnUrl = rtrim(config('app.frontend_url', url('/')), '/') . '/integracoes';
                    // NOV-077: source_system pra Bling OAuth relay centralizado em api.hubai.io
                    $sourceSystemParam = $platform === 'bling' ? '&source_system=' . config('bling.app_tenant', 'hubai') : '';
                    return redirect("/api/oauth/{$platform}/redirect?client_id={$client->id}&supplier_id={$supplierId}&account_name=" . urlencode($accountName) . $shopDomainParam . $sourceSystemParam . "&return_url=" . urlencode($returnUrl));
                }),
        ];
    }
}
