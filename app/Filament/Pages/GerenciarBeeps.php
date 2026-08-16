<?php

namespace App\Filament\Pages;

use App\Models\Order;
use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class GerenciarBeeps extends Page implements HasTable, HasForms
{
    use InteractsWithTable;
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-check-badge';
    protected static ?string $navigationGroup = 'Pedidos & Logística';
    protected static ?string $navigationLabel = 'Conferencia de Pedidos';
    protected static ?string $title = 'Conferencia de Pedidos';
    protected static ?string $slug = 'gerenciar-beeps';
    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.gerenciar-beeps';

    public ?string $quickScanCode = '';
    public ?string $quickScanResult = null;
    public ?string $quickScanStatus = null;

    /**
     * Quick scan: lookup order and register beep + link tracking.
     */
    public function quickScan(): void
    {
        $code = trim($this->quickScanCode ?? '');
        $this->quickScanResult = null;
        $this->quickScanStatus = null;

        if (empty($code)) {
            $this->quickScanResult = 'Informe um codigo para buscar.';
            $this->quickScanStatus = 'error';
            $this->dispatch('scan-feedback', status: 'error');
            return;
        }

        $order = Order::where('order_number', $code)
            ->orWhere('tracking_number', $code)
            ->first();

        if (!$order) {
            $this->quickScanResult = "Nenhum pedido encontrado para: {$code}";
            $this->quickScanStatus = 'error';
            Notification::make()->title('Nao encontrado')->danger()->send();
            $this->dispatch('scan-feedback', status: 'error');
            $this->quickScanCode = '';
            return;
        }

        // Check if already beeped
        $alreadyBeeped = \DB::table('order_beeps')->where('order_id', $order->id)->exists();

        if ($alreadyBeeped) {
            $this->quickScanResult = "Pedido #{$order->order_number} ja foi conferido.";
            $this->quickScanStatus = 'warning';
            Notification::make()->title('Ja conferido')->warning()->send();
            $this->dispatch('scan-feedback', status: 'warning');
            $this->quickScanCode = '';
            return;
        }

        // Register beep
        \DB::table('order_beeps')->insert([
            'order_id'   => $order->id,
            'beeped_at'  => now(),
            'beeped_by'  => auth()->id(),
            'updated_at' => now(),
            'created_at' => now(),
        ]);

        // If scanned code looks like a tracking code and order has no tracking, link it
        if (
            $order->order_number !== $code &&
            empty($order->tracking_number)
        ) {
            $order->update(['tracking_number' => $code]);
            $this->quickScanResult = "Pedido #{$order->order_number} conferido e rastreio {$code} vinculado!";
        } else {
            $this->quickScanResult = "Pedido #{$order->order_number} conferido com sucesso!";
        }

        $this->quickScanStatus = 'success';
        Notification::make()->title("Pedido #{$order->order_number} conferido!")->success()->send();
        $this->dispatch('scan-feedback', status: 'success');
        $this->quickScanCode = '';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Order::query()
                    ->with(['items.product', 'client'])
                    ->whereIn('status', ['paid', 'preparing', 'shipped', 'delivered'])
                    ->latest()
            )
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Data')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('order_number')
                    ->label('Pedido')
                    ->searchable(),

                TextColumn::make('tracking_number')
                    ->label('Rastreio')
                    ->searchable()
                    ->placeholder('—')
                    ->copyable(),

                TextColumn::make('items_sku')
                    ->label('Produtos (SKU)')
                    ->html()
                    ->getStateUsing(function (Order $record): HtmlString {
                        $items = $record->items ?? collect();
                        if ($items->isEmpty()) {
                            return new HtmlString('<span class="text-gray-400 text-xs">—</span>');
                        }
                        $html = '<div class="text-xs">';
                        foreach ($items->take(3) as $item) {
                            $sku = htmlspecialchars($item->sku ?? 'N/A');
                            $qty = intval($item->quantity);
                            $html .= '<span class="inline-block bg-gray-100 text-gray-700 rounded px-1 mr-1 mb-1">' . $sku . ' x' . $qty . '</span>';
                        }
                        if ($items->count() > 3) {
                            $html .= '<span class="text-gray-400">+' . ($items->count() - 3) . ' mais</span>';
                        }
                        $html .= '</div>';
                        return new HtmlString($html);
                    }),

                TextColumn::make('beep_status')
                    ->label('Situação')
                    ->badge()
                    ->getStateUsing(function (Order $record): string {
                        $beeped = \DB::table('order_beeps')->where('order_id', $record->id)->exists();
                        return $beeped ? 'Conferido' : 'Pendente';
                    })
                    ->color(fn(string $state): string => match ($state) {
                        'Conferido' => 'success',
                        'Pendente'  => 'danger',
                        default     => 'gray',
                    }),

                TextColumn::make('order_processing_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn(?string $state) => match ($state) {
                        'separated'         => 'Separado',
                        'packed'            => 'Embalado',
                        'shipped'           => 'Enviado',
                        'awaiting_dispatch' => 'Pronto p/ Despacho',
                        'awaiting_label'    => 'Aguard. Etiqueta',
                        default             => $state ?? 'Novo',
                    })
                    ->color(fn(?string $state): string => match ($state) {
                        'separated', 'packed'   => 'primary',
                        'shipped'               => 'success',
                        'awaiting_dispatch'     => 'info',
                        default                 => 'gray',
                    }),
            ])
            ->filters([
                Filter::make('periodo')
                    ->label('Período')
                    ->form([
                        DatePicker::make('data_inicio')->label('De')->displayFormat('d/m/Y'),
                        DatePicker::make('data_fim')->label('Até')->displayFormat('d/m/Y'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['data_inicio'], fn($q, $val) => $q->whereDate('created_at', '>=', $val))
                            ->when($data['data_fim'],   fn($q, $val) => $q->whereDate('created_at', '<=', $val));
                    }),

                Filter::make('beepados')
                    ->label('Apenas Conferidos')
                    ->query(fn(Builder $q) => $q->whereExists(fn($sub) => $sub->from('order_beeps')->whereColumn('order_beeps.order_id', 'orders.id'))),

                Filter::make('nao_beepados')
                    ->label('Apenas Pendentes')
                    ->query(fn(Builder $q) => $q->whereNotExists(fn($sub) => $sub->from('order_beeps')->whereColumn('order_beeps.order_id', 'orders.id'))),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
