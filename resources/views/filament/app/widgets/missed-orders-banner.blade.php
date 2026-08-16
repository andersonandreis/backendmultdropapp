@if($pendingCount > 0)
<div class="rounded-xl border border-amber-300/60 bg-amber-50 dark:bg-amber-950/30 dark:border-amber-700/50 px-4 py-3 flex items-center justify-between gap-3">
    <div class="flex items-center gap-2 text-sm text-amber-800 dark:text-amber-200">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 flex-shrink-0">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
        </svg>
        <span>
            Identificamos <strong>{{ $pendingCount }} {{ $pendingCount === 1 ? 'possivel venda nao capturada' : 'possiveis vendas nao capturadas' }}</strong> pelo sistema.
        </span>
    </div>
    <a
        href="{{ \App\Filament\App\Pages\MissedOrdersPage::getUrl() }}"
        class="text-xs font-semibold text-amber-700 dark:text-amber-300 underline whitespace-nowrap hover:text-amber-900 dark:hover:text-amber-100 transition-colors"
    >
        Ver alertas &rarr;
    </a>
</div>
@endif
