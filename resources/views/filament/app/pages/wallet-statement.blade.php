<x-filament-panels::page>
    {{-- Cards de saldo por fornecedor --}}
    @if($balances->isNotEmpty())
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
            @foreach($balances as $balance)
                @php
                    $saldo = (float) $balance->balance;
                    $color = $saldo <= 0 ? 'danger' : ($saldo < 50 ? 'warning' : 'success');
                    $colorMap = [
                        'success' => 'border-emerald-500/30 bg-emerald-500/5',
                        'warning' => 'border-amber-500/30 bg-amber-500/5',
                        'danger'  => 'border-red-500/30 bg-red-500/5',
                    ];
                    $textMap = [
                        'success' => 'text-emerald-400',
                        'warning' => 'text-amber-400',
                        'danger'  => 'text-red-400',
                    ];
                    $isSelected = $selectedSupplierId === $balance->supplier_id;
                @endphp
                <button
                    wire:click="selectSupplier({{ $balance->supplier_id }})"
                    class="text-left rounded-xl border p-4 transition-all duration-150
                           {{ $colorMap[$color] }}
                           {{ $isSelected ? 'ring-2 ring-emerald-500/50 shadow-lg' : 'opacity-80 hover:opacity-100' }}"
                >
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm font-medium text-slate-300 truncate">
                            {{ $balance->supplier->company_name ?? 'Fornecedor' }}
                        </span>
                        <x-filament::badge :color="$color" size="sm">
                            {{ match($color) { 'success' => 'OK', 'warning' => 'Baixo', 'danger' => 'Zerado' } }}
                        </x-filament::badge>
                    </div>
                    <div class="text-2xl font-bold {{ $textMap[$color] }}">
                        R$ {{ number_format($saldo, 2, ',', '.') }}
                    </div>
                </button>
            @endforeach
        </div>
    @else
        <div class="text-center py-8 text-slate-500">
            <x-filament::icon icon="heroicon-o-wallet" class="w-12 h-12 mx-auto mb-3 opacity-40"/>
            <p>Nenhum saldo encontrado. Associe-se a um fornecedor e recarregue sua wallet.</p>
        </div>
    @endif

    {{-- Modal PIX --}}
    @if($showingPix)
        <div class="mb-6 rounded-xl border border-emerald-500/20 bg-emerald-500/5 p-6">
            <h3 class="text-lg font-semibold text-emerald-400 mb-4 flex items-center gap-2">
                <x-filament::icon icon="heroicon-o-qr-code" class="w-5 h-5"/>
                Pague com PIX
            </h3>

            <div class="flex flex-col md:flex-row gap-6 items-start">
                @if($pixQrCode)
                    <div class="flex-shrink-0">
                        <img src="data:image/png;base64,{{ $pixQrCode }}" alt="QR Code PIX" class="w-48 h-48 rounded-lg border border-emerald-500/20"/>
                    </div>
                @endif

                <div class="flex-1 space-y-4">
                    <div>
                        <label class="block text-xs text-slate-400 mb-1">PIX Copia e Cola</label>
                        <div class="flex gap-2">
                            <input
                                type="text"
                                value="{{ $pixCopyPaste }}"
                                readonly
                                class="flex-1 rounded-lg border border-emerald-500/20 bg-slate-900/50 px-3 py-2 text-sm text-slate-300 font-mono"
                            />
                            <button
                                onclick="navigator.clipboard.writeText('{{ $pixCopyPaste }}').then(() => alert('Copiado!'))"
                                class="px-3 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-sm transition-colors"
                            >
                                Copiar
                            </button>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <x-filament::icon icon="heroicon-o-clock" class="w-4 h-4 text-amber-400"/>
                        <span class="text-sm text-amber-400">
                            O QR Code expira em 30 minutos. O status é verificado automaticamente.
                        </span>
                    </div>

                    <div wire:poll.5s="checkPixStatus" class="text-xs text-slate-500">
                        Verificando pagamento automaticamente...
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Tabela de transações --}}
    <div>
        {{ $this->table }}
    </div>
</x-filament-panels::page>
