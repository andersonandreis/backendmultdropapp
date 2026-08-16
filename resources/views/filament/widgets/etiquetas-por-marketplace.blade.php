<x-filament-widgets::widget>
    <x-filament::section>
        {{-- MUL-226-11: renomeado de "Etiquetas por Marketplace" pra evitar duplicidade
             com o stat "Etiquetas Impressas" — este card mostra PEDIDOS por canal --}}
        <x-slot name="heading">Pedidos por Marketplace</x-slot>
        <x-slot name="headerEnd">
            <x-filament::link :href="url('/admin/imprimir-etiquetas')" icon="heroicon-o-printer" size="sm">
                Ir para impressão
            </x-filament::link>
        </x-slot>

        {{-- MUL-226-09: pendentes = fila da página Imprimir Etiquetas (mesmos números) --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
            @foreach ($this->getCanaisData() as $canal)
                <div style="border-radius:12px;border:1px solid rgba(148,163,184,0.25);padding:14px 16px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                        <span style="font-weight:700;font-size:0.9rem;">{{ $canal['canal'] }}</span>
                        <span style="width:10px;height:10px;border-radius:9999px;background:{{ $canal['cor'] }};display:inline-block;"></span>
                    </div>
                    <div style="display:flex;align-items:baseline;gap:6px;">
                        <span style="font-size:1.6rem;font-weight:800;color:{{ $canal['cor'] }};">{{ $canal['pendentes'] }}</span>
                        <span class="text-xs text-gray-500 dark:text-gray-400">pendentes de impressão</span>
                    </div>
                    <div style="display:flex;align-items:baseline;gap:6px;margin-top:2px;">
                        <span style="font-size:1rem;font-weight:700;">{{ $canal['embaladas'] }}</span>
                        <span class="text-xs text-gray-500 dark:text-gray-400">embaladas aguard. envio</span>
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
