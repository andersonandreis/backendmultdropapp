<x-filament-panels::page>
    <div>
        <x-filament::card>
            <h2 class="text-xl font-bold mb-1">Pagamentos de Pedidos</h2>
            <p class="text-sm text-gray-500 mb-4">Acompanhe os pagamentos: pagos, pendentes e bloqueados.</p>
            {{ $this->table }}
        </x-filament::card>
    </div>
</x-filament-panels::page>
