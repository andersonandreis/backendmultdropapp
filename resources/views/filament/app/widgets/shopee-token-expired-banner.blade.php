@if($expiredCount > 0)
<div class="rounded-xl border border-orange-300/60 bg-orange-50 dark:bg-orange-950/30 dark:border-orange-700/50 px-4 py-3 flex items-center justify-between gap-3">
    <div class="flex items-center gap-2 text-sm text-orange-800 dark:text-orange-200">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 flex-shrink-0">
            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 10.5V6.75a4.5 4.5 0 1 1 9 0v3.75M3.75 21.75h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H3.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
        </svg>
        <span>
            Sua integração com a <strong>Shopee</strong> está desconectada. Acesse <strong>Integrações &rsaquo; Shopee</strong> e clique em <strong>Reconectar</strong> para continuar vendendo.
        </span>
    </div>
    <a
        href="{{ url('/app/minhas-lojas') }}"
        class="text-xs font-semibold text-orange-700 dark:text-orange-300 underline whitespace-nowrap hover:text-orange-900 dark:hover:text-orange-100 transition-colors"
    >
        Reconectar agora &rarr;
    </a>
</div>
@endif
