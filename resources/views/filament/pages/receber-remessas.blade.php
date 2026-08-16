<x-filament-panels::page>
    <div class="space-y-4 max-w-xl">
        <div class="rounded-xl bg-white dark:bg-zinc-900 border border-gray-200 dark:border-zinc-700 shadow-sm p-5">
            <h2 class="text-base font-semibold text-gray-700 dark:text-gray-200 mb-3">Conferencia de Remessa</h2>
            <form wire:submit="scan">
                {{ $this->form }}
                <x-filament::button type="submit" class="mt-3" icon="heroicon-o-qr-code">
                    Conferir Remessa
                </x-filament::button>
            </form>
        </div>

        @if($this->remessaEncontrada)
            <div class="rounded-xl border border-gray-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5 shadow-sm">
                <h3 class="font-bold text-gray-800 dark:text-gray-100 mb-2">Remessa Encontrada</h3>
                <div class="grid grid-cols-2 gap-3 text-sm mb-4">
                    <div>
                        <span class="text-gray-500">Tracking:</span><br>
                        <strong>{{ $this->remessaEncontrada->tracking_number }}</strong>
                    </div>
                    <div>
                        <span class="text-gray-500">Status:</span><br>
                        <strong>{{ $this->remessaEncontrada->status }}</strong>
                    </div>
                </div>

                @if($this->remessaEncontrada->status !== 'received')
                    <x-filament::button
                        wire:click="confirmarRecebimento"
                        color="success"
                        icon="heroicon-o-check-circle"
                    >
                        Confirmar Recebimento
                    </x-filament::button>
                @else
                    <div class="text-sm text-green-600 font-medium">Esta remessa já foi recebida.</div>
                @endif
            </div>
        @endif

        @if($this->confirmado)
            <div class="rounded-xl bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-700 p-4 text-green-700 dark:text-green-400 font-medium">
                Remessa recebida com sucesso! Estoque atualizado.
            </div>
        @endif
    </div>
</x-filament-panels::page>
