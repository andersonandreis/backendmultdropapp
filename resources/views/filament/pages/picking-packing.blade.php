<x-filament-panels::page>
    <div class="space-y-4">
        <div class="rounded-xl bg-white dark:bg-zinc-900 border border-gray-200 dark:border-zinc-700 shadow-sm p-5">
            <h2 class="text-base font-semibold text-gray-700 dark:text-gray-200 mb-3">Separacao de Pedido</h2>
            <form wire:submit="search">
                {{ $this->form }}
                <x-filament::button type="submit" class="mt-3" icon="heroicon-o-magnifying-glass">
                    Buscar Pedido
                </x-filament::button>
            </form>
        </div>

        @if($this->foundOrder)
            {{-- Visual workflow progress --}}
            <div class="rounded-xl bg-white dark:bg-zinc-900 border border-gray-200 dark:border-zinc-700 shadow-sm p-5">
                <h3 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase mb-3">Fluxo do Pedido</h3>
                <div class="flex items-center gap-1 overflow-x-auto">
                    @foreach($this->getWorkflowSteps() as $index => $step)
                        <div class="flex items-center">
                            <div @class([
                                'flex items-center gap-2 px-3 py-2 rounded-lg text-xs font-medium whitespace-nowrap',
                                'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400' => $step['state'] === 'completed',
                                'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 ring-2 ring-blue-400' => $step['state'] === 'current',
                                'bg-gray-100 dark:bg-zinc-800 text-gray-400 dark:text-gray-500' => $step['state'] === 'pending',
                            ])>
                                @if($step['state'] === 'completed')
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                @endif
                                {{ $step['label'] }}
                            </div>
                            @if(!$loop->last)
                                <svg class="w-4 h-4 text-gray-300 dark:text-zinc-600 mx-1 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>

            {!! $this->getOrderInfoHtml() !!}

            {{-- Action buttons --}}
            <div class="flex flex-wrap gap-3">
                @php $status = $this->foundOrder->order_processing_status ?? 'awaiting_dispatch'; @endphp

                @if(in_array($status, ['awaiting_dispatch', 'separating', null, '']))
                    <x-filament::button
                        wire:click="separarPedido"
                        color="primary"
                        icon="heroicon-o-scissors"
                        size="lg"
                    >
                        Separar Pedido
                    </x-filament::button>
                @endif

                @if($status === 'separated')
                    <x-filament::button
                        wire:click="despachar"
                        color="warning"
                        icon="heroicon-o-truck"
                        size="lg"
                    >
                        Despachar
                    </x-filament::button>
                @endif

                <x-filament::button
                    wire:click="confirm"
                    color="success"
                    icon="heroicon-o-check-circle"
                    size="lg"
                >
                    Confirmar Separacao
                </x-filament::button>
            </div>
        @endif

        @if($this->confirmed)
            <div @class([
                'rounded-xl border p-4 font-medium',
                'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-700 text-green-700 dark:text-green-400',
            ])>
                @if($this->lastAction === 'separated')
                    Pedido separado e conferencia registrada com sucesso!
                @elseif($this->lastAction === 'awaiting_shipment')
                    Pedido marcado para despacho com sucesso!
                @else
                    Pedido confirmado e separacao registrada com sucesso!
                @endif
            </div>
        @endif
    </div>

    @push('scripts')
    <script>
        // Audio feedback
        function playBeep(type) {
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.connect(gain);
                gain.connect(ctx.destination);
                gain.gain.value = 0.3;
                osc.frequency.value = type === 'success' ? 880 : 220;
                osc.type = type === 'success' ? 'sine' : 'square';
                osc.start();
                osc.stop(ctx.currentTime + 0.15);
            } catch (e) {}
        }

        document.addEventListener('livewire:init', () => {
            Livewire.on('scan-feedback', (data) => {
                const status = data[0]?.status || data.status || 'error';
                playBeep(status);
            });
        });

        // Auto-focus scan input after update
        document.addEventListener('livewire:init', () => {
            Livewire.hook('morph.updated', () => {
                setTimeout(() => {
                    const input = document.querySelector('input[type="text"]');
                    if (input) input.focus();
                }, 100);
            });
        });
    </script>
    @endpush
</x-filament-panels::page>
