<div class="flex items-center gap-2 w-full flex-1 mr-auto max-w-lg">
    <div class="shrink-0">
        <select wire:model.live="tableFilters.custom_search.search_by"
            class="text-sm border-gray-300 rounded-lg shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:bg-gray-900 dark:border-white/10 dark:text-white py-2 pl-3 pr-8">
            <option value="name">Nome do Produto</option>
            <option value="sku">Referência/SKU</option>
        </select>
    </div>
    <div class="relative w-full">
        <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
            <x-heroicon-m-magnifying-glass class="w-4 h-4 text-gray-400" />
        </div>
        <input wire:model.live.debounce.500ms="tableFilters.custom_search.search_term" type="text"
            class="block w-full pl-9 pr-3 py-2 text-sm border-gray-300 rounded-lg shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:bg-gray-900 dark:border-white/10 dark:text-white"
            placeholder="Pesquisar catálogo...">
    </div>
</div>