<x-filament-panels::page>
    <form wire:submit="migrate" class="space-y-4">
        {{ $this->form }}
        <div class="flex justify-end">
            <x-filament::button type="submit" color="primary" icon="heroicon-o-arrows-right-left">
                Migrar Anúncio
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
