<?php

namespace App\Filament\Pages;

use App\Models\Shipment;
use Filament\Pages\Page;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;

class ReceberRemessas extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-inbox-arrow-down';
    protected static ?string $navigationGroup = 'Estoque & Remessas';
    protected static ?string $navigationLabel = 'Receber Remessas';
    protected static ?string $title = 'Receber Remessas — Scanner';
    protected static ?string $slug = 'receber-remessas';
    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.receber-remessas';

    public ?array $data = [];
    public ?Shipment $remessaEncontrada = null;
    public bool $confirmado = false;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('codigo')
                    ->label('Codigo de Barras / Tracking da Remessa')
                    ->autofocus()
                    ->required()
                    ->placeholder('Ex: BR123456789BR ou REM-001'),
            ])
            ->statePath('data');
    }

    public function scan(): void
    {
        $this->confirmado = false;
        $codigo = trim($this->data['codigo'] ?? '');

        if (empty($codigo)) {
            Notification::make()->title('Informe um código')->warning()->send();
            return;
        }

        $remessa = Shipment::with(['items'])
            ->where('tracking_number', $codigo)
            ->orWhere('id', is_numeric($codigo) ? (int) $codigo : -1)
            ->first();

        if (!$remessa) {
            $this->remessaEncontrada = null;
            Notification::make()->title('Remessa não encontrada')->body("Código: {$codigo}")->danger()->send();
            return;
        }

        $this->remessaEncontrada = $remessa;

        if ($remessa->status === 'received') {
            Notification::make()->title('Remessa já recebida')->warning()->send();
        }
    }

    public function confirmarRecebimento(): void
    {
        if (!$this->remessaEncontrada) {
            return;
        }

        $this->remessaEncontrada->update(['status' => 'received']);

        Notification::make()
            ->title('Remessa recebida!')
            ->body("Tracking: {$this->remessaEncontrada->tracking_number}")
            ->success()
            ->send();

        $this->confirmado = true;
        $this->remessaEncontrada = null;
        $this->form->fill();
    }
}
