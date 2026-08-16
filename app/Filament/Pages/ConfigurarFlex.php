<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use Filament\Pages\Page;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Placeholder;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;

class ConfigurarFlex extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-bolt';
    protected static ?string $navigationGroup = 'Configurações';
    protected static ?string $navigationLabel = 'Configurar Flex';
    protected static ?string $title = 'Configurar ML Flex';
    protected static ?string $slug = 'configurar-flex';
    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.configurar-flex';

    public ?array $data = [];

    private function getSetting(string $key, string $default = ''): string
    {
        return Setting::where('key', $key)->value('value') ?? $default;
    }

    private function setSetting(string $key, ?string $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value, 'group' => 'flex']);
    }

    public function mount(): void
    {
        $this->form->fill([
            'flex_taxa'            => $this->getSetting('flex_taxa', '20.00'),
            'flex_authorization_url' => $this->getSetting('flex_authorization_url'),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Configurações ML Flex')
                    ->description('Configure a taxa de entrega Flex e o link de autorização do MercadoLivre.')
                    ->schema([
                        TextInput::make('flex_taxa')
                            ->label('Taxa Flex (R$)')
                            ->numeric()
                            ->prefix('R$')
                            ->helperText('Valor cobrado por entrega Flex (padrão: R$ 20,00)')
                            ->required(),

                        TextInput::make('flex_authorization_url')
                            ->label('URL de Autorização ML Flex')
                            ->url()
                            ->placeholder('https://auth.mercadolivre.com.br/...')
                            ->helperText('Link gerado no painel do MercadoLivre para autorizar o Flex.'),

                        Placeholder::make('nota')
                            ->label('')
                            ->content(new HtmlString(
                                '<div class="rounded-lg bg-blue-50 border border-blue-200 p-3 text-sm text-blue-700">'
                                . '<strong>Como obter o link:</strong> Acesse o painel do MercadoLivre > Configurações > Flex > Copie o link de autorização.'
                                . '</div>'
                            )),
                    ]),
            ])
            ->statePath('data');
    }

    public function salvar(): void
    {
        $this->form->validate();

        $this->setSetting('flex_taxa',              $this->data['flex_taxa']);
        $this->setSetting('flex_authorization_url', $this->data['flex_authorization_url'] ?? null);

        Notification::make()->title('Configurações Flex salvas!')->success()->send();
    }

    protected function getFormActions(): array
    {
        return [
            \Filament\Actions\Action::make('salvar')
                ->label('Salvar')
                ->submit('salvar'),
        ];
    }
}
