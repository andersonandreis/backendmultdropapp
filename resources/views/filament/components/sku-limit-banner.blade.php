{{--
    Banner exibido quando cliente atingiu o limite de SKUs do plano.
    Variaveis esperadas:
      $planName  string  — ex: "Start R$29,90"
      $maxSkus   int     — limite do plano
      $current   int     — quantidade atual
--}}
<div class="rounded-xl border-2 border-amber-400 bg-gradient-to-r from-amber-50 to-yellow-50 dark:from-amber-950/40 dark:to-yellow-950/30 dark:border-amber-600 p-6 mb-6 shadow-md">
    <div class="flex items-start gap-4">
        <div class="flex-shrink-0">
            <div class="w-14 h-14 rounded-full bg-amber-100 dark:bg-amber-900 flex items-center justify-center shadow-inner">
                <svg class="w-8 h-8 text-amber-600 dark:text-amber-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M13 10V3L4 14h7v7l9-11h-7z"/>
                </svg>
            </div>
        </div>

        <div class="flex-1 min-w-0">
            <div class="flex items-center gap-3 flex-wrap">
                <h3 class="text-xl font-bold text-amber-900 dark:text-amber-100">
                    Limite de produtos atingido
                </h3>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-500 text-white uppercase tracking-wide">
                    UPGRADE NECESSARIO
                </span>
            </div>

            <p class="mt-2 text-sm font-medium text-amber-800 dark:text-amber-300">
                Voce atingiu o limite de <strong>{{ $maxSkus }} produto(s)</strong>
                do plano <strong>{{ $planName }}</strong>.
                Cadastre mais produtos fazendo upgrade do seu plano.
            </p>

            <div class="mt-2 flex items-center gap-2 text-xs text-amber-700 dark:text-amber-400">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                </svg>
                <span>{{ $current }} de {{ $maxSkus }} produtos cadastrados</span>
            </div>

            <div class="mt-4 flex items-center gap-4 flex-wrap">
                <a
                    href="https://wa.me/5511999999999?text=Quero%20fazer%20upgrade%20do%20meu%20plano%20HubAI"
                    target="_blank"
                    class="inline-flex items-center gap-2 px-5 py-2.5 bg-amber-600 hover:bg-amber-700 active:bg-amber-800 text-white text-sm font-bold rounded-lg shadow-md transition-all focus:ring-2 focus:ring-amber-500 focus:ring-offset-2"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M5 10l7-7m0 0l7 7m-7-7v18"/>
                    </svg>
                    Fazer Upgrade
                </a>
                <span class="text-xs text-gray-500 dark:text-gray-400">
                    Entre em contato para ampliar seu catalogo
                </span>
            </div>
        </div>
    </div>
</div>
