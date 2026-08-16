<?php

namespace App\Filament\Pages;

use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * HUB-QZ 2026-07-17 — Configuração de impressão automática via QZ Tray.
 * Fornecedor escolhe qual impressora usar e em qual bip disparar auto-print.
 */
class ImpressaoQz extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-printer';
    protected static ?string $navigationLabel = 'Impressão de Etiquetas';
    protected static ?string $navigationGroup = 'Configurações';
    protected static ?string $title = 'Impressão automática (QZ Tray)';
    protected static ?string $slug = 'impressao-qz';
    protected static ?int $navigationSort = 50;
    protected static string $view = 'filament.pages.impressao-qz';

    public ?array $data = [];
    public array $detectedPrinters = [];

    public function mount(): void
    {
        $u = auth()->user();
        $this->form->fill([
            'default_printer_name' => $u->default_printer_name,
            'qz_print_trigger'     => $u->qz_print_trigger ?? 'second_beep',
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('qz_print_trigger')
                    ->label('Quando imprimir a etiqueta')
                    ->options([
                        'disabled'    => 'Desabilitado (fluxo manual)',
                        'first_beep'  => 'No 1º bip (ao separar o pedido)',
                        'second_beep' => 'No 2º bip (ao marcar como enviado) — padrão',
                        'both'        => 'Em ambos os bips',
                    ])
                    ->required()
                    ->helperText('Segundo bip é o padrão — imprime só quando o pedido está pronto pra sair.'),

                TextInput::make('default_printer_name')
                    ->label('Impressora padrão (nome exato)')
                    ->placeholder('Ex: Zebra ZD220 · TSC TTP-244 Pro · POS-58')
                    ->helperText('Nome do QZ Tray. Clique em "Detectar impressoras" abaixo pra ver as disponíveis.')
                    ->maxLength(191),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $trigger = in_array($state['qz_print_trigger'] ?? '', ['disabled','first_beep','second_beep','both'], true)
            ? $state['qz_print_trigger']
            : 'second_beep';

        User::where('id', auth()->id())->update([
            'default_printer_name' => $state['default_printer_name'] ?: null,
            'qz_print_trigger'     => $trigger,
        ]);

        Notification::make()->title('Preferências salvas')->success()->send();
    }
}
