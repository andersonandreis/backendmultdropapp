<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use App\Models\Shipment;
use App\Models\Order;
use App\Models\Inventory;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Components\TextInput;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class ScanShipment extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-qr-code';
    protected static ?string $navigationGroup = 'Estoque & Remessas';
    protected static ?string $navigationLabel = 'Conferir Remessa';
    protected static ?string $title = 'Scanner de Armazém';
    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.scan-shipment';

    public ?array $data = [];

    /** Last scanned result for display in the view */
    public ?string $lastScanResult = null;
    public ?string $lastScanStatus = null; // 'success' | 'error' | 'warning'
    public ?array $lastScanOrderInfo = null;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('label_code')
                    ->label('Codigo de Barras / Etiqueta')
                    ->placeholder('Escaneie ou digite o codigo...')
                    ->autofocus()
                    ->required()
                    ->extraInputAttributes([
                        'x-on:keydown.enter' => '$wire.submit()',
                        'autocomplete' => 'off',
                    ]),
            ])
            ->statePath('data');
    }

    public function submit(): void
    {
        $code = trim($this->data['label_code'] ?? '');
        $this->lastScanResult = null;
        $this->lastScanStatus = null;
        $this->lastScanOrderInfo = null;

        if (empty($code)) {
            $this->lastScanResult = 'Codigo vazio.';
            $this->lastScanStatus = 'error';
            $this->dispatch('scan-feedback', status: 'error');
            return;
        }

        // Try to find an Order first (by order_number or tracking_number)
        $order = Order::with(['items.product', 'client'])
            ->where('order_number', $code)
            ->orWhere('tracking_number', $code)
            ->first();

        if ($order) {
            $result = $this->processOrderScan($order);
            $this->form->fill();
            return;
        }

        // Fallback: try legacy Shipment lookup
        $shipment = Shipment::where('tracking_number', $code)->first();

        if (!$shipment) {
            $this->lastScanResult = "Nenhum pedido ou remessa encontrado para: {$code}";
            $this->lastScanStatus = 'error';
            Notification::make()->title('Nao encontrado')->body("Codigo: {$code}")->danger()->send();
            $this->dispatch('scan-feedback', status: 'error');
            $this->form->fill();
            return;
        }

        if ($shipment->status === 'received') {
            $this->lastScanResult = "Remessa {$shipment->tracking_number} ja foi recebida.";
            $this->lastScanStatus = 'warning';
            Notification::make()->title('Remessa ja recebida')->warning()->send();
            $this->dispatch('scan-feedback', status: 'warning');
            $this->form->fill();
            return;
        }

        $shipment->update(['status' => 'received']);

        $this->lastScanResult = "Remessa {$shipment->tracking_number} recebida! Estoque atualizado.";
        $this->lastScanStatus = 'success';
        Notification::make()->title("Remessa {$shipment->tracking_number} recebida!")->success()->send();
        $this->dispatch('scan-feedback', status: 'success');
        $this->form->fill();
    }

    /**
     * Process order scan: progress order_processing_status through the workflow.
     * awaiting_dispatch -> separated -> shipped
     */
    protected function processOrderScan(Order $order): void
    {
        $currentStatus = $order->order_processing_status ?? 'awaiting_dispatch';

        // Determine next status in the progression
        $progression = [
            'awaiting_dispatch' => 'separated',
            'separating'        => 'separated',
            'separated'         => 'shipped',
        ];

        $nextStatus = $progression[$currentStatus] ?? null;

        if (!$nextStatus) {
            $this->lastScanResult = "Pedido #{$order->order_number} ja esta no status: {$currentStatus}. Nenhuma acao necessaria.";
            $this->lastScanStatus = 'warning';
            Notification::make()->title("Pedido ja processado")->body("Status atual: {$currentStatus}")->warning()->send();
            $this->dispatch('scan-feedback', status: 'warning');
            return;
        }

        $updateData = ['order_processing_status' => $nextStatus];
        if ($nextStatus === 'separated') {
            $updateData['separated_at'] = now();
        }
        if ($nextStatus === 'shipped') {
            $updateData['shipped_at'] = now();
        }

        $order->update($updateData);

        // Register beep
        \DB::table('order_beeps')->updateOrInsert(
            ['order_id' => $order->id],
            [
                'order_id'   => $order->id,
                'beeped_at'  => now(),
                'beeped_by'  => auth()->id(),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $statusLabels = [
            'separated' => 'Separado',
            'shipped'   => 'Enviado',
        ];

        $label = $statusLabels[$nextStatus] ?? $nextStatus;

        $this->lastScanResult = "Pedido #{$order->order_number} atualizado para: {$label}";
        $this->lastScanStatus = 'success';
        $this->lastScanOrderInfo = [
            'order_number' => $order->order_number,
            'client' => $order->client?->company_name ?? $order->client?->name ?? 'N/A',
            'previous_status' => $currentStatus,
            'new_status' => $nextStatus,
            'items_count' => $order->items->count(),
            'tracking' => $order->tracking_number ?? 'N/A',
        ];

        Notification::make()
            ->title("Pedido #{$order->order_number} -> {$label}")
            ->success()
            ->send();
        $this->dispatch('scan-feedback', status: 'success');

        // HUB-QZ 2026-07-17: dispara auto-print via QZ Tray conforme preferencia do usuario.
        // first_beep = separated / second_beep = shipped / both = sempre / disabled = pula.
        $trigger = auth()->user()?->qz_print_trigger ?? 'second_beep';
        $printer = auth()->user()?->default_printer_name;
        $labelUrl = $order->label_url;
        $shouldPrint = ($trigger === 'both')
            || ($trigger === 'first_beep' && $nextStatus === 'separated')
            || ($trigger === 'second_beep' && $nextStatus === 'shipped');
        if ($shouldPrint && $printer && $labelUrl) {
            $this->dispatch('qz-print-label', [
                'orderId'     => $order->id,
                'printer'     => $printer,
                'labelUrl'    => $labelUrl,
                'orderNumber' => $order->order_number,
            ]);
        }
    }
}
