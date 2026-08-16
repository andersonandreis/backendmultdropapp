<div>
    <h2 class="text-xl font-bold mb-4">Configurações de Estratégia de Preço</h2>
    <p class="text-sm text-gray-500 mb-6">Configure como sua conta importa catálogos e aplica margens globais.</p>

    <form wire:submit="saveSettings">
        {{ $this->form }}

        <div class="mt-4">
            <x-filament::button type="submit">
                Save Strategy
            </x-filament::button>
        </div>
    </form>
</div>