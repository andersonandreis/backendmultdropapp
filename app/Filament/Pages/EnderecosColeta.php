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

class EnderecosColeta extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';
    protected static ?string $navigationGroup = 'Integrações';
    protected static ?string $navigationLabel = 'Endereços de Coleta';
    protected static ?string $title = 'Endereços de Coleta por Marketplace';
    protected static ?string $slug = 'enderecos-coleta';
    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.enderecos-coleta';

    public ?array $data = [];

    private function getSetting(string $key, string $default = ''): string
    {
        return Setting::where('key', $key)->value('value') ?? $default;
    }

    private function setSetting(string $key, ?string $value, string $group): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value, 'group' => $group]);
    }

    public function mount(): void
    {
        $this->form->fill([
            'ml_cep'       => $this->getSetting('coleta_ml_cep'),
            'ml_endereco'  => $this->getSetting('coleta_ml_endereco'),
            'shopee_cep'   => $this->getSetting('coleta_shopee_cep'),
            'shopee_end'   => $this->getSetting('coleta_shopee_endereco'),
            'magalu_cep'   => $this->getSetting('coleta_magalu_cep'),
            'magalu_end'   => $this->getSetting('coleta_magalu_endereco'),
            'amazon_cep'   => $this->getSetting('coleta_amazon_cep'),
            'amazon_end'   => $this->getSetting('coleta_amazon_endereco'),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('MercadoLivre')
                    ->icon('heroicon-o-shopping-bag')
                    ->columns(2)
                    ->schema([
                        TextInput::make('ml_cep')->label('CEP')->mask('99999-999'),
                        TextInput::make('ml_endereco')->label('Endereço Completo'),
                    ]),

                Section::make('Shopee')
                    ->icon('heroicon-o-fire')
                    ->columns(2)
                    ->schema([
                        TextInput::make('shopee_cep')->label('CEP')->mask('99999-999'),
                        TextInput::make('shopee_end')->label('Endereço Completo'),
                    ]),

                Section::make('Magazine Luiza')
                    ->icon('heroicon-o-tag')
                    ->columns(2)
                    ->schema([
                        TextInput::make('magalu_cep')->label('CEP')->mask('99999-999'),
                        TextInput::make('magalu_end')->label('Endereço Completo'),
                    ]),

                Section::make('Amazon')
                    ->icon('heroicon-o-globe-alt')
                    ->columns(2)
                    ->schema([
                        TextInput::make('amazon_cep')->label('CEP')->mask('99999-999'),
                        TextInput::make('amazon_end')->label('Endereço Completo'),
                    ]),
            ])
            ->statePath('data');
    }

    public function salvar(): void
    {
        $this->form->validate();

        $map = [
            'coleta_ml_cep'           => 'ml_cep',
            'coleta_ml_endereco'      => 'ml_endereco',
            'coleta_shopee_cep'       => 'shopee_cep',
            'coleta_shopee_endereco'  => 'shopee_end',
            'coleta_magalu_cep'       => 'magalu_cep',
            'coleta_magalu_endereco'  => 'magalu_end',
            'coleta_amazon_cep'       => 'amazon_cep',
            'coleta_amazon_endereco'  => 'amazon_end',
        ];

        foreach ($map as $key => $dataKey) {
            $this->setSetting($key, $this->data[$dataKey] ?? null, 'coleta');
        }

        Notification::make()->title('Endereços de coleta salvos!')->success()->send();
    }

    protected function getFormActions(): array
    {
        return [
            \Filament\Actions\Action::make('salvar')
                ->label('Salvar Endereços')
                ->submit('salvar'),
        ];
    }
}
