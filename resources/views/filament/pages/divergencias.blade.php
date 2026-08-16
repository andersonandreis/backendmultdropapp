<x-filament-panels::page>
    <div class="space-y-6">

        {{-- Header: totais + botão atualizar --}}
        <div class="flex items-center justify-between">
            <div class="flex gap-6">
                <div>
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Pendentes</span>
                    <div class="text-2xl font-bold tabular-nums {{ $totalPending > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-900 dark:text-white' }}">
                        {{ number_format($totalPending) }}
                    </div>
                </div>
                <div>
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Resolvidas</span>
                    <div class="text-2xl font-bold tabular-nums text-green-600 dark:text-green-400">
                        {{ number_format($totalResolved) }}
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

        {{-- Tabela de divergências --}}
        <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Data</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Tipo</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Assunto</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Detalhe</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Ação</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse($divergences as $row)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                        <td class="px-4 py-3 text-xs font-mono text-gray-500 dark:text-gray-400 whitespace-nowrap">
                            {{ \Carbon\Carbon::parse($row['created_at'])->setTimezone('America/Sao_Paulo')->format('d/m H:i') }}
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex rounded-full bg-blue-100 px-2 py-0.5 text-xs font-semibold text-blue-700 dark:bg-blue-500/10 dark:text-blue-400 font-mono">
                                {{ $row['kind'] ?? '—' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300 max-w-xs truncate" title="{{ $row['subject'] ?? '' }}">
                            {{ $row['subject'] ?? '—' }}
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400 max-w-sm truncate font-mono" title="{{ $row['detail'] ?? '' }}">
                            {{ $row['detail'] ? \Str::limit($row['detail'], 80) : '—' }}
                        </td>
                        <td class="px-4 py-3">
                            @if($row['resolved'])
                            <span class="inline-flex rounded-full bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-700 dark:bg-green-500/10 dark:text-green-400">
                                Resolvida
                            </span>
                            @else
                            <span class="inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700 dark:bg-amber-500/10 dark:text-amber-400">
                                Pendente
                            </span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if(!$row['resolved'])
                            <x-filament::button
                                wire:click="markResolved({{ $row['id'] }})"
                                size="xs"
                                color="success"
                                icon="heroicon-o-check"
                            >
                                Resolver
                            </x-filament::button>
                            @else
                            <span class="text-xs text-gray-400 dark:text-gray-600 font-mono">
                                {{ \Carbon\Carbon::parse($row['resolved_at'])->setTimezone('America/Sao_Paulo')->format('d/m H:i') }}
                            </span>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                            Nenhuma divergência registrada.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <p class="text-xs text-gray-400 dark:text-gray-600">
            Exibindo as últimas 50 divergências. Tabela: <code class="font-mono">tenant_divergence_log</code>.
        </p>

    </div>
</x-filament-panels::page>
