<x-filament-panels::page>
    <div class="max-w-3xl space-y-4">
        <form wire:submit="salvar">
            {{ $this->form }}

            <div class="flex gap-3 mt-6">
                <x-filament::button type="submit" icon="heroicon-o-check">
                    Salvar Configuracoes
                </x-filament::button>

                <x-filament::button
                    type="button"
                    wire:click="testarConexao"
                    color="info"
                    icon="heroicon-o-signal"
                    outlined
                >
                    Testar Conexao
                </x-filament::button>
            </div>
        </form>
    </div>
</x-filament-panels::page>
