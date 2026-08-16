<x-filament-panels::page>
    <div class="max-w-lg space-y-4">
        <div class="rounded-xl bg-white border border-gray-200 shadow-sm p-5">
            <h2 class="text-base font-semibold text-gray-700 mb-3">Validar Chave Pix</h2>
            <form wire:submit="validar">
                {{ $this->form }}
                <x-filament::button type="submit" class="mt-4" icon="heroicon-o-check-circle">
                    Validar
                </x-filament::button>
            </form>
        </div>

        @if($this->resultado)
            <div class="rounded-xl p-4 border {{ $this->resultado['status'] === 'valido' ? 'bg-green-50 border-green-200 text-green-700' : 'bg-red-50 border-red-200 text-red-700' }}">
                <p class="font-semibold">{{ $this->resultado['status'] === 'valido' ? 'Chave Válida' : 'Chave Inválida' }}</p>
                <p class="text-sm mt-1">{{ $this->resultado['message'] }}</p>
                <p class="text-xs mt-1 opacity-70">Chave: {{ $this->resultado['chave'] }} ({{ $this->resultado['tipo'] }})</p>
            </div>
        @endif
    </div>
</x-filament-panels::page>
