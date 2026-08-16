<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\MarketplaceAccountResource\Pages;
use App\Filament\App\Resources\MarketplaceAccountResource\RelationManagers;
use App\Models\MarketplaceAccount;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Jobs\SyncBlingJob;

class MarketplaceAccountResource extends Resource
{
    protected static ?string $model = MarketplaceAccount::class;

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';
    protected static ?int $navigationSort = 4;
    protected static ?string $navigationLabel = 'Minhas Lojas';
    protected static ?string $modelLabel = 'Conexão Marketplace';
    protected static ?string $slug = 'minhas-lojas';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user  = auth()->user();

        if (!$user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->role === 'super_admin') {
            return $query;
        }

        $clientId = $user->client?->id;
        if ($clientId) {
            return $query->where('client_id', $clientId);
        }

        return $query->whereRaw('1 = 0');
    }

    // Guard: bloqueia acesso quando user nao tem client registrado
    public static function canViewAny(): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }
        if ($user->role === 'super_admin') {
            return true;
        }
        if (! $user->client) {
            Notification::make()
                ->title('Conta nao configurada')
                ->body('Seu cadastro ainda nao esta ativo. Entre em contato com o suporte para ativar sua conta.')
                ->warning()
                ->persistent()
                ->send();
            return false;
        }
        return true;
    }

    // Bloqueia criacao sem client
    public static function canCreate(): bool
    {
        $user = auth()->user();
        if (! $user || (! $user->client && $user->role !== 'super_admin')) {
            return false;
        }
        return true;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // SEL-357: aviso LGPD para conta espelho readonly
                Forms\Components\Placeholder::make('mirror_warning')
                    ->label('')
                    ->content(function ($record): \Illuminate\Support\HtmlString {
                        $backend = $record ? htmlspecialchars($record->mirror_source_backend ?? '') : '';
                        return new \Illuminate\Support\HtmlString(
                            '<div style="background:#fef3c7;border:2px solid #f59e0b;border-radius:8px;padding:16px;color:#92400e;">'
                            . '<strong>ESPELHO READONLY (SEL-357)</strong><br>'
                            . 'Esta e uma conta espelho somente-leitura. '
                            . 'Quaisquer alteracoes feitas aqui NAO afetam a loja real em ' . $backend
                            . '. Dados de clientes sao mascarados (LGPD).'
                            . '</div>'
                        );
                    })
                    ->visible(fn ($record): bool => $record !== null && ($record->mirror_mode ?? 'active') === 'readonly'),

                Forms\Components\Tabs::make('Configurações da Loja')
                    ->tabs([
                        Forms\Components\Tabs\Tab::make('Conexão e Dados Básicos')
                            ->schema([
                                Forms\Components\TextInput::make('account_name')
                                    ->label('Nome da Conta (Apelido)')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('platform')
                                    ->label('Plataforma')
                                    ->disabled(),
                                Forms\Components\Select::make('supplier_id')
                                    ->label('Fornecedor')
                                    ->options(function () {
                                        // FOR-029: filtrar por tenant_supplier (plataforma), não por plan_supplier.
                                        // O plano define limites de SKU/features; a plataforma define quais
                                        // fornecedores ficam visíveis para os lojistas dela.
                                        $user   = auth()->user();
                                        $client = $user->client ?? null;
                                        if (!$client) return [];

                                        $tenant = \App\Models\Tenant::where('slug', config('bling.app_tenant', 'hubai'))->first();

                                        $tenantSupplierIds = null; // null = sem filtro (visibility=all)
                                        if ($tenant && $tenant->default_supplier_visibility === 'scoped') {
                                            $tenantSupplierIds = $tenant->suppliers()->pluck('suppliers.id')->toArray();
                                        }

                                        $query = \App\Models\Supplier::where('is_active', true);

                                        if ($tenantSupplierIds !== null) {
                                            $query->where(function ($q) use ($tenantSupplierIds, $client) {
                                                $q->whereIn('id', $tenantSupplierIds)
                                                  ->orWhere(function ($sub) use ($client) {
                                                      $sub->where('owner_client_id', $client->id)
                                                          ->where('is_private', true);
                                                  });
                                            });
                                        } else {
                                            $query->where(function ($q) use ($client) {
                                                $q->where('is_private', false)
                                                  ->orWhere(function ($sub) use ($client) {
                                                      $sub->where('owner_client_id', $client->id)
                                                          ->where('is_private', true);
                                                  });
                                            });
                                        }

                                        return $query->pluck('company_name', 'id');
                                    })
                                    ->searchable()
                                    ->required()
                                    ->disabled(fn (string $operation): bool => $operation === 'edit'),
                                Forms\Components\TextInput::make('status')
                                    ->label('Status OAuth')
                                    ->disabled(),
                                Forms\Components\Toggle::make('is_active')
                                    ->label('Ativo para Sincronizações')
                                    ->required()
                                    ->default(true),
                            ])->columns(2),

                        Forms\Components\Tabs\Tab::make('Estratégia de Preço')
                            ->schema([
                                Forms\Components\Select::make('pricing_strategy')
                                    ->label('Modo de Precificação')
                                    ->options([
                                        'fixed' => 'Preço Exato (Custo Seco)',
                                        'margin_percentage' => 'Margem de Lucro (%)',
                                        // 'margin_fixed' => 'Margem de Lucro (R$ Fixo)', 
                                    ])
                                    ->default('fixed')
                                    ->required()
                                    ->live(),

                                Forms\Components\TextInput::make('price_margin')
                                    ->label('Valor da Margem')
                                    ->numeric()
                                    ->default(0)
                                    ->prefix(fn(Forms\Get $get) => $get('pricing_strategy') === 'margin_percentage' ? '%' : 'R$')
                                    ->visible(fn(Forms\Get $get) => $get('pricing_strategy') !== 'fixed')
                                    ->required(fn(Forms\Get $get) => $get('pricing_strategy') !== 'fixed'),

                                Forms\Components\Fieldset::make('Custos e Taxas da Plataforma (Usados no Cálculo de Preço de Venda)')
                                    ->schema([
                                        Forms\Components\TextInput::make('tax_percentage')
                                            ->label('Imposto sobre Venda (Ex: Simples Nacional)')
                                            ->numeric()
                                            ->default(0)
                                            ->prefix('%'),
                                        Forms\Components\TextInput::make('marketplace_commission')
                                            ->label('Comissão do Marketplace')
                                            ->numeric()
                                            ->default(0)
                                            ->prefix('%'),
                                        Forms\Components\TextInput::make('marketplace_fixed_fee')
                                            ->label('Taxa Fixa por Venda')
                                            ->numeric()
                                            ->default(0)
                                            ->prefix('R$'),
                                        Forms\Components\TextInput::make('marketplace_shipping_fee')
                                            ->label('Custo de Frete Fixo Automático')
                                            ->numeric()
                                            ->default(0)
                                            ->prefix('R$'),
                                    ])->columns(2),
                            ])->columns(2),

                        Forms\Components\Tabs\Tab::make('Modo de Importação')
                            ->schema([
                                Forms\Components\Select::make('import_mode')
                                    ->label('Fluxo de Importação')
                                    ->options([
                                        'manual' => 'Revisão Manual (Os produtos vão para o catálogo local para ajuste antes do envio)',
                                        'automatic' => 'Sincronização Direta (Os produtos do fornecedor vão direto para a plataforma, via API)',
                                    ])
                                    ->default('manual')
                                    ->required(),
                            ])->columns(1),

                        Forms\Components\Tabs\Tab::make('Inteligência Artificial')
                            ->schema([
                                Forms\Components\Textarea::make('ai_instructions')
                                    ->label('Instruções Personalizadas para IA')
                                    ->helperText('Defina o tom, estilo e regras que a IA deve seguir ao gerar títulos e descrições dos seus anúncios. Ex: "Use linguagem informal, destaque durabilidade, evite palavras técnicas."')
                                    ->rows(4)
                                    ->columnSpanFull(),
                            ])->columns(1),
                    ])->columnSpanFull()
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->contentGrid([
                'md' => 2,
                'xl' => 3,
            ])
            ->columns([
                \Filament\Tables\Columns\Layout\Stack::make([
                    Tables\Columns\TextColumn::make('platform')
                        ->badge()
                        ->weight('bold')
                        ->formatStateUsing(fn(string $state): string => strtoupper($state)),

                    \Filament\Tables\Columns\Layout\Stack::make([
                        Tables\Columns\TextColumn::make('account_name')
                            ->size(\Filament\Tables\Columns\TextColumn\TextColumnSize::Large)
                            ->weight('bold')
                            ->searchable(),
                        Tables\Columns\TextColumn::make('supplier.company_name')
                            ->color('gray')
                            ->prefix('Fornecedor: '),
                    ])->space(1)->extraAttributes(['class' => 'mt-4']),

                    \Filament\Tables\Columns\Layout\Stack::make([
                        Tables\Columns\TextColumn::make('status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'active' => 'success',
                                'pending' => 'warning',
                                'needs_reauth' => 'warning',
                                'error' => 'danger',
                                'disconnected' => 'gray',
                                default => 'gray',
                            })
                            ->formatStateUsing(fn (string $state): string => match ($state) {
                                'active' => 'Conectada',
                                'pending' => 'Aguardando conexao',
                                'needs_reauth' => 'Reconectar necessario',
                                'error' => 'Erro na conexao',
                                'disconnected' => 'Desconectada',
                                default => $state,
                            }),
                        // SEL-357: badge espelho readonly LGPD
                        Tables\Columns\TextColumn::make('mirror_mode')
                            ->badge()
                            ->color('warning')
                            ->formatStateUsing(fn (?string $state): string => 'ESPELHO READONLY')
                            ->hidden(fn (?MarketplaceAccount $record): bool => $record === null || ($record->mirror_mode ?? 'active') !== 'readonly'),
                    ])->extraAttributes(['class' => 'mt-4 flex items-center justify-between']),
                ])->space(3)->extraAttributes(['class' => 'p-4 bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700']),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\Action::make('reconnect')
                    ->label('Tentar Conectar')
                    ->icon('heroicon-m-arrow-path')
                    ->color('warning')
                    ->visible(fn (MarketplaceAccount $record): bool => in_array($record->status, ['pending', 'error', 'disconnected', 'needs_reauth']))
                    ->url(function (MarketplaceAccount $record): string {
                        $clientId = auth()->user()->client?->id ?? $record->client_id;
                        $returnUrl = urlencode(rtrim(config('app.frontend_url', url('/')), '/') . '/integracoes');
                        // NOV-077: source_system pra Bling OAuth relay centralizado em api.hubai.io
                        $sourceSystemParam = $record->platform === 'bling' ? '&source_system=' . config('bling.app_tenant', 'hubai') : '';
                        return "/api/oauth/{$record->platform}/redirect?client_id={$clientId}&supplier_id={$record->supplier_id}&account_name=" . urlencode($record->account_name) . $sourceSystemParam . "&return_url={$returnUrl}";
                    }),
                Tables\Actions\Action::make('manage_catalog')
                    ->label('Gerenciar Catálogo desta Loja')
                    ->icon('heroicon-m-squares-plus')
                    ->color('primary')
                    ->visible(fn (MarketplaceAccount $record): bool => $record->status === 'active')
                    ->url(fn(MarketplaceAccount $record): string => ClientProductResource::getUrl('index') . '?store_id=' . $record->id),
                Tables\Actions\EditAction::make()->iconButton()->tooltip('Configuracoes da Conta'),
                Tables\Actions\Action::make('connect_bling')
                    ->label('Conectar Bling')
                    ->icon('heroicon-m-link')
                    ->color('success')
                    ->visible(fn (MarketplaceAccount $record): bool => $record->platform === 'bling' && !$record->bling_access_token)
                    ->url(function (MarketplaceAccount $record): string {
                        // NOV-077: usar OAuthController (relay) em vez do BlingController legado
                        $clientId = auth()->user()->client?->id ?? $record->client_id;
                        $returnUrl = urlencode(rtrim(config('app.frontend_url', url('/')), '/') . '/integracoes');
                        $sourceSystem = config('bling.app_tenant', 'hubai');
                        return "/api/oauth/bling/redirect?client_id={$clientId}&supplier_id={$record->supplier_id}&account_name=" . urlencode($record->account_name) . "&source_system={$sourceSystem}&return_url={$returnUrl}";
                    }),
                Tables\Actions\Action::make('sync_bling')
                    ->label('Sincronizar')
                    ->icon('heroicon-m-arrow-path')
                    ->color('info')
                    ->visible(fn (MarketplaceAccount $record): bool => $record->platform === 'bling' && (bool) $record->bling_access_token)
                    ->requiresConfirmation()
                    ->action(function (MarketplaceAccount $record) {
                        SyncBlingJob::dispatch($record->id, 'all');
                        \Filament\Notifications\Notification::make()->title('Sync iniciada!')->success()->send();
                    }),
                Tables\Actions\Action::make('push_stock_bling')
                    ->label('Enviar Estoque')
                    ->icon('heroicon-m-arrow-up-tray')
                    ->color('warning')
                    ->visible(fn (MarketplaceAccount $record): bool => $record->platform === 'bling' && (bool) $record->bling_access_token)
                    ->requiresConfirmation()
                    ->action(function (MarketplaceAccount $record) {
                        SyncBlingJob::dispatch($record->id, 'push_stock');
                        \Filament\Notifications\Notification::make()->title('Envio iniciado!')->success()->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
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
            'index' => Pages\ListMarketplaceAccounts::route('/'),
            'create' => Pages\CreateMarketplaceAccount::route('/create'),
            'edit' => Pages\EditMarketplaceAccount::route('/{record}/edit'),
        ];
    }
}
