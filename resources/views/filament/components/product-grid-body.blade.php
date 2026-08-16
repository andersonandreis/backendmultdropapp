<div
    class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 p-4 lg:p-6 bg-gray-50 dark:bg-gray-900 border-t border-gray-200 dark:border-white/10 w-full overflow-hidden">
    @foreach ($records as $record)
        @php
            $isClientProduct = $record instanceof \App\Models\ClientProduct;
            $class = get_class($this);
            $imageUrl = $isClientProduct
                ? ($record->product?->media()->first()?->url ?? '')
                : ($record->media()->first()?->url ?? '');

            $sku = $isClientProduct ? $record->custom_sku : $record->sku;
            $title = $isClientProduct ? $record->custom_title : $record->name;
            $pricePrefix = 'R$ ';
            $priceRaw = $isClientProduct ? $record->custom_price : $record->price;
            $priceFormatted = $pricePrefix . number_format((float) $priceRaw, 2, ',', '.');

            $subtitle = '';
            if ($isClientProduct) {
                $subtitle = $record->marketplaceAccount?->account_name;
            } else if ($record instanceof \App\Models\Product) {
                $subtitle = $record->supplier?->company_name;
            }

            $viewActionName = $isClientProduct ? 'view' : (str_contains($class, 'SupplierProductList') ? 'view_details' : 'view');
            $recordId = $record->id;

            // Status sync para ClientProduct
            $syncStatus = $isClientProduct ? ($record->sync_status ?? 'draft') : null;
            $syncBadgeConfig = [
                'synced'  => ['label' => 'Publicado', 'classes' => 'bg-green-500 text-white'],
                'pending' => ['label' => 'Pendente',  'classes' => 'bg-yellow-400 text-gray-900'],
                'error'   => ['label' => 'Erro',      'classes' => 'bg-red-500 text-white'],
                'paused'  => ['label' => 'Pausado',   'classes' => 'bg-gray-400 text-white'],
                'draft'   => ['label' => 'Rascunho',  'classes' => 'bg-gray-300 text-gray-700'],
            ];
            $currentBadge = $syncBadgeConfig[$syncStatus] ?? $syncBadgeConfig['draft'];

            // Verificar se tem integração ativa para exibir botão Publicar
            $hasActiveIntegration = $isClientProduct
                && $record->marketplaceAccount
                && (
                    $record->marketplaceAccount->status === 'active'
                    || !empty($record->marketplaceAccount->ml_access_token)
                );

            // Badge Adicionado -- produto ja importado pelo seller
            $isAdded = false;
            if (!$isClientProduct && $record instanceof \App\Models\Product) {
                $user = auth()->user();
                $clientId = $user?->client_id ?? $user?->client?->id;
                if ($clientId) {
                    $isAdded = \App\Models\ClientProduct::where("client_id", $clientId)
                        ->where("product_id", $record->id)
                        ->exists();
                }
            }
        @endphp

        <div
            class="bg-white dark:bg-gray-800 rounded-3xl shadow-sm border border-gray-100 dark:border-gray-800 hover:shadow-lg transition-all hover:-translate-y-1 duration-300 w-full flex flex-col overflow-hidden h-full relative">

            {{-- Badge Adicionado para catalogo do fornecedor --}}
            @if($isAdded)
                <div class="absolute top-2 left-2 z-10">
                    <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-green-500 text-white text-xs font-bold rounded-full shadow-md">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                        Adicionado
                    </span>
                </div>
            @endif

            {{-- Badge da loja no canto superior direito --}}
            @if($subtitle)
                <div
                    class="absolute top-2 right-2 z-10 px-2.5 py-1 bg-blue-600 text-white text-xs font-bold rounded-lg shadow-md tracking-wide">
                    {{ $subtitle }}
                </div>
            @endif

            {{-- Badge de status sync no canto superior esquerdo --}}
            @if($isClientProduct && $syncStatus)
                <div class="absolute top-2 left-2 z-10">
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold {{ $currentBadge['classes'] }}"
                        @if($syncStatus === 'error' && $record->last_sync_error)
                            title="{{ $record->last_sync_error }}"
                        @endif
                    >
                        {{ $currentBadge['label'] }}
                    </span>
                    @if($syncStatus === 'error' && $record->last_sync_error)
                        <div class="mt-1 max-w-[200px] bg-red-50 dark:bg-red-900/50 border border-red-200 dark:border-red-800 rounded-lg px-2 py-1 text-xs text-red-700 dark:text-red-300 line-clamp-3 shadow-sm">
                            {{ Str::limit($record->last_sync_error, 120) }}
                        </div>
                    @endif
                </div>
            @endif

            <div class="h-48 w-full relative group cursor-pointer"
                wire:click="mountTableAction('{{ $viewActionName }}', '{{ $recordId }}')">
                @if($imageUrl)
                    <img src="{{ $imageUrl }}"
                        class="object-cover w-full h-full border-b border-gray-100 dark:border-gray-800" />
                @else
                    <div class="w-full h-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center text-gray-400">Sem
                        Imagem</div>
                @endif
                <div
                    class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-20 transition-all flex items-center justify-center opacity-0 group-hover:opacity-100">
                    <span
                        class="text-white bg-primary-600 px-3 py-1 rounded-full text-sm shadow-md flex items-center gap-1 font-semibold">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z">
                            </path>
                        </svg>
                        Ver Ficha
                    </span>
                </div>
            </div>

            <div class="p-4 flex flex-col justify-between flex-grow">
                <div>
                    <div class="text-xs font-bold text-gray-500 mb-1">#{{ $sku }}</div>
                    <div class="font-bold text-md text-gray-900 dark:text-gray-100 mb-2 line-clamp-2" title="{{ $title }}">
                        {{ $title }}
                    </div>
                </div>
                <div class="mt-auto pt-4 border-t border-gray-50 dark:border-gray-800/50 flex items-center justify-between gap-2 flex-wrap">
                    <div class="font-bold text-xl text-green-600 dark:text-green-400">
                        {{ $priceFormatted }}
                    </div>
                    <div class="flex items-center gap-1.5">
                        {{-- Botão Detalhes --}}
                        <button type="button" wire:click="mountTableAction('{{ $viewActionName }}', '{{ $recordId }}')"
                            class="inline-flex items-center px-3 py-1.5 border border-primary-200 dark:border-primary-900 shadow-sm text-xs font-semibold rounded-full text-primary-700 dark:text-primary-300 bg-primary-50 hover:bg-primary-100 focus:outline-none transition-colors dark:bg-primary-900/40 dark:hover:bg-primary-900/60">
                            Detalhes
                        </button>

                        {{-- Botão Editar — apenas para ClientProduct --}}
                        @if($isClientProduct)
                            <button type="button" wire:click="mountTableAction('edit_inline', '{{ $recordId }}')"
                                class="inline-flex items-center px-3 py-1.5 border border-gray-200 dark:border-gray-600 shadow-sm text-xs font-semibold rounded-full text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 focus:outline-none transition-colors">
                                <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z">
                                    </path>
                                </svg>
                                Editar
                            </button>
                        @endif

                        {{-- Botão Publicar — apenas se tem integração ativa --}}
                        @if($hasActiveIntegration)
                            <button type="button" wire:click="mountTableAction('publish_to_marketplace_grid', '{{ $recordId }}')"
                                title="Publicar no Marketplace"
                                class="inline-flex items-center justify-center w-8 h-8 border border-green-200 dark:border-green-900 shadow-sm rounded-full text-green-700 dark:text-green-300 bg-green-50 dark:bg-green-900/40 hover:bg-green-100 dark:hover:bg-green-900/60 focus:outline-none transition-colors">
                                {{-- Heroicon rocket-launch outline --}}
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M15.59 14.37a6 6 0 01-5.84 7.38v-4.82m5.84-2.56a14.98 14.98 0 006.16-12.12A14.98 14.98 0 009.631 8.41m5.96 5.96a14.926 14.926 0 01-5.841 2.58m-.119-8.54a6 6 0 00-7.381 5.84h4.818m2.596-5.98a14.984 14.984 0 016.025-8.12l.32-.17a.75.75 0 01.82.07l.093.086a.75.75 0 01.074.82l-.17.32a14.97 14.97 0 01-6.096 6.05z">
                                    </path>
                                </svg>
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endforeach
</div>
