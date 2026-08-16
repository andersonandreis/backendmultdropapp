<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center gap-2">
                <x-heroicon-o-currency-dollar class="w-5 h-5 text-emerald-500" />
                Resumo Financeiro
            </div>
        </x-slot>
        <x-slot name="description">Ultima leitura: <span class="font-mono">{{ $lastUpdated }}</span></x-slot>
        <x-slot name="headerEnd">
            <x-filament::button wire:click="loadData" color="gray" size="sm" icon="heroicon-o-arrow-path">Atualizar</x-filament::button>
        </x-slot>

        <div class="space-y-4" wire:poll.30s="loadData">

            <div class="grid grid-cols-2 gap-3">

                <div class="bg-gray-50 dark:bg-gray-800/50 rounded-xl p-4">
                    <div class="flex items-center gap-2 mb-1">
                        <x-heroicon-o-arrow-up-tray class="w-4 h-4 text-amber-500" />
                        <span class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Saques Pendentes</span>
                    </div>
                    <div class="flex items-baseline gap-2 mt-2">
                        <span class="text-2xl font-bold tabular-nums {{ ($data['saques_pendentes_qtd'] ?? 0) > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                            {{ number_format($data['saques_pendentes_qtd'] ?? 0) }}
                        </span>
                        <span class="text-sm text-gray-500">solicitacoes</span>
                    </div>
                    <p class="text-sm font-semibold text-gray-700 dark:text-gray-300 font-mono mt-1">R$ {{ number_format($data['saques_pendentes_valor'] ?? 0, 2, ',', '.') }}</p>
                </div>

                <div class="bg-gray-50 dark:bg-gray-800/50 rounded-xl p-4">
                    <div class="flex items-center gap-2 mb-1">
                        <x-heroicon-o-wallet class="w-4 h-4 text-cyan-500" />
                        <span class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Saldo em Carteiras</span>
                    </div>
                    <div class="mt-2">
                        <span class="text-2xl font-bold text-cyan-600 dark:text-cyan-400 font-mono tabular-nums">R$ {{ number_format($data['saldo_total'] ?? 0, 2, ',', '.') }}</span>
                    </div>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Total consolidado</p>
                </div>

                <div class="bg-gray-50 dark:bg-gray-800/50 rounded-xl p-4">
                    <div class="flex items-center gap-2 mb-1">
                        <x-heroicon-o-chart-bar class="w-4 h-4 text-emerald-500" />
                        <span class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Receita Hoje</span>
                    </div>
                    <div class="mt-2">
                        <span class="text-2xl font-bold text-emerald-600 dark:text-emerald-400 font-mono tabular-nums">R$ {{ number_format($data['receita_hoje'] ?? 0, 2, ',', '.') }}</span>
                    </div>
                </div>

                <div class="bg-gray-50 dark:bg-gray-800/50 rounded-xl p-4">
                    <div class="flex items-center gap-2 mb-1">
                        <x-heroicon-o-arrow-trending-up class="w-4 h-4 text-emerald-500" />
                        <span class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Receita 7 Dias</span>
                    </div>
                    <div class="mt-2">
                        <span class="text-2xl font-bold text-emerald-600 dark:text-emerald-400 font-mono tabular-nums">R$ {{ number_format($data['receita_7d'] ?? 0, 2, ',', '.') }}</span>
                    </div>
                </div>

            </div>

            @if(!empty($data['top_saldos']))
            <div>
                <h4 class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-2">Top Saldos por Fornecedor</h4>
                <div class="space-y-1">
                    @foreach($data['top_saldos'] as $saldo)
                    <div class="flex items-center justify-between py-1.5 border-b border-gray-100 dark:border-gray-800">
                        <span class="text-sm text-gray-700 dark:text-gray-300 truncate max-w-xs">{{ $saldo['name'] ?? '?' }}</span>
                        <span class="text-sm font-semibold font-mono text-cyan-600 dark:text-cyan-400">R$ {{ number_format($saldo['balance'] ?? 0, 2, ',', '.') }}</span>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif

        </div>
    </x-filament::section>
</x-filament-widgets::widget>