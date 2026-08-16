<x-filament-panels::page>
    @if(auth()->user()?->client?->isStartPlan())
        {{-- Elemento 4: Upsell card para clientes Start --}}
        <div class="rounded-xl border border-amber-300 bg-amber-50 dark:bg-amber-950/40 dark:border-amber-700 p-6 flex flex-col items-center text-center gap-4">
            <x-heroicon-o-lock-closed class="w-12 h-12 text-amber-500" />
            <div>
                <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-1">Funcionalidade exclusiva do plano Pro</h2>
                <p class="text-sm text-gray-600 dark:text-gray-300 max-w-md">
                    Com <strong>Vendas Perdidas</strong> voce detecta pedidos do marketplace que nao chegaram via webhook e os registra manualmente — sem perder receita.
                </p>
            </div>
            <a href="/app/pricing"
               class="inline-flex items-center gap-2 rounded-lg bg-amber-500 hover:bg-amber-600 text-white font-semibold px-5 py-2.5 text-sm transition-colors">
                <x-heroicon-o-arrow-up-circle class="w-4 h-4" />
                Fazer upgrade para Pro
            </a>
            <p class="text-xs text-gray-400 dark:text-gray-500">Plano atual: Start R$29,90/mes</p>
        </div>
    @else
        {{-- Banner informativo --}}
        <div class="rounded-xl border border-amber-300/50 bg-amber-50 dark:bg-amber-950/30 dark:border-amber-700/40 p-4 mb-4 flex items-start gap-3">
            <x-heroicon-o-exclamation-triangle class="w-5 h-5 text-amber-500 mt-0.5 flex-shrink-0" />
            <div class="text-sm text-amber-800 dark:text-amber-200">
                <strong>O que sao vendas possivelmente nao capturadas?</strong>
                Detectamos pedidos no marketplace que podem nao ter chegado via webhook. Revise cada alerta e registre manualmente os que forem reais, ou descarte os que nao se aplicam.
            </div>
        </div>

        {{ $this->table }}
    @endif
</x-filament-panels::page>
