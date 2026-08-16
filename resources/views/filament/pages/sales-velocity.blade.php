<x-filament-panels::page>
    <div class="space-y-6">
        <div class="flex items-center gap-2">
            <span class="text-sm text-gray-600">Janela:</span>
            @foreach([7,30,90,180] as $d)
                <x-filament::button
                    :color="$daysWindow == $d ? 'primary' : 'gray'"
                    size="sm"
                    wire:click="changeWindow({{ $d }})">{{ $d }} dias</x-filament::button>
            @endforeach
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4">
                <h3 class="text-lg font-semibold mb-3 text-green-700">Top 20 — Vendem mais rápido</h3>
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-2 py-1 text-left">SKU</th>
                            <th class="px-2 py-1 text-left">Produto</th>
                            <th class="px-2 py-1 text-right">Qtd</th>
                            <th class="px-2 py-1 text-right">/dia</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($fastest as $row)
                            <tr class="border-t hover:bg-gray-50 dark:hover:bg-gray-700">
                                <td class="px-2 py-1 font-mono text-xs">{{ $row['sku'] }}</td>
                                <td class="px-2 py-1">{{ Str::limit($row['name'], 35) }}</td>
                                <td class="px-2 py-1 text-right">{{ $row['qty_sold'] }}</td>
                                <td class="px-2 py-1 text-right font-bold text-green-600">{{ $row['velocity_per_day'] }}</td>
                            </tr>
                        @endforeach
                        @if(empty($fastest))
                            <tr><td colspan="4" class="text-center py-3 text-gray-500">Sem dados.</td></tr>
                        @endif
                    </tbody>
                </table>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4">
                <h3 class="text-lg font-semibold mb-3 text-red-700">Top 20 — Estão parados</h3>
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-2 py-1 text-left">SKU</th>
                            <th class="px-2 py-1 text-left">Produto</th>
                            <th class="px-2 py-1 text-right">Vendas</th>
                            <th class="px-2 py-1 text-right">Dias</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($slowest as $row)
                            <tr class="border-t hover:bg-gray-50 dark:hover:bg-gray-700">
                                <td class="px-2 py-1 font-mono text-xs">{{ $row['sku'] }}</td>
                                <td class="px-2 py-1">{{ Str::limit($row['name'], 35) }}</td>
                                <td class="px-2 py-1 text-right">{{ $row['qty_sold'] }}</td>
                                <td class="px-2 py-1 text-right text-red-600">{{ $row['days_in_catalog'] }}</td>
                            </tr>
                        @endforeach
                        @if(empty($slowest))
                            <tr><td colspan="4" class="text-center py-3 text-gray-500">Sem dados.</td></tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-filament-panels::page>
