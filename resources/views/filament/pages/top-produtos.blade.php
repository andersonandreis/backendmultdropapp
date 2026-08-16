<x-filament-panels::page>
    <div>
        <x-filament::card>
            <h2 class="text-xl font-bold mb-1">Ranking de Produtos</h2>
            <p class="text-sm text-gray-500 mb-4">Os produtos mais vendidos da plataforma, ordenados por unidades vendidas.</p>
            {{ $this->table }}
        </x-filament::card>
    </div>
</x-filament-panels::page>
