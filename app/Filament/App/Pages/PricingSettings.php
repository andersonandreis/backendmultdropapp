<?php

namespace App\Filament\App\Pages;

use Filament\Pages\Page;
use App\Models\Client;
use App\Models\MarketplaceFee;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Card;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Auth;

class PricingSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';
    protected static ?int $navigationSort = 6;
    protected static ?string $navigationLabel = 'Estratégia de Preço';
    protected static ?string $title = 'Margem Global & Preço';

    protected static string $view = 'filament.app.pages.pricing-settings';

    /// Page State variables binding via Livewire ///
    public ?array $data = [];

    public function mount(): void
    {
        $client = Auth::user()->client;

        $this->form->fill([
            'listing_mode' => $client->listing_mode ?? 'manual',
            'default_profit_margin' => 30, // Mock for default configuration
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Card::make()->schema([
                    Select::make('listing_mode')
                        ->label('Modo de Importação de Produtos')
                        ->options([
                            'manual' => 'Manual (Aprovar um a um)',
                            'semi_auto' => 'Semi-Auto (Seleção em lote e Publicação)',
                            'full_auto' => 'Automático (100% Dropshipping Robô)',
                        ])
                        ->helperText('Define como o Catálogo do Fornecedor será importado para sua loja.'),

                    TextInput::make('default_profit_margin')
                        ->label('Margem de Lucro Padrão (%)')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(500)
                        ->helperText('Usada na precificação de importações automáticas e semi-automáticas.'),
                ])
            ])
            ->statePath('data');
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Salvar Configuração')
                ->submit('saveSettings'),
        ];
    }

    public function saveSettings(): void
    {
        $client = Auth::user()->client;
        $client->update([
            'listing_mode' => $this->data['listing_mode'],
        ]);

        // Save mock default margin to settings table/json eventually

        \Filament\Notifications\Notification::make()->title('Configuração salva!')->success()->send();
    }
}
