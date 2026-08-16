<x-filament-panels::page>
    <div class="space-y-4">
        {{-- Quick scan input --}}
        <div class="rounded-xl bg-white dark:bg-zinc-900 border border-gray-200 dark:border-zinc-700 shadow-sm p-5">
            <h2 class="text-base font-semibold text-gray-700 dark:text-gray-200 mb-2">Conferencia Rapida</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-3">Escaneie o codigo de barras ou rastreio para conferir e vincular ao pedido.</p>
            <div class="flex gap-3 items-end">
                <div class="flex-1">
                    <input
                        type="text"
                        wire:model="quickScanCode"
                        wire:keydown.enter="quickScan"
                        placeholder="Escaneie ou digite o codigo..."
                        autofocus
                        autocomplete="off"
                        class="w-full rounded-lg border-gray-300 dark:border-zinc-600 dark:bg-zinc-800 dark:text-gray-200 shadow-sm focus:border-primary-500 focus:ring-primary-500 text-sm"
                    />
                </div>
                <x-filament::button wire:click="quickScan" icon="heroicon-o-qr-code">
                    Conferir
                </x-filament::button>
            </div>

            @if($this->quickScanResult)
                <div @class([
                    'mt-3 rounded-lg border p-3 text-sm font-medium',
                    'bg-green-50 dark:bg-green-900/20 border-green-200 text-green-700 dark:text-green-400' => $this->quickScanStatus === 'success',
                    'bg-red-50 dark:bg-red-900/20 border-red-200 text-red-700 dark:text-red-400' => $this->quickScanStatus === 'error',
                    'bg-yellow-50 dark:bg-yellow-900/20 border-yellow-200 text-yellow-700 dark:text-yellow-400' => $this->quickScanStatus === 'warning',
                ])>
                    {{ $this->quickScanResult }}
                </div>
            @endif
        </div>

        {{-- Existing table --}}
        <x-filament::card>
            <h2 class="text-xl font-bold mb-1">Conferencia de Pedidos</h2>
            <p class="text-sm text-gray-500 mb-4">Controle de pedidos conferidos e pendentes no processo de separacao.</p>
            {{ $this->table }}
        </x-filament::card>
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
                osc.frequency.value = type === 'success' ? 880 : (type === 'warning' ? 440 : 220);
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

            // Auto-focus the quick scan input after updates
            Livewire.hook('morph.updated', () => {
                setTimeout(() => {
                    const input = document.querySelector('input[wire\\:model="quickScanCode"]');
                    if (input) input.focus();
                }, 100);
            });
        });
    </script>
    @endpush
</x-filament-panels::page>
