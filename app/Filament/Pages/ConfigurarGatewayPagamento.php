<?php

namespace App\Filament\Pages;

use App\Models\SupplierPaymentSetting;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class ConfigurarGatewayPagamento extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon  = 'heroicon-o-credit-card';
    protected static ?string $navigationGroup = 'Financeiro';
    protected static ?string $navigationLabel = 'Gateway de Pagamento';
    protected static ?string $title           = 'Configurar Gateway de Pagamento';
    protected static ?string $slug            = 'meu-gateway-pagamento';
    protected static ?int    $navigationSort  = 20;

    protected static string $view = 'filament.pages.configurar-gateway-pagamento';

    public ?array $data = [];

    // ------------------------------------------------------------------ acesso

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'supplier']);
    }

    // Oculta do menu para super_admin (tem o Resource completo)
    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->role === 'supplier';
    }

    // ------------------------------------------------------------------ mount

    public function mount(): void
    {
        $setting = $this->getOrCreateSetting();
        $extra   = $setting->api_extra ?? [];

        $this->form->fill([
            'gateway'              => $setting->gateway,
            'api_key'              => $setting->api_key,
            'api_secret'           => $setting->api_secret,
            'mp_public_key'        => $extra['public_key']         ?? null,
            'shipay_access_key'    => $extra['access_key']         ?? null,
            'pix_fee_type'         => $setting->pix_fee_type       ?? 'percentage',
            'pix_fee_value'        => $setting->pix_fee_value      ?? 0,
            'pix_timeout_minutes'  => $setting->pix_timeout_minutes ?? 30,
            'allows_wallet_topup'  => $setting->allows_wallet_topup  ?? true,
            'allows_wallet_payment'=> $setting->allows_wallet_payment ?? true,
            'pix_only_orders'      => $setting->pix_only_orders    ?? false,
            'refund_policy'        => $setting->refund_policy      ?? 'wallet_credit',
            'is_active'            => $setting->is_active          ?? true,
        ]);
    }

    // ------------------------------------------------------------------ form

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Gateway de Pagamento')
                    ->description('Escolha o gateway pelo qual seus clientes pagarao os pedidos.')
                    ->schema([
                        Select::make('gateway')
                            ->label('Gateway')
                            ->options([
                                'asaas'       => 'Asaas — PIX via API',
                                'shipay'      => 'Shipay — PIX com access_key',
                                'pagarme'     => 'Pagar.me — PIX + cartao',
                                'mercadopago' => 'Mercado Pago — PIX + cartao via MP',
                            ])
                            ->required()
                            ->live()
                            ->columnSpanFull(),

                        // Para Asaas, Pagar.me e Mercado Pago: api_key / access token
                        TextInput::make('api_key')
                            ->label(fn (Get $get) => match ($get('gateway')) {
                                'asaas'       => 'API Key (Asaas)',
                                'pagarme'     => 'Secret Key (Pagar.me)',
                                'mercadopago' => 'Access Token (Mercado Pago)',
                                default       => 'API Key / Token',
                            })
                            ->password()
                            ->revealable()
                            ->required()
                            ->helperText(fn (Get $get) => match ($get('gateway')) {
                                'asaas'       => 'Painel Asaas → Integracoes → API Key de producao.',
                                'pagarme'     => 'Dashboard Pagar.me → Configuracoes → Chaves de API → Secret Key.',
                                'mercadopago' => 'mercadopago.com.br → Seu negocio → Credenciais → Access Token de producao.',
                                default       => null,
                            })
                            ->visible(fn (Get $get) => in_array($get('gateway'), ['asaas', 'pagarme', 'mercadopago'])),

                        // Shipay: Client ID (usa campo api_key)
                        TextInput::make('shipay_client_id')
                            ->label('Client ID (Shipay)')
                            ->password()
                            ->revealable()
                            ->required()
                            ->helperText('Painel Shipay → Integracoes → Client ID.')
                            ->visible(fn (Get $get) => $get('gateway') === 'shipay'),

                        // Shipay: Client Secret
                        TextInput::make('api_secret')
                            ->label('Client Secret (Shipay)')
                            ->password()
                            ->revealable()
                            ->helperText('Painel Shipay → Integracoes → Client Secret.')
                            ->visible(fn (Get $get) => $get('gateway') === 'shipay'),

                        // Shipay: Access Key
                        TextInput::make('shipay_access_key')
                            ->label('Access Key (Shipay)')
                            ->password()
                            ->revealable()
                            ->helperText('Painel Shipay → Integracoes → Access Key.')
                            ->visible(fn (Get $get) => $get('gateway') === 'shipay'),

                        // Mercado Pago: Public Key
                        TextInput::make('mp_public_key')
                            ->label('Public Key (Mercado Pago)')
                            ->password()
                            ->revealable()
                            ->helperText('mercadopago.com.br → Credenciais → Public Key de producao. Necessaria para o checkout JS.')
                            ->visible(fn (Get $get) => $get('gateway') === 'mercadopago'),

                        Toggle::make('is_active')
                            ->label('Gateway ativo')
                            ->default(true),
                    ])
                    ->columns(2),

                Section::make('Taxas e Politica')
                    ->schema([
                        Select::make('pix_fee_type')
                            ->label('Tipo de Taxa PIX')
                            ->options([
                                'fixed'      => 'Fixo (R$)',
                                'percentage' => 'Percentual (%)',
                            ])
                            ->default('percentage')
                            ->live()
                            ->required(),

                        TextInput::make('pix_fee_value')
                            ->label('Valor da Taxa')
                            ->numeric()
                            ->default(0)
                            ->prefix(fn (Get $get) => $get('pix_fee_type') === 'fixed' ? 'R$' : null)
                            ->suffix(fn (Get $get) => $get('pix_fee_type') === 'percentage' ? '%' : null)
                            ->required(),

                        TextInput::make('pix_timeout_minutes')
                            ->label('Expiracao do PIX')
                            ->numeric()
                            ->default(30)
                            ->suffix('minutos')
                            ->required(),

                        Select::make('refund_policy')
                            ->label('Politica de reembolso')
                            ->options([
                                'wallet_credit'  => 'Creditar na wallet do cliente',
                                'manual_estorno' => 'Estorno manual pelo fornecedor',
                            ])
                            ->default('wallet_credit')
                            ->required(),

                        Toggle::make('allows_wallet_topup')
                            ->label('Permitir recarga de creditos')
                            ->default(true),

                        Toggle::make('allows_wallet_payment')
                            ->label('Permitir pagamento com saldo')
                            ->default(true),

                        Toggle::make('pix_only_orders')
                            ->label('Aceitar apenas PIX para pedidos')
                            ->default(false),
                    ])
                    ->columns(2),

                Section::make('Webhook')
                    ->description('Configure esta URL no painel do seu gateway para receber notificacoes de PIX.')
                    ->schema([
                        TextInput::make('webhook_info')
                            ->label('URL de Webhook')
                            ->disabled()
                            ->default(fn () => $this->getOrCreateSetting()->webhook_url ?? '(salve a configuracao para gerar)')
                            ->columnSpanFull(),
                    ]),
            ])
            ->statePath('data');
    }

    // ------------------------------------------------------------------ save

    public function salvar(): void
    {
        $data    = $this->form->getState();
        $setting = $this->getOrCreateSetting();

        // Constroi api_extra agrupando campos extras por gateway
        $apiExtra = $setting->api_extra ?? [];

        if ($data['gateway'] === 'shipay') {
            if (!empty($data['shipay_access_key'])) {
                $apiExtra['access_key'] = $data['shipay_access_key'];
            }
            // client_id vai para api_key
            if (!empty($data['shipay_client_id'])) {
                $data['api_key'] = $data['shipay_client_id'];
            }
        }

        if ($data['gateway'] === 'mercadopago') {
            if (!empty($data['mp_public_key'])) {
                $apiExtra['public_key'] = $data['mp_public_key'];
            }
        }

        $setting->fill([
            'gateway'              => $data['gateway'],
            'api_key'              => $data['api_key']              ?? $setting->api_key,
            'api_secret'           => $data['api_secret']           ?? $setting->api_secret,
            'api_extra'            => $apiExtra,
            'pix_fee_type'         => $data['pix_fee_type'],
            'pix_fee_value'        => $data['pix_fee_value'],
            'pix_timeout_minutes'  => $data['pix_timeout_minutes'],
            'allows_wallet_topup'  => $data['allows_wallet_topup'],
            'allows_wallet_payment'=> $data['allows_wallet_payment'],
            'pix_only_orders'      => $data['pix_only_orders'],
            'refund_policy'        => $data['refund_policy'],
            'is_active'            => $data['is_active'],
        ]);

        $setting->save();

        Notification::make()
            ->title('Gateway salvo com sucesso!')
            ->body('Suas configuracoes de pagamento foram atualizadas.')
            ->success()
            ->send();
    }

    // ------------------------------------------------------------------ testar

    public function testarConexao(): void
    {
        $setting = $this->getOrCreateSetting();

        if (!$setting->gateway) {
            Notification::make()
                ->title('Nenhum gateway configurado')
                ->warning()
                ->send();
            return;
        }

        try {
            $supplier = auth()->user()->supplier;
            $gateway  = \App\Services\Integrations\Factories\PaymentGatewayFactory::makeForSupplier($supplier);
            $gateway->getPaymentStatus('TEST_' . now()->timestamp);

            Notification::make()
                ->title('Conexao bem-sucedida!')
                ->body('O gateway respondeu com as credenciais salvas.')
                ->success()
                ->send();
        } catch (\Exception $e) {
            $msg             = $e->getMessage();
            $credentialError = !str_contains(strtolower($msg), 'not found')
                && !str_contains($msg, '404')
                && !str_contains(strtolower($msg), 'nao encontrado');

            if ($credentialError) {
                Notification::make()
                    ->title('Falha na conexao')
                    ->body("Erro: {$msg}")
                    ->danger()
                    ->send();
            } else {
                Notification::make()
                    ->title('Conexao bem-sucedida!')
                    ->body('Credenciais validas — o gateway respondeu corretamente.')
                    ->success()
                    ->send();
            }
        }
    }

    // ------------------------------------------------------------------ helper

    private function getOrCreateSetting(): SupplierPaymentSetting
    {
        $user     = auth()->user();
        $supplier = $user->supplier ?? null;

        if (!$supplier) {
            abort(403, 'Usuario nao esta vinculado a um fornecedor.');
        }

        return SupplierPaymentSetting::firstOrNew(
            ['supplier_id' => $supplier->id]
        );
    }
}
