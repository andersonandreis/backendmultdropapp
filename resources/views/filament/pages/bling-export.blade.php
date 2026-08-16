<x-filament-panels::page>
    {{-- MUL-226-01: banners de congelamento ativos --}}
    @if ($this->isCatalogFrozen())
        <div style="border-radius:12px;border:1px solid rgba(245,158,11,0.45);background:rgba(245,158,11,0.10);padding:12px 16px;display:flex;align-items:center;gap:10px;">
            <span style="font-size:1.1rem;">⏸️</span>
            <div>
                <div style="font-weight:700;color:#b45309;">Catálogo CONGELADO — nenhum produto está sendo exportado pro Bling</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">A importação de vendas dos sellers segue normal. Ao descongelar, os envios pulados são reenviados automaticamente.</div>
            </div>
        </div>
    @endif

    @if ($this->isOrdersQueueFrozen())
        <div style="border-radius:12px;border:1px solid rgba(148,163,184,0.45);background:rgba(148,163,184,0.10);padding:12px 16px;display:flex;align-items:center;gap:10px;">
            <span style="font-size:1.1rem;">⏸️</span>
            <div>
                <div style="font-weight:700;">Fila de pedidos/NF CONGELADA</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">Pedidos não estão sendo empurrados pro Bling — re-agendam de hora em hora até descongelar.</div>
            </div>
        </div>
    @endif

    {{-- MUL-226-02: cards por status — clicáveis, filtram a tabela abaixo --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
        @foreach ($this->getCardsData() as $card)
            @php($ativo = $card['key'] !== null && $this->statusCard === $card['key'])
            <div
                @if ($card['key'] !== null) wire:click="setStatusCard('{{ $card['key'] }}')" @endif
                style="border-radius:12px;padding:14px 16px;border:2px solid {{ $ativo ? $card['cor'] : 'rgba(148,163,184,0.25)' }};{{ $card['key'] !== null ? 'cursor:pointer;' : '' }}{{ $ativo ? 'background:' . $card['cor'] . '10;' : '' }}"
                @if ($card['key'] !== null) title="Clique pra filtrar a lista" @endif
            >
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
                    <span style="font-weight:700;font-size:0.8rem;">{{ $card['label'] }}</span>
                    <span style="width:10px;height:10px;border-radius:9999px;background:{{ $card['cor'] }};display:inline-block;"></span>
                </div>
                <div style="font-size:1.5rem;font-weight:800;color:{{ $card['cor'] }};line-height:1.1;">{{ number_format($card['count'], 0, ',', '.') }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400" style="margin-top:2px;">{{ $card['hint'] }}</div>
            </div>
        @endforeach
    </div>

    @if ($this->statusCard)
        <div class="text-sm text-gray-500 dark:text-gray-400">
            Filtro ativo pelo card — clique de novo no card pra limpar.
        </div>
    @endif

    <x-filament::section>
        {{ $this->table }}
    </x-filament::section>
</x-filament-panels::page>
