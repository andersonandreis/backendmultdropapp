<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center gap-2">
                <x-heroicon-o-queue-list class="w-5 h-5 text-cyan-500" />
                Saude das Filas
            </div>
        </x-slot>
        <x-slot name="description">
            <span class="text-amber-500 dark:text-amber-400 font-semibold">{{ $totalFailed }}</span> falhas totais &bull; <span class="font-semibold">{{ $totalPending }}</span> pendentes
        </x-slot>
        <x-slot name="headerEnd">
            <div class="flex gap-2">
                <x-filament::button wire:click="clearOldFailed" color="danger" size="sm" icon="heroicon-o-trash" wire:confirm="Remover todos os failed_jobs com mais de 7 dias?">
                    Limpar Antigos
                </x-filament::button>
                <x-filament::button wire:click="loadData" color="gray" size="sm" icon="heroicon-o-arrow-path">Atualizar</x-filament::button>
            </div>
        </x-slot>

        <div wire:poll.30s="loadData">
            @if(count($queues) === 0)
                <div class="flex items-center gap-3 py-8 justify-center">
                    <x-heroicon-o-check-circle class="w-8 h-8 text-emerald-500" />
                    <div>
                        <p class="font-semibold text-emerald-700 dark:text-emerald-400">Todas as filas limpas</p>
                        <p class="text-sm text-gray-400">Nenhum job pendente ou com falha</p>
                    </div>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide border-b border-gray-100 dark:border-gray-800">
                                <th class="text-left py-2 px-3">Fila</th>
                                <th class="text-right py-2 px-3">Pendentes</th>
                                <th class="text-right py-2 px-3">Processando</th>
                                <th class="text-right py-2 px-3">Falhas</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach($queues as $q)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors">
                                <td class="py-2 px-3">
                                    <span class="font-mono text-xs bg-gray-100 dark:bg-gray-800 px-2 py-0.5 rounded">{{ $q['name'] }}</span>
                                </td>
                                <td class="py-2 px-3 text-right tabular-nums">
                                    @if($q['pending'] > 0)
                                        <span class="text-amber-600 dark:text-amber-400 font-semibold">{{ number_format($q['pending']) }}</span>
                                    @else
                                        <span class="text-gray-400">0</span>
                                    @endif
                                </td>
                                <td class="py-2 px-3 text-right tabular-nums">
                                    @if($q['processing'] > 0)
                                        <span class="text-cyan-600 dark:text-cyan-400 font-semibold">
                                            <span class="w-1.5 h-1.5 bg-cyan-500 rounded-full animate-pulse inline-block mr-1"></span>{{ $q['processing'] }}
                                        </span>
                                    @else
                                        <span class="text-gray-400">0</span>
                                    @endif
                                </td>
                                <td class="py-2 px-3 text-right tabular-nums">
                                    @if($q['failed'] > 0)
                                        <span class="text-red-600 dark:text-red-400 font-bold">{{ number_format($q['failed']) }}</span>
                                    @else
                                        <span class="text-emerald-500">OK</span>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>