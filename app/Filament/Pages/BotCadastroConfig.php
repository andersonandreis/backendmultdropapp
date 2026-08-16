<?php

namespace App\Filament\Pages;

use App\Models\AutoListingConfig;
use App\Models\AutoListingQueueItem;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class BotCadastroConfig extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';
    protected static ?string $navigationGroup = 'Automação';
    protected static ?string $navigationLabel = 'Bot Cadastro';
    protected static ?string $title = 'Bot de Cadastro Automático';
    protected static ?string $slug = 'bot-cadastro';
    protected static ?int $navigationSort = 1;
    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.bot-cadastro-config';

    public ?array $data = [];

    public function mount(): void
    {
        $config = AutoListingConfig::getDefault();
        $this->form->fill($config->toArray());
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Velocidade e Limites')
                    ->description('Controle a velocidade de cadastro automático')
                    ->schema([
                        TextInput::make('max_listings_per_hour')
                            ->label('Cadastros por hora')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(100)
                            ->required(),
                        TextInput::make('max_listings_per_day')
                            ->label('Cadastros por dia')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(1000)
                            ->required(),
                        TextInput::make('delay_between_listings_seconds')
                            ->label('Delay entre cadastros (segundos)')
                            ->numeric()
                            ->minValue(5)
                            ->maxValue(300)
                            ->required(),
                    ])->columns(3),

                Section::make('Horário de Funcionamento')
                    ->schema([
                        TimePicker::make('active_hours_start')
                            ->label('Início')
                            ->seconds(false)
                            ->required(),
                        TimePicker::make('active_hours_end')
                            ->label('Fim')
                            ->seconds(false)
                            ->required(),
                        CheckboxList::make('active_days')
                            ->label('Dias ativos')
                            ->options([
                                'mon' => 'Segunda',
                                'tue' => 'Terça',
                                'wed' => 'Quarta',
                                'thu' => 'Quinta',
                                'fri' => 'Sexta',
                                'sat' => 'Sábado',
                                'sun' => 'Domingo',
                            ])
                            ->columns(4)
                            ->columnSpanFull(),
                    ])->columns(2),

                Section::make('Inteligência Artificial')
                    ->schema([
                        Toggle::make('ai_enabled')
                            ->label('IA habilitada')
                            ->helperText('Gerar títulos e descrições automaticamente com IA'),
                        Toggle::make('ai_generate_title')
                            ->label('Gerar título')
                            ->visible(fn ($get) => $get('ai_enabled')),
                        Toggle::make('ai_generate_description')
                            ->label('Gerar descrição')
                            ->visible(fn ($get) => $get('ai_enabled')),
                        Select::make('ai_model')
                            ->label('Modelo IA')
                            ->options([
                                'gpt-4o-mini' => 'GPT-4o Mini (rápido, barato)',
                                'gpt-4o' => 'GPT-4o (melhor qualidade)',
                            ])
                            ->visible(fn ($get) => $get('ai_enabled')),
                        Textarea::make('ai_instructions')
                            ->label('Instruções padrão para IA')
                            ->helperText('Instruções globais aplicadas a todos os sellers. Ex: "Use linguagem informal, destaque durabilidade"')
                            ->rows(3)
                            ->columnSpanFull()
                            ->visible(fn ($get) => $get('ai_enabled')),
                    ])->columns(2),

                Section::make('Comportamento')
                    ->schema([
                        Toggle::make('auto_publish')
                            ->label('Publicar automaticamente')
                            ->helperText('Se desativado, produtos ficam como rascunho para revisão'),
                        Toggle::make('skip_existing')
                            ->label('Pular produtos já cadastrados'),
                        Select::make('status')
                            ->label('Status do bot')
                            ->options([
                                'active' => 'Ativo',
                                'paused' => 'Pausado',
                                'disabled' => 'Desabilitado',
                            ])
                            ->required(),
                    ])->columns(3),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $config = AutoListingConfig::getDefault();
        $config->update($data);

        Notification::make()
            ->title('Configuração salva!')
            ->success()
            ->send();
    }

    public function getStats(): array
    {
        return [
            'pending' => AutoListingQueueItem::where('status', 'pending')->count(),
            'processing' => AutoListingQueueItem::where('status', 'processing')->count(),
            'completed_today' => AutoListingQueueItem::where('status', 'completed')
                ->whereDate('completed_at', today())->count(),
            'failed_today' => AutoListingQueueItem::where('status', 'failed')
                ->whereDate('updated_at', today())->count(),
        ];
    }
    public function getRecentActivity(): array
    {
        return \DB::table('auto_listing_queue_items')
            ->orderByDesc('created_at')
            ->limit(15)
            ->get(['id', 'status', 'generated_title', 'client_product_id', 'error_message', 'attempts', 'created_at', 'completed_at'])
            ->map(fn($item) => (array)$item)
            ->toArray();
    }
}
