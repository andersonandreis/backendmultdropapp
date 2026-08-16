<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center gap-2">
                <x-heroicon-o-megaphone class="w-5 h-5 text-blue-500" />
                Trafego Pago &mdash; Meta Ads
            </div>
        </x-slot>
        <x-slot name="description">
            @if($configured)
                Ultima leitura: <span class="font-mono">{{ $lastUpdated }}</span> &bull; Cache 5min
            @else
                Configure as variaveis de ambiente para ativar
            @endif
        </x-slot>
        <x-slot name="headerEnd">
            <x-filament::button wire:click="loadData" color="gray" size="sm" icon="heroicon-o-arrow-path">Atualizar</x-filament::button>
        </x-slot>

        <div wire:poll.300s="loadData">

            @if(!$configured)
                <div class="flex items-start gap-4 py-6 px-4 bg-blue-50 dark:bg-blue-950/30 rounded-xl border border-blue-200 dark:border-blue-800/50">
                    <x-heroicon-o-information-circle class="w-6 h-6 text-blue-500 shrink-0 mt-0.5" />
                    <div>
                        <p class="font-semibold text-blue-800 dark:text-blue-300">Meta Ads nao configurado</p>
                        <p class="text-sm text-blue-700 dark:text-blue-400 mt-1">Adicione as variaveis no <code class="bg-blue-100 dark:bg-blue-900/50 px-1.5 py-0.5 rounded text-xs">.env</code> do servidor:</p>
                        <pre class="mt-3 text-xs bg-gray-900 text-emerald-400 p-3 rounded-lg overflow-x-auto">META_ADS_ACCESS_TOKEN=seu_token_aqui
META_ADS_HUB_ACCOUNT_ID=act_xxxxxxxxx
META_ADS_TOKFY_ACCOUNT_ID=act_xxxxxxxxx</pre>
                    </div>
                </div>

            @else
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                    @if(!empty($hubAds))
                    <div class="bg-gray-50 dark:bg-gray-800/50 rounded-xl p-4 space-y-3">
                        <div class="flex items-center gap-2">
                            <div class="w-2 h-2 bg-emerald-500 rounded-full"></div>
                            <h4 class="font-semibold text-gray-800 dark:text-gray-200">HubAI Ads</h4>
                            @if(!empty($hubAds['error']))
                                <span class="text-xs text-red-500 ml-auto">{{ $hubAds['error'] }}</span>
                            @endif
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div class="bg-white dark:bg-gray-900/50 rounded-lg p-2.5">
                                <p class="text-xs text-gray-500 dark:text-gray-400">Gasto Hoje</p>
                                <p class="text-lg font-bold font-mono">R$ {{ number_format($hubAds['spend_today'] ?? 0, 2, ',', '.') }}</p>
                            </div>
                            <div class="bg-white dark:bg-gray-900/50 rounded-lg p-2.5">
                                <p class="text-xs text-gray-500 dark:text-gray-400">Gasto 7 Dias</p>
                                <p class="text-lg font-bold font-mono">R$ {{ number_format($hubAds['spend_7d'] ?? 0, 2, ',', '.') }}</p>
                            </div>
                            <div class="bg-white dark:bg-gray-900/50 rounded-lg p-2.5">
                                <p class="text-xs text-gray-500 dark:text-gray-400">Leads Hoje</p>
                                <p class="text-lg font-bold text-emerald-600 dark:text-emerald-400 font-mono">{{ number_format($hubAds['leads_today'] ?? 0) }}</p>
                            </div>
                            <div class="bg-white dark:bg-gray-900/50 rounded-lg p-2.5">
                                <p class="text-xs text-gray-500 dark:text-gray-400">CPA Hoje</p>
                                <p class="text-lg font-bold font-mono">
                                    @if(($hubAds['leads_today'] ?? 0) > 0)
                                        R$ {{ number_format($hubAds['cpa_today'] ?? 0, 2, ',', '.') }}
                                    @else &mdash; @endif
                                </p>
                            </div>
                            <div class="bg-white dark:bg-gray-900/50 rounded-lg p-2.5">
                                <p class="text-xs text-gray-500 dark:text-gray-400">Impressoes</p>
                                <p class="text-base font-bold font-mono">{{ number_format($hubAds['impressions'] ?? 0) }}</p>
                            </div>
                            <div class="bg-white dark:bg-gray-900/50 rounded-lg p-2.5">
                                <p class="text-xs text-gray-500 dark:text-gray-400">Cliques</p>
                                <p class="text-base font-bold font-mono">{{ number_format($hubAds['clicks'] ?? 0) }}</p>
                            </div>
                        </div>
                    </div>
                    @endif

                    @if(!empty($tokfyAds))
                    <div class="bg-gray-50 dark:bg-gray-800/50 rounded-xl p-4 space-y-3">
                        <div class="flex items-center gap-2">
                            <div class="w-2 h-2 bg-blue-500 rounded-full"></div>
                            <h4 class="font-semibold text-gray-800 dark:text-gray-200">Tokfy Ads</h4>
                            @if(!empty($tokfyAds['error']))
                                <span class="text-xs text-red-500 ml-auto">{{ $tokfyAds['error'] }}</span>
                            @endif
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div class="bg-white dark:bg-gray-900/50 rounded-lg p-2.5">
                                <p class="text-xs text-gray-500 dark:text-gray-400">Gasto Hoje</p>
                                <p class="text-lg font-bold font-mono">R$ {{ number_format($tokfyAds['spend_today'] ?? 0, 2, ',', '.') }}</p>
                            </div>
                            <div class="bg-white dark:bg-gray-900/50 rounded-lg p-2.5">
                                <p class="text-xs text-gray-500 dark:text-gray-400">Gasto 7 Dias</p>
                                <p class="text-lg font-bold font-mono">R$ {{ number_format($tokfyAds['spend_7d'] ?? 0, 2, ',', '.') }}</p>
                            </div>
                            <div class="bg-white dark:bg-gray-900/50 rounded-lg p-2.5">
                                <p class="text-xs text-gray-500 dark:text-gray-400">Leads Hoje</p>
                                <p class="text-lg font-bold text-blue-600 dark:text-blue-400 font-mono">{{ number_format($tokfyAds['leads_today'] ?? 0) }}</p>
                            </div>
                            <div class="bg-white dark:bg-gray-900/50 rounded-lg p-2.5">
                                <p class="text-xs text-gray-500 dark:text-gray-400">CPA Hoje</p>
                                <p class="text-lg font-bold font-mono">
                                    @if(($tokfyAds['leads_today'] ?? 0) > 0)
                                        R$ {{ number_format($tokfyAds['cpa_today'] ?? 0, 2, ',', '.') }}
                                    @else &mdash; @endif
                                </p>
                            </div>
                            <div class="bg-white dark:bg-gray-900/50 rounded-lg p-2.5">
                                <p class="text-xs text-gray-500 dark:text-gray-400">Impressoes</p>
                                <p class="text-base font-bold font-mono">{{ number_format($tokfyAds['impressions'] ?? 0) }}</p>
                            </div>
                            <div class="bg-white dark:bg-gray-900/50 rounded-lg p-2.5">
                                <p class="text-xs text-gray-500 dark:text-gray-400">Cliques</p>
                                <p class="text-base font-bold font-mono">{{ number_format($tokfyAds['clicks'] ?? 0) }}</p>
                            </div>
                        </div>
                    </div>
                    @endif

                    @if(empty($hubAds) && empty($tokfyAds))
                    <div class="md:col-span-2 text-center py-6 text-gray-400">
                        <p>Nenhuma conta Meta Ads com ID configurado</p>
                    </div>
                    @endif

                </div>
            @endif

        </div>
    </x-filament::section>
</x-filament-widgets::widget>