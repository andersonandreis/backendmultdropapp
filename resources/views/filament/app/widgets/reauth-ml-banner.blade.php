@if($countNeedsReauth > 0)
<div class="rounded-xl border border-orange-300/60 bg-orange-50 dark:bg-orange-950/30 dark:border-orange-700/50 px-4 py-3 flex items-center justify-between gap-3">
    <div class="flex items-center gap-3 text-sm text-orange-800 dark:text-orange-200">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 flex-shrink-0 text-orange-500">
            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
        </svg>
        <span>
            <strong>{{ $countNeedsReauth === 1 ? 'Uma conta' : $countNeedsReauth . ' contas' }} do Mercado Livre {{ $countNeedsReauth === 1 ? 'precisa' : 'precisam' }} ser reconectada{{ $countNeedsReauth === 1 ? '' : 's' }}.</strong>
            Acesse <strong>Minhas Lojas</strong> e clique em <strong>Reconectar</strong> para restaurar a sincronizacao de pedidos.
        </span>
    </div>
    <a
        href="{{ \App\Filament\App\Resources\MarketplaceAccountResource::getUrl('index') }}"
        class="text-xs font-semibold text-orange-700 dark:text-orange-300 underline whitespace-nowrap hover:text-orange-900 dark:hover:text-orange-100 transition-colors"
    >
        Ir para Minhas Lojas &rarr;
    </a>
</div>
@endif