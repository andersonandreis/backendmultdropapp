<x-filament-panels::page>
    <div class="space-y-6">

        {{-- Alerta de status da fila default --}}
        @if($alertLevel === 'red')
        <div class="rounded-xl border border-red-200 bg-red-50 dark:border-red-500/20 dark:bg-red-500/10 p-4 flex items-start gap-3">
            <x-heroicon-o-x-circle class="w-5 h-5 text-red-500 mt-0.5 shrink-0" />
            <div>
                <p class="text-sm font-semibold text-red-800 dark:text-red-400">CRITICO — Fila acumulada acima de 50.000 jobs</p>
                <p class="text-xs text-red-700 dark:text-red-500 mt-0.5">Total pendente: {{ number_format($totalPending) }} jobs. Verificar workers e logs imediatamente.</p>
            </div>
        </div>
        @elseif($alertLevel === 'yellow')
        <div class="rounded-xl border border-amber-200 bg-amber-50 dark:border-amber-500/20 dark:bg-amber-500/10 p-4 flex items-start gap-3">
            <x-heroicon-o-exclamation-triangle class="w-5 h-5 text-amber-500 mt-0.5 shrink-0" />
            <div>
                <p class="text-sm font-semibold text-amber-800 dark:text-amber-400">ATENCAO — Fila com mais de 10.000 jobs</p>
                <p class="text-xs text-amber-700 dark:text-amber-500 mt-0.5">Total pendente: {{ number_format($totalPending) }} jobs. Monitorar throughput dos workers.</p>
            </div>
        </div>
        @else
        <div class="rounded-xl border border-green-200 bg-green-50 dark:border-green-500/20 dark:bg-green-500/10 p-4 flex items-start gap-3">
            <x-heroicon-o-check-circle class="w-5 h-5 text-green-500 mt-0.5 shrink-0" />
            <div>
                <p class="text-sm font-semibold text-green-800 dark:text-green-400">Filas saudáveis</p>
                <p class="text-xs text-green-700 dark:text-green-500 mt-0.5">Total pendente: {{ number_format($totalPending) }} jobs. Tudo dentro do normal.</p>
            </div>
        </div>
        @endif

        {{-- Header: totais + botão atualizar --}}
        <div class="flex items-center justify-between">
            <div class="flex gap-6">
                <div>
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Jobs pendentes</span>
                    <div class="text-2xl font-bold tabular-nums text-gray-900 dark:text-white">{{ number_format($totalPending) }}</div>
                </div>
                <div>
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Jobs falhos</span>
                    <div class="text-2xl font-bold tabular-nums {{ $totalFailed > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-900 dark:text-white' }}">
                        {{ number_format($totalFailed) }}
                    </div>
                </div>
                <div class="self-end text-xs text-gray-400 dark:text-gray-500 mb-1">
                    Atualizado: <span class="font-mono">{{ $lastUpdated }}</span>
                </div>
            </div>
            <x-filament::button wire:click="refresh" color="gray" icon="heroicon-o-arrow-path">
                Atualizar
            </x-filament::button>
        </div>

        {{-- Tabela de filas --}}
        <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Fila</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Jobs</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Job mais antigo</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Job mais comum</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse($queues as $queue)
                    @php
                        $isHigh   = $queue['job_count'] > 50000;
                        $isMedium = !$isHigh && $queue['job_count'] > 10000;
                        $ageH     = (int) floor($queue['oldest_job_age_minutes'] / 60);
                        $ageM     = $queue['oldest_job_age_minutes'] % 60;
                        $ageLabel = $ageH > 0 ? "{$ageH}h {$ageM}m" : "{$ageM}m";
                    @endphp
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                        <td class="px-4 py-3 text-sm font-mono font-semibold text-gray-900 dark:text-white">
                            {{ $queue['queue_name'] }}
                        </td>
                        <td class="px-4 py-3 text-sm text-right tabular-nums font-bold
                            {{ $isHigh ? 'text-red-600 dark:text-red-400' : ($isMedium ? 'text-amber-600 dark:text-amber-400' : 'text-gray-900 dark:text-white') }}">
                            {{ number_format($queue['job_count']) }}
                        </td>
                        <td class="px-4 py-3 text-sm text-right text-gray-500 dark:text-gray-400 font-mono">
                            {{ $queue['oldest_job_age_minutes'] > 0 ? $ageLabel : '—' }}
                        </td>
                        <td class="px-4 py-3 text-sm font-mono text-gray-600 dark:text-gray-300">
                            {{ $queue['most_common_job'] }}
                        </td>
                        <td class="px-4 py-3">
                            @if($isHigh)
                            <span class="inline-flex items-center gap-1 rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700 dark:bg-red-500/10 dark:text-red-400">
                                Critico
                            </span>
                            @elseif($isMedium)
                            <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700 dark:bg-amber-500/10 dark:text-amber-400">
                                Alto
                            </span>
                            @else
                            <span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-700 dark:bg-green-500/10 dark:text-green-400">
                                Normal
                            </span>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                            Nenhum job pendente nas filas.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <p class="text-xs text-gray-400 dark:text-gray-600">
            Jobs com status "reserved" (em processamento) são incluídos na contagem. A coluna "job mais antigo" mede o tempo desde a inserção na fila até agora.
        </p>

    </div>
</x-filament-panels::page>
