<x-filament-panels::page>
    <div>
        <x-filament::card>
            <h2 class="text-xl font-bold mb-1">Ranking de Sellers</h2>
            <p class="text-sm text-gray-500 mb-4">Os sellers mais ativos da plataforma, ordenados por volume de pedidos.</p>
            {{ $this->table }}
        </x-filament::card>
    </div>
</x-filament-panels::page>
