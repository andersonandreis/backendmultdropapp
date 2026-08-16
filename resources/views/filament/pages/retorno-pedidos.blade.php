<x-filament-panels::page>
    <div>
        <x-filament::card>
            <h2 class="text-xl font-bold mb-1">Retorno de Pedidos</h2>
            <p class="text-sm text-gray-500 mb-4">Pedidos devolvidos ou cancelados. Use a busca para localizar por cliente, rastreio, NF ou SKU.</p>
            {{ $this->table }}
        </x-filament::card>
    </div>
</x-filament-panels::page>
