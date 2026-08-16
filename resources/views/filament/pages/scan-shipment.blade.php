<x-filament-panels::page>
    <div class="space-y-4">
        {{-- Scan input --}}
        <div class="rounded-xl bg-white dark:bg-zinc-900 border border-gray-200 dark:border-zinc-700 shadow-sm p-5">
            <h2 class="text-base font-semibold text-gray-700 dark:text-gray-200 mb-3">Escanear Codigo</h2>
            <form wire:submit="submit">
                {{ $this->form }}
                <x-filament::button type="submit" class="mt-3" icon="heroicon-o-qr-code">
                    Confirmar Conferencia
                </x-filament::button>
            </form>
        </div>

        {{-- Flash feedback --}}
        <div
            id="scan-flash"
            class="hidden rounded-xl border p-4 font-medium transition-all duration-300"
        ></div>

        {{-- Last scan result --}}
        @if($this->lastScanResult)
            <div @class([
                'rounded-xl border p-4 font-medium',
                'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-700 text-green-700 dark:text-green-400' => $this->lastScanStatus === 'success',
                'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-700 text-red-700 dark:text-red-400' => $this->lastScanStatus === 'error',
                'bg-yellow-50 dark:bg-yellow-900/20 border-yellow-200 dark:border-yellow-700 text-yellow-700 dark:text-yellow-400' => $this->lastScanStatus === 'warning',
            ])>
                {{ $this->lastScanResult }}
            </div>
        @endif

        {{-- Order details after scan --}}
        @if($this->lastScanOrderInfo)
            <div class="rounded-xl border border-gray-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5 shadow-sm">
                <h3 class="text-lg font-bold text-gray-800 dark:text-gray-200 mb-3">Detalhes do Pedido Escaneado</h3>
                <div class="grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <span class="text-gray-500 dark:text-gray-400">Pedido:</span>
                        <span class="font-semibold text-gray-800 dark:text-gray-200">{{ $this->lastScanOrderInfo['order_number'] }}</span>
                    </div>
                    <div>
                        <span class="text-gray-500 dark:text-gray-400">Cliente:</span>
                        <span class="font-semibold text-gray-800 dark:text-gray-200">{{ $this->lastScanOrderInfo['client'] }}</span>
                    </div>
                    <div>
                        <span class="text-gray-500 dark:text-gray-400">Status anterior:</span>
                        <span class="inline-block px-2 py-0.5 rounded-full bg-gray-100 dark:bg-zinc-700 text-gray-700 dark:text-gray-300 text-xs font-medium">{{ $this->lastScanOrderInfo['previous_status'] }}</span>
                    </div>
                    <div>
                        <span class="text-gray-500 dark:text-gray-400">Novo status:</span>
                        <span class="inline-block px-2 py-0.5 rounded-full bg-green-100 dark:bg-green-800 text-green-700 dark:text-green-300 text-xs font-medium">{{ $this->lastScanOrderInfo['new_status'] }}</span>
                    </div>
                    <div>
                        <span class="text-gray-500 dark:text-gray-400">Itens:</span>
                        <span class="font-semibold text-gray-800 dark:text-gray-200">{{ $this->lastScanOrderInfo['items_count'] }}</span>
                    </div>
                    <div>
                        <span class="text-gray-500 dark:text-gray-400">Rastreio:</span>
                        <span class="font-semibold text-gray-800 dark:text-gray-200">{{ $this->lastScanOrderInfo['tracking'] }}</span>
                    </div>
                </div>
            </div>
        @endif
    </div>

    @push('scripts')
    <script>
        // Audio feedback using Web Audio API
        function playBeep(type) {
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.connect(gain);
                gain.connect(ctx.destination);
                gain.gain.value = 0.3;

                if (type === 'success') {
                    osc.frequency.value = 880;
                    osc.type = 'sine';
                } else if (type === 'error') {
                    osc.frequency.value = 220;
                    osc.type = 'square';
                } else {
                    osc.frequency.value = 440;
                    osc.type = 'triangle';
                }

                osc.start();
                osc.stop(ctx.currentTime + 0.15);
            } catch (e) {
                // Audio not supported
            }
        }

        // Visual flash feedback
        function flashScreen(type) {
            const el = document.getElementById('scan-flash');
            if (!el) return;
            el.className = 'rounded-xl border p-4 font-medium transition-all duration-300';

            if (type === 'success') {
                el.classList.add('bg-green-100', 'border-green-300', 'text-green-800');
                el.textContent = 'OK';
            } else if (type === 'error') {
                el.classList.add('bg-red-100', 'border-red-300', 'text-red-800');
                el.textContent = 'ERRO';
            } else {
                el.classList.add('bg-yellow-100', 'border-yellow-300', 'text-yellow-800');
                el.textContent = 'AVISO';
            }

            el.classList.remove('hidden');
            setTimeout(() => el.classList.add('hidden'), 1500);
        }

        // Listen for Livewire scan-feedback event
        document.addEventListener('livewire:init', () => {
            Livewire.on('scan-feedback', (data) => {
                const status = data[0]?.status || data.status || 'error';
                playBeep(status);
                flashScreen(status);
            });
        });

        // Auto-focus the input after each Livewire update
        document.addEventListener('livewire:init', () => {
            Livewire.hook('morph.updated', () => {
                setTimeout(() => {
                    const input = document.querySelector('[wire\\:model\\.live="data.label_code"], [wire\\:model="data.label_code"], input[type="text"]');
                    if (input) input.focus();
                }, 100);
            });
        });
    </script>
    @endpush
</x-filament-panels::page>
