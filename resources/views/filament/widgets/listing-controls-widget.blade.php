<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center gap-2">
                <x-filament::icon icon="heroicon-o-cog-6-tooth" class="h-5 w-5 text-primary-500" />
                Controles do Robo de Cadastro
            </div>
        </x-slot>

        <div class="space-y-4">
            {{ $this->form }}

            <div class="flex flex-wrap gap-3 pt-2">
                <x-filament::button
                    wire:click="applyGlobalSpeed"
                    color="info"
                    icon="heroicon-o-bolt"
                >
                    Aplicar Velocidade
                </x-filament::button>

                <x-filament::button
                    wire:click="applyGenerateImage"
                    color="primary"
                    icon="heroicon-o-photo"
                >
                    Aplicar Config de Imagem
                </x-filament::button>

                <x-filament::button
                    wire:click="enqueueAll"
                    color="success"
                    icon="heroicon-o-queue-list"
                >
                    Enfileirar Tudo
                </x-filament::button>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
