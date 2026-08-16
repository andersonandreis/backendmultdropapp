@php
    $steps = [
        ['label' => 'Venda', 'icon' => '🛍️', 'done_at' => $record->created_at, 'complete' => true],
        ['label' => 'Pagamento', 'icon' => '💸', 'done_at' => $record->paid_at, 'complete' => (bool) $record->paid_at],
        ['label' => 'Etiqueta', 'icon' => '🏷️', 'done_at' => $record->label_printed_at ?? ($record->label_url ? $record->updated_at : null), 'complete' => (bool) $record->label_url],
        ['label' => 'Separado', 'icon' => '📦', 'done_at' => $record->separated_at, 'complete' => in_array($record->order_processing_status, ['separated','packed','awaiting_dispatch','shipped','delivered'])],
        ['label' => 'Enviado', 'icon' => '🚚', 'done_at' => $record->shipped_at, 'complete' => in_array($record->order_processing_status, ['shipped','delivered'])],
        ['label' => 'Entregue', 'icon' => '✅', 'done_at' => $record->delivered_at, 'complete' => $record->order_processing_status === 'delivered'],
    ];
    $totalSteps = count($steps);
    $completedSteps = collect($steps)->filter(fn($s) => $s['complete'])->count();
    $progressPct = $totalSteps > 0 ? round(($completedSteps / $totalSteps) * 100) : 0;
    $isError = in_array($record->order_processing_status, ['payment_pending']) || in_array($record->status, ['cancelled','returned']);
    $statusText = match(true) {
        $record->order_processing_status === 'delivered' => 'ENTREGUE',
        $record->order_processing_status === 'shipped' => 'EM TRANSPORTE',
        $record->order_processing_status === 'payment_pending' => 'AGUARD. PAGAMENTO',
        $record->status === 'cancelled' => 'CANCELADO',
        $record->status === 'returned' => 'DEVOLVIDO',
        default => 'EM ANDAMENTO ' . $progressPct . '%',
    };
@endphp

<div style="border-radius:12px; padding:20px; margin-bottom:16px; background:var(--fi-body-bg, rgba(255,255,255,0.03)); border:1px solid color-mix(in srgb, currentColor 10%, transparent);">
    {{-- Header --}}
    <div class="flex items-center justify-between mb-4">
        <span style="font-weight:700; font-size:0.9rem; letter-spacing:0.05em; color:color-mix(in srgb, currentColor 60%, transparent);">ACOMPANHAMENTO DO PEDIDO</span>
        <span style="font-weight:700; font-size:0.8rem; padding:4px 12px; border-radius:20px;
            background:{{ $isError ? 'rgba(239,68,68,0.15)' : 'rgba(16,185,129,0.15)' }};
            color:{{ $isError ? '#dc2626' : '#059669' }};
            border:1px solid {{ $isError ? 'rgba(239,68,68,0.3)' : 'rgba(16,185,129,0.3)' }};">
            {{ $statusText }}
        </span>
    </div>

    {{-- Barra de progresso --}}
    <div style="border-radius:8px; height:6px; margin-bottom:20px; overflow:hidden; background:color-mix(in srgb, currentColor 10%, transparent);">
        <div style="width:{{ $progressPct }}%;" style="height:100%; border-radius:8px; background:linear-gradient(90deg,#6366f1,#8b5cf6); transition:width 0.5s ease;"></div>
    </div>

    {{-- Steps --}}
    <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:4px; overflow-x:auto; padding-bottom:4px;">
        @foreach($steps as $i => $step)
            @php
                $isComplete = $step['complete'];
                $dateStr = $step['done_at'] ? \Carbon\Carbon::parse($step['done_at'])->format('d/m H:i') : '--/--';
            @endphp
            <div style="display:flex; flex-direction:column; align-items:center; min-width:80px; text-align:center; position:relative;">
                @if($i < count($steps) - 1)
                    <div style="position:absolute; top:20px; height:2px; z-index:0; background:{{ $isComplete ? '#10b981' : 'color-mix(in srgb, currentColor 10%, transparent)' }};"
                         style="left:calc(50% + 20px); width:calc(100% - 40px);"></div>
                @endif

                <div style="width:40px; height:40px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:1rem; z-index:1; position:relative; box-shadow:0 1px 3px rgba(0,0,0,0.08);
                    background:{{ $isComplete ? '#10b981' : 'color-mix(in srgb, currentColor 5%, transparent)' }};
                    border:2px solid {{ $isComplete ? '#10b981' : 'color-mix(in srgb, currentColor 15%, transparent)' }};">
                    <span style="color:{{ $isComplete ? '#fff' : 'color-mix(in srgb, currentColor 30%, transparent)' }};">{{ $step['icon'] }}</span>
                </div>

                <span style="font-size:0.72rem; margin-top:6px; white-space:nowrap;
                    font-weight:{{ $isComplete ? '700' : '500' }};
                    color:{{ $isComplete ? '#059669' : 'color-mix(in srgb, currentColor 40%, transparent)' }};">
                    {{ $step['label'] }}
                </span>

                <span style="font-size:0.68rem; margin-top:2px;
                    color:{{ $isComplete ? 'color-mix(in srgb, currentColor 50%, transparent)' : 'color-mix(in srgb, currentColor 25%, transparent)' }};">
                    {{ $dateStr }}
                </span>
            </div>
        @endforeach
    </div>
</div>