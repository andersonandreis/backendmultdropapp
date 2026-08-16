<x-filament-panels::page>
    <div class="space-y-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            @foreach($this->getBlocos() as $bloco)
                <div class="rounded-xl bg-white border border-{{ $bloco['cor'] }}-200 shadow-sm p-5">
                    <h3 class="font-bold text-{{ $bloco['cor'] }}-700 mb-3 border-b border-{{ $bloco['cor'] }}-100 pb-2">{{ $bloco['titulo'] }}</h3>
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-gray-500">Total</span>
                            <span class="font-bold text-gray-800">{{ $bloco['total'] }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Aguardando</span>
                            <span class="font-semibold text-yellow-600">{{ $bloco['aguardando'] }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Bloqueado</span>
                            <span class="font-semibold text-red-500">{{ $bloco['bloqueado'] }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Liberado</span>
                            <span class="font-semibold text-green-600">{{ $bloco['liberado'] }}</span>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <x-filament::card>
            <h2 class="text-lg font-bold mb-1">Faturas / Transações</h2>
            {{ $this->table }}
        </x-filament::card>
    </div>
</x-filament-panels::page>
