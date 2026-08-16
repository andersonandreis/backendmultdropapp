<x-filament-panels::page>
    <div>
        <x-filament::card>
            <h2 class="text-xl font-bold mb-1">Colaboradores da Equipe</h2>
            <p class="text-sm text-gray-500 mb-4">Gerencie a equipe interna do fornecedor. Use "Novo Colaborador" para adicionar membros.</p>
            {{ $this->table }}
        </x-filament::card>
    </div>
</x-filament-panels::page>
