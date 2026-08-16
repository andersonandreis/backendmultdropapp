<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupplierPaymentSettingResource\Pages;
use App\Models\Supplier;
use App\Models\SupplierPaymentSetting;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class SupplierPaymentSettingResource extends Resource
{
    protected static ?string $model = SupplierPaymentSetting::class;
    protected static ?string $slug = 'configuracoes-pagamento';
    protected static ?string $modelLabel = 'Configuração de Pagamento';
    protected static ?string $pluralModelLabel = 'Configurações de Pagamento';

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';
    protected static ?string $navigationGroup = 'Financeiro';
    protected static ?int $navigationSort = 10;

    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin']);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Gateway de Pagamento')
                    ->schema([
                        Forms\Components\Select::make('supplier_id')
                            ->label('Fornecedor')
                            ->relationship('supplier', 'company_name')
                            ->searchable()
                            ->preload()
                            ->required(),

                        Forms\Components\Select::make('gateway')
                            ->label('Gateway')
                            ->options([
                                'asaas'        => 'Asaas — Cobranças PIX via API',
                                'shipay'       => 'Shipay — PIX com access_key dedicada',
                                'pagarme'      => 'Pagar.me — Gateway completo PIX + cartão',
                                'mercadopago'  => 'Mercado Pago — PIX + cartão via MP',
                            ])
                            ->required()
                            ->live(),

                        Forms\Components\TextInput::make('api_key')
                            ->label(fn (Get $get) => match ($get('gateway')) {
                                'asaas'       => 'API Key (Asaas)',
                                'shipay'      => 'Client ID (Shipay)',
                                'pagarme'     => 'Secret Key (Pagar.me)',
                                'mercadopago' => 'Access Token (Mercado Pago)',
                                default       => 'API Key',
                            })
                            ->helperText(fn (Get $get) => match ($get('gateway')) {
                                'mercadopago' => 'Encontre em mercadopago.com.br → Seu negócio → Credenciais → Access Token de produção.',
                                default       => null,
                            })
                            ->password()
                            ->revealable()
                            ->required(),

                        Forms\Components\TextInput::make('api_secret')
                            ->label('Client Secret (Shipay)')
                            ->password()
                            ->revealable()
                            ->visible(fn (Get $get) => $get('gateway') === 'shipay'),

                        Forms\Components\KeyValue::make('api_extra')
                            ->label('Campos Extras (ex: access_key)')
                            ->keyLabel('Chave')
                            ->valueLabel('Valor')
                            ->visible(fn (Get $get) => $get('gateway') === 'shipay')
                            ->helperText('Para Shipay: adicione access_key aqui.'),

                        Forms\Components\TextInput::make('public_key')
                            ->label('Public Key (Mercado Pago)')
                            ->password()
                            ->revealable()
                            ->visible(fn (Get $get) => $get('gateway') === 'mercadopago')
                            ->helperText('Encontre em mercadopago.com.br → Credenciais → Public Key de produção. Necessária para o SDK JS.'),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Configuração ativa')
                            ->default(true),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Webhook')
                    ->schema([
                        Forms\Components\TextInput::make('webhook_url')
                            ->label('URL de Webhook')
                            ->disabled()
                            ->placeholder('Gerada automaticamente ao salvar — configure no painel do gateway')
                            ->helperText('Configure esta URL no painel do seu gateway de pagamento para receber notificações de PIX.'),

                        Forms\Components\TextInput::make('webhook_secret')
                            ->label('Webhook Secret')
                            ->disabled()
                            ->suffixAction(
                                Forms\Components\Actions\Action::make('regenerar_secret')
                                    ->label('Regenerar')
                                    ->icon('heroicon-o-arrow-path')
                                    ->color('warning')
                                    ->requiresConfirmation()
                                    ->modalHeading('Regenerar Webhook Secret')
                                    ->modalDescription('O secret atual será invalidado. Webhooks com o secret antigo não serão mais aceitos. Confirma?')
                                    ->action(function ($record) {
                                        if ($record) {
                                            $record->generateWebhookSecret();
                                            Notification::make()
                                                ->title('Webhook secret regenerado com sucesso!')
                                                ->success()
                                                ->send();
                                        }
                                    })
                            ),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Taxas e Wallet')
                    ->schema([
                        Forms\Components\Select::make('pix_fee_type')
                            ->label('Tipo de Taxa PIX')
                            ->options([
                                'fixed'      => 'Fixo (R$)',
                                'percentage' => 'Percentual (%)',
                            ])
                            ->default('percentage')
                            ->live()
                            ->required(),

                        Forms\Components\TextInput::make('pix_fee_value')
                            ->label('Valor da Taxa')
                            ->numeric()
                            ->default(0)
                            ->prefix(fn (Get $get) => $get('pix_fee_type') === 'fixed' ? 'R$' : null)
                            ->suffix(fn (Get $get) => $get('pix_fee_type') === 'percentage' ? '%' : null)
                            ->required(),

                        Forms\Components\Toggle::make('allows_wallet_topup')
                            ->label('Permitir recarga de créditos')
                            ->default(true),

                        Forms\Components\Toggle::make('allows_wallet_payment')
                            ->label('Permitir pagamento com saldo')
                            ->default(true),

                        Forms\Components\Toggle::make('pix_only_orders')
                            ->label('Aceitar apenas PIX para pedidos')
                            ->default(false),

                        Forms\Components\TextInput::make('pix_timeout_minutes')
                            ->label('Tempo de expiração do PIX')
                            ->numeric()
                            ->default(30)
                            ->suffix('minutos')
                            ->required(),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Política de Reembolso')
                    ->schema([
                        Forms\Components\Select::make('refund_policy')
                            ->label('Política de reembolso')
                            ->options([
                                'wallet_credit'  => 'Creditar na wallet do cliente',
                                'manual_estorno' => 'Estorno manual pelo fornecedor',
                            ])
                            ->default('wallet_credit')
                            ->required(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('supplier.company_name')
                    ->label('Fornecedor')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('gateway')
                    ->label('Gateway')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'asaas'       => 'info',
                        'shipay'      => 'warning',
                        'pagarme'     => 'success',
                        'mercadopago' => 'primary',
                        default       => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'mercadopago' => 'Mercado Pago',
                        'pagarme'     => 'Pagar.me',
                        default       => Str::upper($state),
                    }),

                Tables\Columns\TextColumn::make('pix_fee_value')
                    ->label('Taxa PIX')
                    ->formatStateUsing(function ($state, $record) {
                        if ($record->pix_fee_type === 'fixed') {
                            return 'R$ ' . number_format($state, 2, ',', '.');
                        }
                        return number_format($state, 2, ',', '.') . '%';
                    }),

                Tables\Columns\IconColumn::make('allows_wallet_topup')
                    ->label('Wallet')
                    ->boolean(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Ativo')
                    ->boolean(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Atualizado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('gateway')
                    ->label('Gateway')
                    ->options([
                        'asaas'       => 'Asaas',
                        'shipay'      => 'Shipay',
                        'pagarme'     => 'Pagar.me',
                        'mercadopago' => 'Mercado Pago',
                    ]),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status')
                    ->trueLabel('Apenas ativos')
                    ->falseLabel('Apenas inativos'),
            ])
            ->actions([
                Tables\Actions\Action::make('testar_conexao')
                    ->label('Testar Conexão')
                    ->icon('heroicon-o-signal')
                    ->color('info')
                    ->action(function (SupplierPaymentSetting $record) {
                        try {
                            // Teste simples: verifica se as credenciais são válidas
                            // tentando instanciar o gateway e checar autenticação
                            $factory = app(\App\Services\Integrations\Factories\PaymentGatewayFactory::class);
                            $gateway = \App\Services\Integrations\Factories\PaymentGatewayFactory::makeForSupplier($record->supplier);

                            // Tenta verificar status de um ID fictício (deve retornar erro de "não encontrado", não de credenciais)
                            $gateway->getPaymentStatus('TEST_CONNECTION_' . now()->timestamp);

                            Notification::make()
                                ->title('Conexão bem-sucedida!')
                                ->body("Gateway {$record->gateway} respondeu corretamente.")
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            $msg = $e->getMessage();
                            // "not found" ou "404" significa que as credenciais são válidas mas o ID não existe — OK
                            $isCredentialError = !str_contains(strtolower($msg), 'not found')
                                && !str_contains($msg, '404')
                                && !str_contains(strtolower($msg), 'nao encontrado');

                            if ($isCredentialError) {
                                Notification::make()
                                    ->title('Falha na conexão')
                                    ->body("Erro: {$msg}")
                                    ->danger()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('Conexão bem-sucedida!')
                                    ->body("Gateway {$record->gateway} respondeu (credenciais válidas).")
                                    ->success()
                                    ->send();
                            }
                        }
                    }),

                Tables\Actions\EditAction::make()->label('Editar'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('Excluir'),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSupplierPaymentSettings::route('/'),
            'create' => Pages\CreateSupplierPaymentSetting::route('/create'),
            'edit'   => Pages\EditSupplierPaymentSetting::route('/{record}/edit'),
        ];
    }
}
