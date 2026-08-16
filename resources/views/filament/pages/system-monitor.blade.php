<x-filament-panels::page>
    <div class="space-y-6">

        {{-- Alerta: tabela ainda nao existe --}}
        @if(!$tableReady)
        <div class="rounded-xl border border-amber-200 bg-amber-50 dark:border-amber-500/20 dark:bg-amber-500/10 p-4 flex items-start gap-3">
            <x-heroicon-o-exclamation-triangle class="w-5 h-5 text-amber-500 mt-0.5 shrink-0" />
            <div>
                <p class="text-sm font-semibold text-amber-800 dark:text-amber-400">Dados de logs indisponíveis</p>
                <p class="text-xs text-amber-700 dark:text-amber-500 mt-0.5">{{ $loadError }}</p>
            </div>
        </div>
        @endif

        {{-- Botao atualizar + ultima atualizacao --}}
        <div class="flex items-center justify-between">
            <div class="text-xs text-gray-400 dark:text-gray-500">
                Ultimo erro: <span class="font-mono text-gray-600 dark:text-gray-300">{{ $health['last_error'] }}</span>
            </div>
            <x-filament::button wire:click="refresh" color="gray" icon="heroicon-o-arrow-path">
                Atualizar
            </x-filament::button>
        </div>

        {{-- Cards de saude --}}
        <div class="grid grid-cols-2 md:grid-cols-7 gap-4">
            @php
            $cards = [
                ['label' => 'Erros 24h',      'value' => $stats24h['error']   ?? 0, 'danger' => ($stats24h['error']   ?? 0) > 0],
                ['label' => 'Warnings 24h',   'value' => $stats24h['warning'] ?? 0, 'warn'   => ($stats24h['warning'] ?? 0) > 0],
                ['label' => 'Jobs falhos',     'value' => $health['failed_jobs'],    'danger' => ($health['failed_jobs']) > 0],
                ['label' => 'Fila pendente',   'value' => $health['queue_size'],     'neutral' => true],
                ['label' => 'Log (MB)',        'value' => $health['log_size_mb'],    'info' => true],
                ['label' => 'Bot IA Pendentes', 'value' => $health['auto_listing_pending'] ?? 0, 'neutral' => true],
                ['label' => 'Bot IA Falhas',    'value' => $health['auto_listing_failed']  ?? 0, 'danger'  => ($health['auto_listing_failed'] ?? 0) > 0],
            ];
            @endphp
            @foreach($cards as $card)
            <div class="rounded-xl bg-white dark:bg-gray-900 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 p-4">
                <div class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ $card['label'] }}</div>
                <div class="mt-2 text-3xl font-bold tabular-nums
                    {{ !empty($card['danger']) ? 'text-red-600 dark:text-red-400' : '' }}
                    {{ !empty($card['warn'])   ? 'text-amber-500 dark:text-amber-400' : '' }}
                    {{ !empty($card['info'])   ? 'text-cyan-600 dark:text-cyan-400' : '' }}
                    {{ !empty($card['neutral']) ? 'text-gray-900 dark:text-white' : '' }}
                    {{ empty($card['danger']) && empty($card['warn']) && empty($card['info']) && empty($card['neutral']) ? 'text-gray-900 dark:text-white' : '' }}
                ">{{ $card['value'] }}</div>
            </div>
            @endforeach
        </div>

        {{-- Grid 2 colunas: por canal + top eventos --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

            {{-- Logs por canal --}}
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 p-4">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">Logs por canal (24h)</h3>
                @forelse($byChannel as $channel => $count)
                <div class="flex items-center justify-between py-1.5 border-b border-gray-100 dark:border-gray-800">
                    <span class="text-sm text-gray-600 dark:text-gray-400 font-mono">{{ $channel }}</span>
                    <span class="inline-flex items-center rounded-md bg-gray-100 dark:bg-gray-800 px-2 py-0.5 text-xs font-semibold text-gray-800 dark:text-gray-200">{{ $count }}</span>
                </div>
                @empty
                <p class="text-sm text-gray-400 dark:text-gray-500 italic">Nenhum log nas últimas 24h.</p>
                @endforelse
            </div>

            {{-- Top eventos de erro --}}
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 p-4">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">Top eventos de erro (24h)</h3>
                @forelse($topEvents as $ev)
                <div class="flex items-center justify-between py-1.5 border-b border-gray-100 dark:border-gray-800">
                    <span class="text-sm text-red-600 dark:text-red-400 font-mono truncate max-w-[70%]">{{ $ev['event'] }}</span>
                    <span class="inline-flex items-center rounded-md bg-red-50 dark:bg-red-950/50 px-2 py-0.5 text-xs font-bold text-red-700 dark:text-red-400">{{ $ev['total'] }}</span>
                </div>
                @empty
                <p class="text-sm text-green-600 dark:text-green-400 italic">Nenhum erro nas últimas 24h.</p>
                @endforelse
            </div>
        </div>

        {{-- Comparativo 24h vs 7d --}}
        <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 p-4">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">Comparativo de logs</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-xs text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                            <th class="text-left py-2 font-semibold uppercase tracking-wide">Nível</th>
                            <th class="text-right py-2 font-semibold uppercase tracking-wide">Últimas 24h</th>
                            <th class="text-right py-2 font-semibold uppercase tracking-wide">Últimos 7 dias</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach(['error' => 'Erro', 'warning' => 'Warning', 'info' => 'Info'] as $key => $label)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="py-2 text-gray-700 dark:text-gray-300">
                                <span class="inline-flex items-center gap-2">
                                    <span class="w-2 h-2 rounded-full inline-block {{ $key === 'error' ? 'bg-red-500' : ($key === 'warning' ? 'bg-amber-400' : 'bg-blue-400') }}"></span>
                                    {{ $label }}
                                </span>
                            </td>
                            <td class="py-2 text-right font-semibold tabular-nums {{ $key === 'error' && ($stats24h[$key] ?? 0) > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-900 dark:text-white' }}">
                                {{ $stats24h[$key] ?? 0 }}
                            </td>
                            <td class="py-2 text-right font-semibold tabular-nums {{ $key === 'error' && ($stats7d[$key] ?? 0) > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-900 dark:text-white' }}">
                                {{ $stats7d[$key] ?? 0 }}
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Ultimos erros --}}
        <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 p-4">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">Últimos erros</h3>
            @if(empty($recentErrors))
                <p class="text-sm text-green-600 dark:text-green-400 italic">Nenhum erro recente.</p>
            @else
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                            <th class="text-left py-2 font-semibold">Hora</th>
                            <th class="text-left py-2 font-semibold">Canal</th>
                            <th class="text-left py-2 font-semibold">Evento</th>
                            <th class="text-left py-2 font-semibold">Mensagem</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($recentErrors as $err)
                        <tr class="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors">
                            <td class="py-2 text-gray-500 dark:text-gray-400 whitespace-nowrap font-mono">
                                {{ \Carbon\Carbon::parse($err['created_at'])->setTimezone('America/Sao_Paulo')->format('d/m H:i:s') }}
                            </td>
                            <td class="py-2">
                                <span class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-800 rounded text-gray-700 dark:text-gray-300 font-mono text-[11px]">
                                    {{ $err['channel'] ?? '—' }}
                                </span>
                            </td>
                            <td class="py-2 font-mono text-red-600 dark:text-red-400 max-w-[160px] truncate">
                                {{ $err['event'] ?? '—' }}
                            </td>
                            <td class="py-2 text-gray-700 dark:text-gray-300">
                                {{ \Illuminate\Support\Str::limit($err['message'] ?? '', 100) }}
                                @if(!empty($err['context']))
                                <details class="mt-1">
                                    <summary class="cursor-pointer text-blue-500 hover:text-blue-600 dark:text-blue-400 text-[11px] select-none">Ver contexto</summary>
                                    <pre class="mt-1 text-[11px] bg-gray-100 dark:bg-gray-800 rounded-lg p-2 overflow-auto max-h-36 whitespace-pre-wrap break-all">{{ is_string($err['context']) ? json_encode(json_decode($err['context']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : json_encode($err['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                </details>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </div>

    </div>
</x-filament-panels::page>
