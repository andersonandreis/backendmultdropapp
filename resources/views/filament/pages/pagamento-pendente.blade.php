<x-filament-panels::page.simple>
    <div class="flex flex-col items-center text-center gap-y-4">
        <div class="flex h-14 w-14 items-center justify-center rounded-full bg-danger-500/10">
            <x-filament::icon
                icon="heroicon-o-exclamation-triangle"
                class="h-8 w-8 text-danger-600 dark:text-danger-400"
            />
        </div>

        <div class="space-y-1">
            <h1 class="text-lg font-semibold text-gray-950 dark:text-white">
                Pagamento Pendente
            </h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                O acesso ao painel administrativo desta White Label está temporariamente
                suspenso por pendência financeira.
            </p>
        </div>

        <div class="w-full rounded-lg bg-gray-50 dark:bg-white/5 p-4 text-sm text-gray-600 dark:text-gray-300">
            Regularize o pagamento com o suporte HubAI para reativar o painel. Seus
            clientes finais e as vendas continuam funcionando normalmente — este bloqueio
            afeta apenas o seu acesso administrativo.
        </div>

        <x-filament::button
            wire:click="verificarPagamento"
            icon="heroicon-o-arrow-path"
            color="primary"
        >
            Já regularizei, verificar novamente
        </x-filament::button>

        <x-filament::link :href="filament()->getLogoutUrl()" tag="a">
            Sair
        </x-filament::link>
    </div>
</x-filament-panels::page.simple>
