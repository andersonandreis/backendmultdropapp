@if($hasAccountError)
<div class="rounded-xl border-2 {{ $isBlocked ? 'border-red-500 bg-gradient-to-r from-red-100 to-red-50 dark:from-red-900/50 dark:to-red-900/30 dark:border-red-600' : 'border-orange-400 bg-gradient-to-r from-red-50 to-orange-50 dark:from-red-900/30 dark:to-orange-900/30 dark:border-orange-600' }} p-6 mb-6 shadow-lg">
    <div class="flex items-start gap-4">
        {{-- Ícone grande de alerta --}}
        <div class="flex-shrink-0">
            <div class="w-14 h-14 rounded-full {{ $isBlocked ? 'bg-red-200 dark:bg-red-800' : 'bg-orange-100 dark:bg-red-800' }} flex items-center justify-center shadow-inner">
                <svg class="w-8 h-8 {{ $isBlocked ? 'text-red-700 dark:text-red-300' : 'text-orange-600 dark:text-orange-300' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                </svg>
            </div>
        </div>

        <div class="flex-1 min-w-0">
            <div class="flex items-center gap-3 flex-wrap">
                <h3 class="text-xl font-bold {{ $isBlocked ? 'text-red-900 dark:text-red-100' : 'text-red-800 dark:text-red-200' }}">
                    {{ $isBlocked ? 'Sincronizacao pausada — problema persiste' : 'Sua conta no Mercado Livre precisa de atencao' }}
                </h3>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold {{ $isBlocked ? 'bg-red-600 text-white' : 'bg-orange-500 text-white' }} uppercase tracking-wide">
                    {{ $isBlocked ? 'BLOQUEADO' : 'ACAO NECESSARIA' }}
                </span>
            </div>

            <p class="mt-2 text-sm font-medium {{ $isBlocked ? 'text-red-700 dark:text-red-300' : 'text-red-700 dark:text-red-300' }}">
                {{ $errorMessage }}
            </p>

            @if($isBlocked)
                <div class="mt-4 p-4 bg-red-100 dark:bg-red-900/60 rounded-lg border border-red-300 dark:border-red-700">
                    <p class="text-sm font-semibold text-red-900 dark:text-red-100">
                        Novas publicacoes estao bloqueadas ate que o problema seja resolvido.
                    </p>
                    <p class="text-xs text-red-700 dark:text-red-400 mt-1">
                        Acesse <a href="https://www.mercadolivre.com.br/perfil" target="_blank" class="underline font-semibold">mercadolivre.com.br</a>, corrija o problema na sua conta e entre em contato com o suporte se necessario.
                    </p>
                </div>
            @else
                <div class="mt-2 text-xs text-red-600 dark:text-red-400 flex items-center gap-1">
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span>Acesse <a href="https://www.mercadolivre.com.br/perfil" target="_blank" class="underline font-semibold">mercadolivre.com.br</a> para corrigir o problema antes de tentar novamente.</span>
                </div>

                <div class="mt-4 flex items-center gap-4 flex-wrap">
                    <button
                        wire:click="retryAfterFix"
                        wire:loading.attr="disabled"
                        wire:loading.class="opacity-60 cursor-not-allowed"
                        class="inline-flex items-center gap-2 px-5 py-2.5 bg-green-600 hover:bg-green-700 active:bg-green-800 text-white text-sm font-bold rounded-lg shadow-md transition-all focus:ring-2 focus:ring-green-500 focus:ring-offset-2"
                    >
                        <svg wire:loading.remove wire:target="retryAfterFix" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                        <svg wire:loading wire:target="retryAfterFix" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span wire:loading.remove wire:target="retryAfterFix">Ja resolvi, tentar novamente</span>
                        <span wire:loading wire:target="retryAfterFix">Tentando...</span>
                    </button>

                    <span class="text-xs text-gray-500 dark:text-gray-400 max-w-xs">
                        Corrija o problema na sua conta no Mercado Livre primeiro, depois clique para reenviar.
                    </span>
                </div>
            @endif
        </div>
    </div>
</div>
@endif
