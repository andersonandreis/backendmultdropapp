<x-filament-panels::page>
    {{-- Modo Monitor: textos GRANDES, alto contraste, para TV/Monitor de operacao --}}
    <div
        class="monitor-mode"
        wire:poll.{{ $this->autoRefreshSeconds }}s="$refresh"
        style="font-family: 'Inter', system-ui, sans-serif;"
    >
        <style>
            .monitor-mode { padding: 0.5rem; }
            .monitor-mode .stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.2rem; margin-bottom: 1.8rem; }
            .monitor-mode .stat-card { padding: 1.4rem 1.6rem; border-radius: 14px; border: 2px solid rgba(255,255,255,0.08); background: rgba(8,18,22,0.9); display: flex; flex-direction: column; gap: 0.4rem; }
            .monitor-mode .stat-label { font-size: 1rem; opacity: 0.62; text-transform: uppercase; letter-spacing: 0.08em; font-weight: 600; }
            .monitor-mode .stat-value { font-size: 3.5rem; font-weight: 900; line-height: 1; }
            .monitor-mode .stat-value.danger { color: #fca5a5; }
            .monitor-mode .stat-value.warning { color: #fbbf24; }
            .monitor-mode .stat-value.info { color: #67e8f9; }
            .monitor-mode .stat-value.success { color: #34d399; }

            .monitor-mode .fila-card { background: rgba(8,18,22,0.95); border: 2px solid rgba(255,255,255,0.08); border-radius: 14px; padding: 1.2rem 1.6rem; margin-bottom: 0.8rem; display: grid; grid-template-columns: 90px 1fr 220px 180px; gap: 1.4rem; align-items: center; }
            .monitor-mode .fila-card.atrasado { border-color: rgba(239,68,68,0.45); background: rgba(60,20,20,0.7); animation: pulse-red 2s infinite; }
            .monitor-mode .fila-card .ordem { font-size: 3.2rem; font-weight: 900; color: rgba(255,255,255,0.95); line-height: 1; text-align: center; }
            .monitor-mode .fila-card .lojista { font-size: 1.6rem; font-weight: 700; color: rgba(255,255,255,0.95); margin-bottom: 0.3rem; }
            .monitor-mode .fila-card .pedido-info { font-size: 1rem; opacity: 0.65; font-family: monospace; }
            .monitor-mode .fila-card .produtos { font-size: 1.1rem; opacity: 0.85; margin-top: 0.4rem; }
            .monitor-mode .fila-card .valor { font-size: 1.8rem; font-weight: 800; color: #34d399; text-align: right; }
            .monitor-mode .fila-card .tempo { font-size: 1.4rem; font-weight: 700; text-align: right; }
            .monitor-mode .fila-card .tempo.danger { color: #fca5a5; }
            .monitor-mode .fila-card .tempo.warning { color: #fbbf24; }
            .monitor-mode .fila-card .tempo.info { color: #cbd5e1; }

            @keyframes pulse-red {
                0%, 100% { box-shadow: 0 0 0 0 rgba(239,68,68,0.4); }
                50% { box-shadow: 0 0 0 8px rgba(239,68,68,0); }
            }

            .monitor-mode .fila-header { font-size: 1.5rem; font-weight: 800; margin-bottom: 1rem; display: flex; align-items: center; justify-content: space-between; }
            .monitor-mode .refresh-info { font-size: 0.85rem; opacity: 0.5; display: flex; align-items: center; gap: 0.3rem; }

            html:not(.dark) .monitor-mode .stat-card,
            html:not(.dark) .monitor-mode .fila-card { background: #fff; border-color: rgba(0,0,0,0.08); }
            html:not(.dark) .monitor-mode .stat-value { color: #0f172a; }
            html:not(.dark) .monitor-mode .fila-card .ordem,
            html:not(.dark) .monitor-mode .fila-card .lojista { color: #0f172a; }

            @media (max-width: 1024px) {
                .monitor-mode .stats-row { grid-template-columns: repeat(2, 1fr); }
                .monitor-mode .fila-card { grid-template-columns: 70px 1fr; gap: 1rem; }
                .monitor-mode .fila-card .valor, .monitor-mode .fila-card .tempo { grid-column: 1 / -1; text-align: left; }
            }
        </style>

        @php $stats = $this->getStatsAgora(); @endphp
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-label">Aguardando Separacao</div>
                <div class="stat-value warning">{{ number_format($stats['aguardando'], 0, ',', '.') }}</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Separados Hoje</div>
                <div class="stat-value info">{{ number_format($stats['separados_hoje'], 0, ',', '.') }}</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Enviados Hoje</div>
                <div class="stat-value success">{{ number_format($stats['enviados_hoje'], 0, ',', '.') }}</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">SLA Atrasados (>48h)</div>
                <div class="stat-value danger">{{ number_format($stats['atrasados'], 0, ',', '.') }}</div>
            </div>
        </div>

        <div class="fila-header">
            <span>Proximos Pedidos na Fila (Top 15 - mais antigos)</span>
            <span class="refresh-info">
                <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24"><path d="M12 4V1L8 5l4 4V6c3.31 0 6 2.69 6 6 0 1.01-.25 1.97-.7 2.8l1.46 1.46C19.54 15.03 20 13.57 20 12c0-4.42-3.58-8-8-8zm0 14c-3.31 0-6-2.69-6-6 0-1.01.25-1.97.7-2.8L5.24 7.74C4.46 8.97 4 10.43 4 12c0 4.42 3.58 8 8 8v3l4-4-4-4v3z"/></svg>
                atualiza a cada {{ $this->autoRefreshSeconds }}s
            </span>
        </div>

        @php $fila = $this->getFilaSeparacao(); @endphp
        @if($fila->isEmpty())
            <div class="fila-card" style="grid-template-columns: 1fr; justify-items: center; text-align: center; padding: 3rem;">
                <div style="font-size: 3rem; opacity: 0.5;">Sem pedidos na fila</div>
                <div style="font-size: 1.2rem; opacity: 0.6; margin-top: 0.5rem;">Tudo separado!</div>
            </div>
        @else
            @foreach($fila as $idx => $order)
                @php
                    $horasParado = $order->paid_at ? $order->paid_at->diffInHours(now()) : 0;
                    $atrasado = $horasParado >= 48;
                    $tempoClasse = $horasParado >= 48 ? 'danger' : ($horasParado >= 24 ? 'warning' : 'info');
                    $tempoLabel = $order->paid_at ? $order->paid_at->diffForHumans() : '-';
                    $totalItems = $order->items->sum('quantity');
                    $primeiroItem = $order->items->first();
                    $produtoResumo = $primeiroItem
                        ? $primeiroItem->name . ($order->items->count() > 1 ? ' + ' . ($order->items->count() - 1) . ' item(s)' : '')
                        : '-';
                @endphp
                <div class="fila-card {{ $atrasado ? 'atrasado' : '' }}">
                    <div class="ordem">#{{ $idx + 1 }}</div>
                    <div>
                        <div class="lojista">{{ $order->client?->company_name ?? 'Lojista sem nome' }}</div>
                        <div class="pedido-info">{{ $order->order_number }} - {{ strtoupper($order->source ?? '-') }} - {{ $totalItems }} item(s)</div>
                        <div class="produtos">{{ \Illuminate\Support\Str::limit($produtoResumo, 90) }}</div>
                    </div>
                    <div class="valor">R$ {{ number_format($order->total ?? 0, 2, ',', '.') }}</div>
                    <div class="tempo {{ $tempoClasse }}">{{ $tempoLabel }}</div>
                </div>
            @endforeach
        @endif
    </div>
</x-filament-panels::page>
