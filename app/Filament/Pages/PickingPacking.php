<?php

namespace App\Filament\Pages;

use App\Models\Order;
use App\Models\ProductMedia;
use App\Models\OrderItem;
use Filament\Pages\Page;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;

class PickingPacking extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cube';
    protected static ?string $navigationGroup = 'Pedidos & Logística';
    protected static ?string $navigationLabel = 'Separacao de Pedidos';
    protected static ?string $title = 'Separacao de Pedidos';
    protected static ?string $slug = 'picking-packing';
    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.picking-packing';

    public ?array $data = [];
    public ?Order $foundOrder = null;
    public bool $confirmed = false;
    public ?string $lastAction = null; // Track last action for feedback

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('scan_code')
                    ->label('Bipe: Produto (EAN / GTIN / SKU) ou Pedido (ID / Rastreio / NF)')
                    ->placeholder('Ex: 7891234567890, SKU-001, MUL-260818-AE99...')
                    ->autofocus()
                    ->required(),
            ])
            ->statePath('data');
    }

    public function search(): void
    {
        $this->confirmed = false;
        $code = trim($this->data['scan_code'] ?? '');

        if (empty($code)) {
            Notification::make()->title('Informe um código para buscar')->warning()->send();
            return;
        }

        // MUL-426: com um pedido aberto na tela, o bip pertence ao fluxo DELE -- e o
        // separador conferindo os produtos daquele pedido, um a um. So volta para a
        // busca normal se o codigo for de outro pedido (troca deliberada).
        if ($this->foundOrder && $this->bipDoPedidoAberto($code)) {
            $this->form->fill();
            return;
        }

        // MUL-378: o bip procura PRIMEIRO entre os pedidos que de fato esperam separacao
        // (pago + etiqueta + nao enviado — Order::scopeReadyToShip). Antes era um first()
        // solto: aceitava calado pedido cancelado, ja enviado ou sem etiqueta. Pior, os
        // ORs nao estavam agrupados e order_number COLIDE entre vendas distintas, entao
        // em colisao vinha um pedido qualquer — e o confirm() marcava esse como separado.
        // MUL-426: o SKU saiu daqui. Bip de PRODUTO nao e "ache o pedido com esse
        // codigo" e sim "traga o proximo pedido da fila que precisa desse produto" --
        // tratado abaixo, com prioridade de fila. Aqui ficam so os codigos que
        // identificam UM pedido especifico.
        $porCodigo = function ($w) use ($code) {
            $w->where('order_number', $code)
              ->orWhere('tracking_number', $code)
              ->orWhere('invoice_number', $code);
        };
        $comRelacoes = fn () => Order::with(["items.product.media", "client"]);

        $candidatos  = $comRelacoes()->readyToShip()->where($porCodigo)->orderByDesc('id')->get();
        $foraDoFluxo = false;

        if ($candidatos->isEmpty()) {
            // MUL-426: nao e codigo de pedido -- tenta como codigo de produto.
            if ($this->abreProximoPedidoDoProduto($code)) {
                return;
            }

            $candidatos  = $comRelacoes()->where($porCodigo)->orderByDesc('id')->get();
            $foraDoFluxo = $candidatos->isNotEmpty();
        }

        if ($candidatos->isEmpty()) {
            $this->foundOrder = null;
            Notification::make()
                ->title('Nada encontrado')
                ->body("Nenhum pedido com o codigo {$code}, e nenhum pedido na fila de separacao com esse produto (EAN/GTIN/SKU).")
                ->danger()
                ->send();
            $this->dispatch('scan-feedback', status: 'error');
            return;
        }

        $order = $candidatos->first();

        if ($candidatos->count() > 1) {
            Notification::make()
                ->title('Atencao: ' . $candidatos->count() . ' pedidos com esse codigo')
                ->body("Mostrando o mais recente (#{$order->order_number}, id {$order->id}). Numero de pedido se repete entre vendas diferentes — confira antes de bipar.")
                ->warning()
                ->persistent()
                ->send();
        }

        // Nao recusa (o volume pode estar na mao do separador), mas diz o motivo na cara.
        if ($foraDoFluxo) {
            $motivos = [];
            if ($order->shipped_at) {
                $motivos[] = 'ja enviado em ' . $order->shipped_at->format('d/m/Y H:i');
            }
            if (in_array($order->canonical_status, ['cancelled', 'delivered', 'completed', 'shipped'], true)) {
                $motivos[] = "status {$order->canonical_status}";
            }
            if (empty($order->label_url)) {
                $motivos[] = 'sem etiqueta';
            }
            if (! $order->paid_at) {
                $motivos[] = 'sem pagamento confirmado no marketplace';
            }
            if ($order->is_draft) {
                $motivos[] = 'rascunho';
            }

            Notification::make()
                ->title('Pedido fora da fila de separacao')
                ->body("#{$order->order_number}: " . (empty($motivos) ? 'nao atende as condicoes de separacao' : implode(' · ', $motivos)) . '.')
                ->warning()
                ->persistent()
                ->send();
        }

        // MUL-226-08: pedido bloqueado alerta e não entra no fluxo de separação
        if ($order->blocked_at) {
            $this->foundOrder = null;
            $this->dispatch('scan-feedback', status: 'error');
            Notification::make()
                ->title('🔒 PEDIDO BLOQUEADO')
                ->body("Pedido #{$order->order_number} está bloqueado" . ($order->block_reason ? " — Motivo: {$order->block_reason}" : '') . '. Desbloqueie nos detalhes do pedido antes de separar.')
                ->danger()
                ->persistent()
                ->send();
            return;
        }

        $this->foundOrder = $order;
    }

    /**
     * MUL-426: variantes aceitaveis de um codigo de barras.
     *
     * O leitor manda exatamente o que esta impresso na embalagem, e o cadastro nem
     * sempre bate: o mesmo produto aparece como EAN-13 na caixa e GTIN-14 no ERP, e
     * planilha de importacao come zero a esquerda. Comparar so a string crua faz o
     * bip falhar num produto que ESTA cadastrado.
     */
    protected function variantesDoCodigo(string $code): array
    {
        $variantes = [$code];

        if (ctype_digit($code)) {
            $semZeros = ltrim($code, '0');
            if ($semZeros !== '' && $semZeros !== $code) {
                $variantes[] = $semZeros;
            }
            foreach ([13, 14] as $tamanho) {
                if (strlen($code) < $tamanho) {
                    $variantes[] = str_pad($code, $tamanho, '0', STR_PAD_LEFT);
                }
            }
        }

        return array_values(array_unique($variantes));
    }

    /**
     * MUL-426: o bip de produto casa por SKU, SKU de variacao, GTIN ou EAN.
     * Era so `sku` -- por isso bipar a etiqueta de barras do produto nao achava nada.
     */
    protected function itemCasaComCodigo(OrderItem $item, string $code): bool
    {
        $codigos = $this->variantesDoCodigo($code);

        $candidatos = [
            $item->sku,
            $item->variation_sku,
            $item->product?->gtin,
            $item->product?->ean,
        ];

        foreach ($candidatos as $valor) {
            $valor = trim((string) $valor);
            if ($valor !== '' && in_array($valor, $codigos, true)) {
                return true;
            }
        }

        return false;
    }

    /** MUL-426: mesmo casamento do itemCasaComCodigo, em forma de filtro de consulta. */
    protected function filtroPorProduto(string $code): \Closure
    {
        $codigos = $this->variantesDoCodigo($code);

        return function ($q) use ($codigos) {
            $q->whereHas('items', function ($i) use ($codigos) {
                $i->where(function ($w) use ($codigos) {
                    $w->whereIn('sku', $codigos)
                      ->orWhereIn('variation_sku', $codigos)
                      ->orWhereHas('product', fn ($p) => $p->whereIn('gtin', $codigos)->orWhereIn('ean', $codigos));
                });
            });
        };
    }

    /** Itens do pedido aberto que ainda nao foram bipados. */
    public function itensFaltando(): int
    {
        return $this->foundOrder
            ? $this->foundOrder->items->whereNull('scanned_at')->count()
            : 0;
    }

    /**
     * MUL-426: trata o bip quando ja existe pedido aberto na tela.
     *
     * Devolve true quando consumiu o bip (nao deve cair na busca normal).
     */
    protected function bipDoPedidoAberto(string $code): bool
    {
        $order = $this->foundOrder;
        $item  = $order->items->first(fn ($i) => $this->itemCasaComCodigo($i, $code));

        if ($item) {
            // Item novo: confere e informa o que falta.
            if (! $item->scanned_at) {
                $item->forceFill(['scanned_at' => now()])->save();
                $this->foundOrder = $order->fresh(['items.product.media', 'client']);

                $faltam = $this->itensFaltando();

                Notification::make()
                    ->title($faltam === 0
                        ? 'Pedido conferido por completo'
                        : 'Item conferido (' . intval($item->quantity) . ' un)')
                    ->body($faltam === 0
                        ? 'Bipe novamente para seguir o fluxo e imprimir a etiqueta.'
                        : "Faltam {$faltam} produto(s) deste pedido. Bipe o proximo.")
                    ->success()
                    ->send();

                $this->dispatch('scan-feedback', status: 'success');
                return true;
            }

            // MUL-426b: item ja conferido. Se o pedido inteiro ja esta conferido, ESTE bip
            // e o de fechamento -- e o gesto natural do separador num pedido de um produto
            // so: bipa para trazer o pedido, bipa de novo para fechar e imprimir. Antes
            // este caminho so avisava "ja conferido" e o fluxo nunca avancava.
            if ($this->itensFaltando() === 0) {
                $this->avancaFluxo();
                return true;
            }

            Notification::make()
                ->title('Item ja conferido')
                ->body(($item->product?->name ?? $item->sku) . ' ja foi bipado. Faltam ' . $this->itensFaltando() . ' produto(s) neste pedido.')
                ->warning()
                ->send();

            $this->dispatch('scan-feedback', status: 'success');
            return true;
        }

        // Tudo conferido e veio outro bip: e o bip de fechamento -- segue o fluxo normal
        // (separado -> aguardando envio), que ja imprime conforme a preferencia do usuario.
        if ($this->itensFaltando() === 0) {
            $this->avancaFluxo();
            return true;
        }

        // Codigo de OUTRO pedido: o separador trocou de pedido de proposito, deixa passar.
        $ehCodigoDePedido = Order::query()->where(function ($w) use ($code) {
            $w->where('order_number', $code)
              ->orWhere('tracking_number', $code)
              ->orWhere('invoice_number', $code);
        })->exists();

        if ($ehCodigoDePedido) {
            return false;
        }

        // Produto que nao pertence a este pedido, com itens ainda pendentes: nao troca de
        // pedido no meio da separacao -- isso e o erro que o conferente precisa ver.
        Notification::make()
            ->title('Produto nao pertence a este pedido')
            ->body("O codigo {$code} nao esta no pedido #{$order->order_number}, que ainda tem " . $this->itensFaltando() . ' produto(s) a conferir.')
            ->danger()
            ->send();
        $this->dispatch('scan-feedback', status: 'error');

        return true;
    }

    /**
     * MUL-426: bip de produto sem pedido aberto -- puxa o PROXIMO PRIORITARIO.
     *
     * Prioridade e a mesma da fila de separacao (MonitorSeparacao): paid_at ascendente,
     * o mais antigo primeiro, com o que ja esta em separacao na frente. O bip por SKU
     * antigo usava orderByDesc('id') -- o mais NOVO -- e fazia o separador atender na
     * ordem inversa da fila.
     */
    protected function abreProximoPedidoDoProduto(string $code): bool
    {
        $filtro = $this->filtroPorProduto($code);

        $order = Order::with(['items.product.media', 'client'])
            ->readyToShip()
            ->where($filtro)
            ->orderByRaw("CASE WHEN order_processing_status = 'separating' THEN 0 ELSE 1 END")
            ->orderBy('paid_at', 'asc')
            ->first();

        if (! $order) {
            return false;
        }

        if ($order->blocked_at) {
            $this->foundOrder = null;
            $this->dispatch('scan-feedback', status: 'error');
            Notification::make()
                ->title('🔒 PEDIDO BLOQUEADO')
                ->body("O proximo pedido com esse produto (#{$order->order_number}) esta bloqueado" . ($order->block_reason ? " — Motivo: {$order->block_reason}" : '') . '.')
                ->danger()
                ->persistent()
                ->send();
            return true;
        }

        $naFila = Order::query()->readyToShip()->where($filtro)->count();

        $item = $order->items->first(fn ($i) => $this->itemCasaComCodigo($i, $code));
        if ($item && ! $item->scanned_at) {
            $item->forceFill(['scanned_at' => now()])->save();
        }

        $this->foundOrder = $order->fresh(['items.product.media', 'client']);
        $faltam = $this->itensFaltando();

        Notification::make()
            ->title("Pedido #{$order->order_number} aberto")
            ->body($faltam === 0
                ? 'Produto unico, ja conferido. Bipe novamente para imprimir a etiqueta.'
                : "Produto conferido. Faltam {$faltam} produto(s) neste pedido -- bipe o proximo."
                . ($naFila > 1 ? " (mais " . ($naFila - 1) . " pedido(s) na fila com esse produto)" : ''))
            ->success()
            ->send();

        $this->dispatch('scan-feedback', status: 'success');
        $this->form->fill();

        return true;
    }

    /** MUL-426: bip de fechamento -- avanca uma etapa do fluxo que ja existia. */
    protected function avancaFluxo(): void
    {
        $status = $this->foundOrder->order_processing_status;

        if (in_array($status, ['separated', 'awaiting_shipment'], true)) {
            $this->despachar();
        } else {
            $this->separarPedido();
        }
    }

    /**
     * MUL-226-08: guard reutilizável — nenhuma ação de separação/despacho em pedido bloqueado.
     */
    protected function guardBlocked(): bool
    {
        if ($this->foundOrder && $this->foundOrder->blocked_at) {
            Notification::make()
                ->title('🔒 PEDIDO BLOQUEADO')
                ->body("Pedido #{$this->foundOrder->order_number} está bloqueado. Desbloqueie nos detalhes do pedido.")
                ->danger()
                ->send();
            return true;
        }
        return false;
    }

    public function confirm(): void
    {
        if (!$this->foundOrder || $this->guardBlocked()) {
            return;
        }

        $this->foundOrder->update([
            'order_processing_status' => 'separated',
            'separated_at' => now(),
        ]);

        // Registra o beep
        \DB::table('order_beeps')->updateOrInsert(
            ['order_id' => $this->foundOrder->id],
            [
                'order_id'   => $this->foundOrder->id,
                'beeped_at'  => now(),
                'beeped_by'  => auth()->id(),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        Notification::make()
            ->title('Pedido confirmado!')
            ->body("Pedido #{$this->foundOrder->order_number} marcado como Separado.")
            ->success()
            ->send();

        $this->confirmed = true;
        $this->lastAction = 'separated';
        $this->foundOrder = null;
        $this->form->fill();
    }

    /**
     * Mark order as 'separating' then immediately as 'separated'.
     */
    public function separarPedido(): void
    {
        if (!$this->foundOrder || $this->guardBlocked()) {
            return;
        }

        // HUB-QZ: guardar referencia antes de zerar $this->foundOrder no fim
        $order = $this->foundOrder;

        // First mark as separating
        $this->foundOrder->update([
            'order_processing_status' => 'separating',
        ]);

        // Then mark as separated
        $this->foundOrder->update([
            'order_processing_status' => 'separated',
            'separated_at' => now(),
        ]);

        // Register beep
        \DB::table('order_beeps')->updateOrInsert(
            ['order_id' => $this->foundOrder->id],
            [
                'order_id'   => $this->foundOrder->id,
                'beeped_at'  => now(),
                'beeped_by'  => auth()->id(),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        Notification::make()
            ->title('Pedido separado!')
            ->body("Pedido #{$this->foundOrder->order_number} marcado como Separado.")
            ->success()
            ->send();

        // HUB-QZ 2026-07-17: dispatch auto-print no PickingPacking::separarPedido (first_beep/both)
        $this->maybeDispatchQzPrint($order, 'separated');

        $this->confirmed = true;
        $this->lastAction = 'separated';
        $this->dispatch('scan-feedback', status: 'success');
        $this->foundOrder = null;
        $this->form->fill();
    }

    /**
     * Mark order as 'awaiting_shipment' (ready to dispatch).
     */
    public function despachar(): void
    {
        if (!$this->foundOrder || $this->guardBlocked()) {
            return;
        }

        // HUB-QZ: guardar referencia antes de zerar $this->foundOrder no fim
        $order = $this->foundOrder;

        $this->foundOrder->update([
            'order_processing_status' => 'awaiting_shipment',
        ]);

        Notification::make()
            ->title('Pedido pronto para despacho!')
            ->body("Pedido #{$this->foundOrder->order_number} marcado como Aguardando Envio.")
            ->success()
            ->send();

        // HUB-QZ 2026-07-17: dispatch auto-print no PickingPacking::despachar
        // Mapeia awaiting_shipment como 'shipped' (2o bip logico) pra bater com trigger config.
        $this->maybeDispatchQzPrint($order, 'shipped');

        $this->confirmed = true;
        $this->lastAction = 'awaiting_shipment';
        $this->dispatch('scan-feedback', status: 'success');
        $this->foundOrder = null;
        $this->form->fill();
    }

    /**
     * HUB-QZ 2026-07-17: helper compartilhado — respeita preferencia do usuario.
     * $stageForTrigger = 'separated' (first_beep) ou 'shipped' (second_beep).
     */
    protected function maybeDispatchQzPrint(\App\Models\Order $order, string $stageForTrigger): void
    {
        $u = auth()->user();
        if (! $u) return;

        $trigger  = $u->qz_print_trigger ?? 'second_beep';
        $printer  = $u->default_printer_name;
        $labelUrl = $order->label_url ?? null;

        $shouldPrint = ($trigger === 'both')
            || ($trigger === 'first_beep'  && $stageForTrigger === 'separated')
            || ($trigger === 'second_beep' && $stageForTrigger === 'shipped');

        if ($shouldPrint && $printer && $labelUrl) {
            $this->dispatch('qz-print-label', [
                'orderId'     => $order->id,
                'printer'     => $printer,
                'labelUrl'    => $labelUrl,
                'orderNumber' => $order->order_number,
            ]);
        }
    }

    /**
     * Returns the current workflow step label for display.
     */
    public function getWorkflowSteps(): array
    {
        $currentStatus = $this->foundOrder?->order_processing_status ?? '';

        $steps = [
            ['key' => 'awaiting_dispatch', 'label' => 'Aguard. Despacho'],
            ['key' => 'separating',        'label' => 'Separando'],
            ['key' => 'separated',         'label' => 'Separado'],
            ['key' => 'awaiting_shipment', 'label' => 'Aguard. Envio'],
            ['key' => 'shipped',           'label' => 'Enviado'],
        ];

        $currentIndex = collect($steps)->search(fn($s) => $s['key'] === $currentStatus);

        return collect($steps)->map(function ($step, $index) use ($currentIndex) {
            $step['state'] = 'pending';
            if ($currentIndex !== false) {
                if ($index < $currentIndex) {
                    $step['state'] = 'completed';
                } elseif ($index === $currentIndex) {
                    $step['state'] = 'current';
                }
            }
            return $step;
        })->toArray();
    }

    public function getOrderInfoHtml(): HtmlString
    {
        if (!$this->foundOrder) {
            return new HtmlString('');
        }

        $order = $this->foundOrder;
        $items = $order->items ?? collect();

        // NOV-140 Item 7: Cabecalho do pedido
        $html = '<div class="rounded-xl border border-gray-200 bg-white p-5 mt-4 shadow-sm">';
        $html .= '<div class="flex justify-between items-start mb-4">';
        $html .= '<div>';
        $html .= '<h3 class="text-lg font-bold text-gray-800">Pedido #' . htmlspecialchars($order->order_number) . '</h3>';
        $html .= '<p class="text-sm text-gray-500">Lojista: ' . htmlspecialchars($order->client?->company_name ?? 'N/A') . '</p>';
        $html .= '<p class="text-sm text-gray-500">Canal: ' . htmlspecialchars($order->source ?? 'N/A') . '</p>';
        if ($order->paid_at) {
            $html .= '<p class="text-xs text-green-600 font-medium">Pago em: ' . $order->paid_at->format('d/m/Y H:i') . '</p>';
        }
        $html .= '</div>';
        $html .= '<span class="text-xs px-2 py-1 rounded-full bg-blue-100 text-blue-700 font-medium">' . htmlspecialchars($order->order_processing_status ?? 'novo') . '</span>';
        $html .= '</div>';

        if ($items->isNotEmpty()) {
            $html .= '<div class="border-t border-gray-100 pt-3">';
            $html .= '<p class="text-xs font-semibold text-gray-400 uppercase mb-2">Produtos</p>';
            foreach ($items as $item) {
                // NOV-140 Item 7: Resolver imagem via ProductMedia (CDN) ou fallback UI-Avatars
                $mediaUrl = null;

                // Prioridade 1: product.media (eager loaded, is_cover=1 ou primeiro disponivel)
                if ($item->product && $item->product->media && $item->product->media->isNotEmpty()) {
                    $cover = $item->product->media->firstWhere('is_cover', true)
                        ?? $item->product->media->first();
                    $mediaUrl = $cover?->url;
                }

                // Prioridade 2: product_image do order_item (apenas se for CDN, nao legado goolhub.io)
                if (!$mediaUrl && !empty($item->product_image) && !str_contains($item->product_image, 'goolhub.io')) {
                    $mediaUrl = $item->product_image;
                }

                // Garante URL absoluta
                if ($mediaUrl && !str_starts_with($mediaUrl, 'http')) {
                    $mediaUrl = asset($mediaUrl);
                }

                $sku = $item->sku ?? $item->variation_sku ?? '?';
                $fallbackUrl = 'https://ui-avatars.com/api/?name=' . urlencode(substr($sku, 0, 2)) . '&background=1e293b&color=94a3b8&size=80';
                $imgSrc = $mediaUrl ?? $fallbackUrl;

                $html .= '<div class="flex items-center gap-3 py-2 border-b border-gray-50">';
                $html .= '<img src="' . htmlspecialchars($imgSrc) . '" alt="" '
                    . 'style="width:56px;height:56px;border-radius:8px;object-fit:cover;border:1px solid #e5e7eb;" '
                    . 'onerror="this.src=\'' . $fallbackUrl . '\'">';
                $html .= '<div class="flex-1">';
                $html .= '<p class="font-medium text-gray-800 text-sm">' . htmlspecialchars($item->product?->name ?? $item->product_name ?? 'Produto') . '</p>';
                $html .= '<p class="text-xs text-gray-400">SKU: ' . htmlspecialchars($sku) . ' | Qtd: ' . intval($item->quantity);
                // MUL-426: o codigo que o separador vai bipar, na frente dele
                $codigoBarras = trim((string) ($item->product?->gtin ?: $item->product?->ean ?: ''));
                if ($codigoBarras !== '') {
                    $html .= ' | Cod: ' . htmlspecialchars($codigoBarras);
                }
                $html .= '</p>';
                // MUL-426: estado da conferencia por item
                $html .= $item->scanned_at
                    ? '<span class="text-xs px-2 py-0.5 rounded-full bg-green-100 text-green-700 font-medium">conferido ' . $item->scanned_at->format('H:i') . '</span>'
                    : '<span class="text-xs px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 font-medium">aguardando bip</span>';
                if ($item->supplier_unit_cost) {
                    $html .= '<p class="text-xs text-gray-500">Custo unit.: R$ ' . number_format((float)$item->supplier_unit_cost, 2, ',', '.') . '</p>';
                }
                $html .= '</div>';
                $html .= '</div>';
            }
            $html .= '</div>';
        }

        $html .= '</div>';
        return new HtmlString($html);
    }
}
