<x-filament-panels::page>
    <div class="max-w-xl">
        <form wire:submit="salvar">
            {{ $this->form }}
            <div class="mt-4">
                <x-filament::button type="submit" icon="heroicon-o-check">
                    Salvar
                </x-filament::button>
            </div>
        </form>
    </div>
</x-filament-panels::page>
