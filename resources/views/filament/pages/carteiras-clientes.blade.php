<div>
    <x-filament::card>
        <h2 class="text-xl font-bold">Carteiras dos Clientes</h2>
        <p class="text-sm text-gray-500 mb-4">Saldo disponível por seller. Clique em "Extrato" para ver o histórico completo.</p>

        {{ $this->table }}
    </x-filament::card>
</div>
