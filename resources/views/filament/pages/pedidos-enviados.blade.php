<x-filament-panels::page>
    <div class="space-y-4">
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            @foreach($this->getMetricas() as $m)
                <div class="rounded-xl bg-white border border-gray-200 shadow-sm p-4">
                    <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">{{ $m['label'] }}</p>
                    <p class="text-2xl font-bold text-{{ $m['cor'] }}-600">{{ $m['valor'] }}</p>
                </div>
            @endforeach
        </div>

        <x-filament::card>
            <h2 class="text-lg font-bold mb-1">Histórico de Envios</h2>
            {{ $this->table }}
        </x-filament::card>
    </div>
</x-filament-panels::page>
