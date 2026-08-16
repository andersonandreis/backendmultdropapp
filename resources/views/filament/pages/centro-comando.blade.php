<x-filament-panels::page>
    <div class="space-y-4">

        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
                <div class="w-2 h-2 bg-emerald-500 rounded-full animate-pulse"></div>
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                    Sistema em tempo real &mdash; auto-refresh 30s
                </span>
            </div>
            <span class="text-xs text-gray-400 dark:text-gray-500 font-mono">
                {{ now()->setTimezone('America/Sao_Paulo')->format('d/m/Y H:i:s') }}
            </span>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">

            {{-- Linha 1: Servidor (full width) --}}
            <div class="xl:col-span-2">
                @livewire('App\Filament\Widgets\ServerHealthWidget')
            </div>

            {{-- Linha 2: Filas + Financeiro --}}
            <div>
                @livewire('App\Filament\Widgets\QueueHealthWidget')
            </div>

            <div>
                @livewire('App\Filament\Widgets\FinancialSummaryWidget')
            </div>

            {{-- Linha 3: Jobs + Robo --}}
            <div>
                @livewire('App\Filament\Widgets\JobStatsWidget')
            </div>

            <div>
                @livewire('App\Filament\Widgets\RoboCadastroMiniWidget')
            </div>

            {{-- Linha 4: Meta Ads (full width) --}}
            <div class="xl:col-span-2">
                @livewire('App\Filament\Widgets\MetaAdsWidget')
            </div>

        </div>

    </div>
</x-filament-panels::page>
