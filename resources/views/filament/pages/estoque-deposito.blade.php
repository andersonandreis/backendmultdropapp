<x-filament-panels::page>
    <div>
        <x-filament::card>
            <h2 class="text-xl font-bold mb-1">Controle de Estoque</h2>
            <p class="text-sm text-gray-500 mb-4">Controle de estoque por SKU, galpão e preço. Use "Movimento" para registrar entradas, saídas ou ajustes.</p>
            {{ $this->table }}
        </x-filament::card>
    </div>
</x-filament-panels::page>
