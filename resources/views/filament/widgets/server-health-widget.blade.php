<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center gap-2">
                <x-heroicon-o-server class="w-5 h-5 text-emerald-500" />
                Saude do Servidor
            </div>
        </x-slot>
        <x-slot name="description">
            Ultima leitura: <span class="font-mono">{{ $lastUpdated }}</span>
        </x-slot>
        <x-slot name="headerEnd">
            <x-filament::button wire:click="loadData" color="gray" size="sm" icon="heroicon-o-arrow-path">
                Atualizar
            </x-filament::button>
        </x-slot>

        <div style="display:grid; grid-template-columns: repeat(4, 1fr); gap: 1rem;" wire:poll.30s="loadData">

            @php $cpu = $serverData['cpu'] ?? 0; @endphp
            <div class="bg-gray-50 dark:bg-gray-800/50 rounded-xl p-4 space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">CPU</span>
                    <span class="text-lg font-bold tabular-nums {{ $cpu >= 80 ? 'text-red-600 dark:text-red-400' : ($cpu >= 60 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400') }}">{{ $cpu }}%</span>
                </div>
                <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                    <div class="h-2 rounded-full {{ $cpu >= 80 ? 'bg-red-500' : ($cpu >= 60 ? 'bg-amber-500' : 'bg-emerald-500') }}" style="width: {{ min($cpu, 100) }}%"></div>
                </div>
            </div>

            @php $ram = $serverData['ram_pct'] ?? 0; $ramUsed = $serverData['ram_used'] ?? 0; $ramTotal = $serverData['ram_total'] ?? 0; @endphp
            <div class="bg-gray-50 dark:bg-gray-800/50 rounded-xl p-4 space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">RAM</span>
                    <span class="text-lg font-bold tabular-nums {{ $ram >= 80 ? 'text-red-600 dark:text-red-400' : ($ram >= 60 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400') }}">{{ $ram }}%</span>
                </div>
                <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                    <div class="h-2 rounded-full {{ $ram >= 80 ? 'bg-red-500' : ($ram >= 60 ? 'bg-amber-500' : 'bg-emerald-500') }}" style="width: {{ min($ram, 100) }}%"></div>
                </div>
                <p class="text-xs text-gray-400 dark:text-gray-500 font-mono">{{ $ramUsed }}GB / {{ $ramTotal }}GB</p>
            </div>

            @php $disk = $serverData['disk_pct'] ?? 0; $diskUsed = $serverData['disk_used'] ?? 0; $diskTotal = $serverData['disk_total'] ?? 0; @endphp
            <div class="bg-gray-50 dark:bg-gray-800/50 rounded-xl p-4 space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Disco</span>
                    <span class="text-lg font-bold tabular-nums {{ $disk >= 80 ? 'text-red-600 dark:text-red-400' : ($disk >= 60 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400') }}">{{ $disk }}%</span>
                </div>
                <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                    <div class="h-2 rounded-full {{ $disk >= 80 ? 'bg-red-500' : ($disk >= 60 ? 'bg-amber-500' : 'bg-emerald-500') }}" style="width: {{ min($disk, 100) }}%"></div>
                </div>
                <p class="text-xs text-gray-400 dark:text-gray-500 font-mono">{{ $diskUsed }}GB / {{ $diskTotal }}GB</p>
            </div>

            <div class="bg-gray-50 dark:bg-gray-800/50 rounded-xl p-4 space-y-2">
                <span class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Uptime</span>
                <div class="flex items-center gap-2 mt-2">
                    <div class="w-2 h-2 bg-emerald-500 rounded-full animate-pulse"></div>
                    <span class="text-2xl font-bold text-emerald-600 dark:text-emerald-400 font-mono">{{ $serverData['uptime'] ?? '?' }}</span>
                </div>
                <p class="text-xs text-gray-400 dark:text-gray-500">Servidor operacional</p>
            </div>

        </div>
    </x-filament::section>
</x-filament-widgets::widget>