<x-filament-panels::page>
    <div>
        <x-filament::card>
            <h2 class="text-xl font-bold mb-1">Remetentes do Deposito</h2>
            <p class="text-sm text-gray-500 mb-4">Lista de fornecedores e remetentes cadastrados no sistema.</p>
            {{ $this->table }}
        </x-filament::card>
    </div>
</x-filament-panels::page>
