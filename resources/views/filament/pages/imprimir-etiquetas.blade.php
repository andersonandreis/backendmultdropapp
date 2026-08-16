<x-filament-panels::page>
    <div class="space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach($this->getCanaisData() as $canal)
                <div class="rounded-xl bg-white border border-gray-200 shadow-sm p-5 flex flex-col gap-3">
                    <div class="flex items-center justify-between">
                        <h3 class="font-semibold text-gray-800">{{ $canal['canal'] }}</h3>
                        <span class="text-2xl font-bold text-{{ $canal['cor'] }}-600">{{ $canal['quantidade'] }}</span>
                    </div>
                    <p class="text-xs text-gray-500">etiquetas pendentes de impressão</p>
                    <x-filament::button
                        wire:click="printAll('{{ $canal['canal'] }}')"
                        color="{{ $canal['quantidade'] > 0 ? 'primary' : 'gray' }}"
                        size="sm"
                        icon="heroicon-o-printer"
                        :disabled="$canal['quantidade'] === 0"
                    >
                        Imprimir Todas
                    </x-filament::button>
                </div>
            @endforeach
        </div>

        <div class="rounded-xl bg-blue-50 border border-blue-200 p-4 text-sm text-blue-700">
            <strong>Info:</strong> Apenas pedidos com etiqueta disponível e aguardando despacho são exibidos.
            Após clicar em "Imprimir Todas", as etiquetas são marcadas como impressas.
        </div>
    </div>
</x-filament-panels::page>
