<x-filament-panels::page>
    <form wire:submit="salvar" class="space-y-6">
        {{ $this->form }}

        <div class="flex justify-end gap-2">
            @foreach ($this->getFormActions() as $action)
                {{ $action }}
            @endforeach
        </div>
    </form>
</x-filament-panels::page>
