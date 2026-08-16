<x-filament-panels::page>
    <div class="max-w-2xl">
        <form wire:submit="salvar">
            {{ $this->form }}
            <div class="mt-4">
                <x-filament::button type="submit" icon="heroicon-o-check">
                    Salvar Endereços
                </x-filament::button>
            </div>
        </form>
    </div>
</x-filament-panels::page>
