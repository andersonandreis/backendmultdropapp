<x-filament-panels::page>
    <div class="max-w-xl space-y-4">
        <div class="rounded-xl bg-white border border-gray-200 shadow-sm p-5">
            <h2 class="text-base font-semibold text-gray-700 mb-3">Buscar Código de Coleta ML</h2>
            <form wire:submit="buscar">
                {{ $this->form }}
                <x-filament::button type="submit" class="mt-3" icon="heroicon-o-magnifying-glass">
                    Buscar
                </x-filament::button>
            </form>
        </div>

        {!! $this->getPedidoInfoHtml() !!}
    </div>
</x-filament-panels::page>
