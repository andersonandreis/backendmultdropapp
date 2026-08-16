<?php

namespace App\Filament\Pages;

use App\Models\Order;
use Filament\Pages\Page;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;

class CodigoColeta extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass';
    protected static ?string $navigationGroup = 'Integrações';
    protected static ?string $navigationLabel = 'Código de Coleta';
    protected static ?string $title = 'Buscar Código de Coleta ML';
    protected static ?string $slug = 'codigo-coleta';
    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.codigo-coleta';

    public ?array $data = [];
    public ?Order $pedido = null;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('codigo')
                    ->label('Pedido / Shipment ID (MercadoLivre)')
                    ->placeholder('Ex: ORD-1234 ou 123456789')
                    ->required()
                    ->autofocus(),
            ])
            ->statePath('data');
    }

    public function buscar(): void
    {
        $codigo = trim($this->data['codigo'] ?? '');

        if (empty($codigo)) {
            Notification::make()->title('Informe um código')->warning()->send();
            return;
        }

        $pedido = Order::where('source', 'MercadoLivre')
            ->where(function ($q) use ($codigo) {
                $q->where('order_number', $codigo)
                  ->orWhere('external_order_id', $codigo)
                  ->orWhere('external_shipping_id', $codigo);
            })
            ->first();

        if (!$pedido) {
            $this->pedido = null;
            Notification::make()->title('Pedido ML não encontrado')->body("Código: {$codigo}")->danger()->send();
            return;
        }

        $this->pedido = $pedido;
    }

    public function getPedidoInfoHtml(): HtmlString
    {
        if (!$this->pedido) {
            return new HtmlString('');
        }

        $p = $this->pedido;

        $html = '<div class="rounded-xl border border-gray-200 bg-white p-5 mt-4 shadow-sm">';
        $html .= '<h3 class="font-bold text-gray-800 mb-3">Pedido #' . htmlspecialchars($p->order_number) . '</h3>';
        $html .= '<div class="grid grid-cols-2 gap-3 text-sm">';
        $html .= '<div><span class="text-gray-500">External Shipping ID:</span><br><strong>' . htmlspecialchars($p->external_shipping_id ?? '—') . '</strong></div>';
        $html .= '<div><span class="text-gray-500">External Order ID:</span><br><strong>' . htmlspecialchars($p->external_order_id ?? '—') . '</strong></div>';
        $html .= '<div><span class="text-gray-500">Rastreio:</span><br><strong>' . htmlspecialchars($p->tracking_number ?? '—') . '</strong></div>';
        $html .= '<div><span class="text-gray-500">Status:</span><br><strong>' . htmlspecialchars($p->order_processing_status ?? '—') . '</strong></div>';
        $html .= '</div>';
        $html .= '</div>';

        return new HtmlString($html);
    }
}
