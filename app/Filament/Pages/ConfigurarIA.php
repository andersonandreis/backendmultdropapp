<?php

namespace App\Filament\Pages;

use App\Models\SupplierAiSetting;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\KeyValue;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Pagina de Configuracoes de IA por WL (MUL-142-H).
 *
 * Acessivel para supplier e super_admin no painel /admin.
 * Permite configurar chave OpenAI, modelo, prompt base e prompts por marketplace.
 * A chave e write-only (nunca exibida de volta).
 */
class ConfigurarIA extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon  = 'heroicon-o-sparkles';
    protected static ?string $navigationGroup = 'Configuracoes';
    protected static ?string $navigationLabel = 'Inteligencia Artificial';
    protected static ?string $title           = 'Configuracoes de IA';
    protected static ?string $slug            = 'configurar-ia';
    protected static ?int    $navigationSort  = 30;

    protected static string $view = 'filament.pages.configurar-ia';

    public ?array $data = [];

    // ------------------------------------------------------------------ acesso

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'supplier']);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'supplier']);
    }

    // ------------------------------------------------------------------ mount

    public function mount(): void
    {
        $setting = $this->getSetting();

        $this->form->fill([
            'ai_enabled'                 => $setting->ai_enabled,
            'openai_api_key'             => '',  // write-only: nunca pre-preencher
            'openai_model'               => $setting->openai_model ?? 'gpt-4o-mini',
            'system_prompt_base'         => $setting->system_prompt_base,
            'system_prompts_marketplace' => $setting->system_prompts_marketplace ?? [],
        ]);
    }

    // ------------------------------------------------------------------ form

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Chave de API')
                    ->description('Sua chave da OpenAI. E criptografada e nunca e exibida ou compartilhada.')
                    ->schema([
                        Toggle::make('ai_enabled')
                            ->label('Ativar IA')
                            ->helperText('Habilita os botoes de geracao de conteudo e imagem para seus sellers.')
                            ->columnSpanFull(),

                        TextInput::make('openai_api_key')
                            ->label('Chave OpenAI (sk-...)')
                            ->password()
                            ->helperText('Deixe em branco para manter a chave atual. Preencha apenas para alterar.')
                            ->maxLength(200)
                            ->columnSpanFull(),

                        TextInput::make('openai_model')
                            ->label('Modelo')
                            ->helperText('Ex: gpt-4o-mini (recomendado), gpt-4o, gpt-3.5-turbo')
                            ->default('gpt-4o-mini')
                            ->maxLength(60),
                    ]),

                Section::make('Prompts de Geracao')
                    ->description('Instrucoes customizadas para a IA ao gerar titulos e descricoes. Deixe em branco para usar o padrao do sistema.')
                    ->schema([
                        Textarea::make('system_prompt_base')
                            ->label('Prompt Base')
                            ->helperText('Usado quando nao ha prompt especifico para o marketplace alvo.')
                            ->rows(4)
                            ->maxLength(2000)
                            ->columnSpanFull(),

                        KeyValue::make('system_prompts_marketplace')
                            ->label('Prompts por Marketplace')
                            ->helperText('Chave: ml | shopee | magalu | americanas | bling. Valor: instrucoes especificas para aquele canal.')
                            ->keyLabel('Marketplace')
                            ->valueLabel('Prompt')
                            ->columnSpanFull(),
                    ]),
            ])
            ->statePath('data');
    }

    // ------------------------------------------------------------------ acoes

    public function salvar(): void
    {
        $data    = $this->form->getState();
        $setting = $this->getSetting();

        $toSave = [
            'ai_enabled'                 => $data['ai_enabled'] ?? false,
            'openai_model'               => $data['openai_model'] ?? 'gpt-4o-mini',
            'system_prompt_base'         => $data['system_prompt_base'] ?? null,
            'system_prompts_marketplace' => $data['system_prompts_marketplace'] ?? null,
        ];

        // So atualiza a chave se foi informada (write-only)
        if (! empty(trim($data['openai_api_key'] ?? ''))) {
            $toSave['openai_api_key'] = trim($data['openai_api_key']);
        }

        $setting->fill($toSave)->save();

        Notification::make()
            ->title('Configuracoes salvas com sucesso!')
            ->success()
            ->send();

        // Limpa o campo de chave apos salvar
        $this->data['openai_api_key'] = '';
    }

    public function testarConexao(): void
    {
        $setting = $this->getSetting();
        $key     = $setting->openai_api_key;

        if (empty($key)) {
            Notification::make()
                ->title('Chave nao configurada')
                ->body('Salve uma chave OpenAI valida antes de testar a conexao.')
                ->warning()
                ->send();
            return;
        }

        try {
            $response = \Illuminate\Support\Facades\Http::withToken($key)
                ->timeout(15)
                ->get('https://api.openai.com/v1/models');

            if ($response->successful()) {
                $count = count($response->json('data') ?? []);
                Notification::make()
                    ->title("Conexao OK! {$count} modelos disponiveis.")
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title('Chave invalida ou sem permissao')
                    ->body('Status: ' . $response->status() . '. Verifique a chave e as permissoes.')
                    ->danger()
                    ->send();
            }
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Erro ao conectar com a OpenAI')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    // ------------------------------------------------------------------ helper

    protected function getSetting(): SupplierAiSetting
    {
        $user = auth()->user();

        // SEL-076: fallback duro pra id=1 quebrava por FK — no seller.global suppliers.id inicia em 26.
        // Resolucao: super_admin usa panel_supplier_id se existir, senao primeiro supplier real.
        if ($user->role === 'super_admin') {
            $supplierId = app()->has('panel_supplier_id')
                ? (int) app('panel_supplier_id')
                : (int) (\App\Models\Supplier::query()->orderBy('id')->value('id') ?? 0);
        } else {
            $supplierId = (int) ($user->supplier?->id ?? \App\Models\Supplier::query()->orderBy('id')->value('id') ?? 0);
        }

        if ($supplierId <= 0) {
            // Nao ha supplier nenhum — retorna instancia em memoria pra nao explodir a page.
            return new SupplierAiSetting([
                'supplier_id' => null,
                'ai_enabled'  => false,
                'openai_model' => 'gpt-4o-mini',
            ]);
        }

        return SupplierAiSetting::firstOrCreate(
            ['supplier_id' => $supplierId],
            [
                'ai_enabled'   => false,
                'openai_model' => 'gpt-4o-mini',
            ]
        );
    }
}
