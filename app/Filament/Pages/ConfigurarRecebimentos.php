<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use Filament\Pages\Page;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Section;
use Filament\Notifications\Notification;

class ConfigurarRecebimentos extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';
    protected static ?string $navigationGroup = 'Configurações';
    protected static ?string $navigationLabel = 'Configurar Recebimentos';
    protected static ?string $title = 'Configurar Recebimentos (Shipay)';
    protected static ?string $slug = 'configurar-recebimentos';
    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.configurar-recebimentos';

    public ?array $data = [];

    private function getSetting(string $key, string $default = ''): string
    {
        return Setting::where('key', $key)->value('value') ?? $default;
    }

    private function setSetting(string $key, ?string $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value, 'group' => 'shipay']);
    }

    public function mount(): void
    {
        $this->form->fill([
            'shipay_access_key'  => $this->getSetting('shipay_access_key'),
            'shipay_secret_key'  => $this->getSetting('shipay_secret_key'),
            'shipay_taxa_pix'    => $this->getSetting('shipay_taxa_pix', '0'),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Configurações Shipay (Pix)')
                    ->description('Integração com a plataforma de pagamentos Shipay para recebimento via Pix.')
                    ->schema([
                        TextInput::make('shipay_access_key')
                            ->label('Access Key')
                            ->required()
                            ->password()
                            ->revealable(),

                        TextInput::make('shipay_secret_key')
                            ->label('Secret Key')
                            ->required()
                            ->password()
                            ->revealable(),

                        TextInput::make('shipay_taxa_pix')
                            ->label('Taxa Pix (%)')
                            ->numeric()
                            ->suffix('%')
                            ->helperText('Percentual de taxa cobrada por transação Pix (ex: 0.99)'),
                    ]),
            ])
            ->statePath('data');
    }

    public function salvar(): void
    {
        $this->form->validate();

        $this->setSetting('shipay_access_key', $this->data['shipay_access_key']);
        $this->setSetting('shipay_secret_key', $this->data['shipay_secret_key']);
        $this->setSetting('shipay_taxa_pix',   $this->data['shipay_taxa_pix']);

        Notification::make()->title('Configurações Shipay salvas!')->success()->send();
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
