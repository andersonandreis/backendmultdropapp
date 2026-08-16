<x-filament-panels::page>
    @php
        $suppliers = $this->getSuppliers();
        $data = $this->getProducts();
        $items = $data['items'] ?? [];
        $total = $data['total'] ?? 0;
        $currentPage = $data['page'] ?? 1;
        $lastPage = $data['lastPage'] ?? 1;
    @endphp

    {{-- Seletor de Fornecedor --}}
    @if(!$this->selectedSupplier)
        <div class="space-y-4">
            <p class="text-sm text-gray-500 dark:text-gray-400">Selecione um fornecedor para ver seus produtos</p>

            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
                @foreach($suppliers as $supplier)
                    <button
                        wire:click="selectSupplier({{ $supplier['id'] }})"
                        class="flex flex-col items-center gap-2 p-4 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 hover:border-primary-500 hover:bg-primary-50 dark:hover:bg-primary-900/20 transition-all cursor-pointer text-center"
                    >
                        <div class="w-12 h-12 rounded-full bg-primary-100 dark:bg-primary-900/30 flex items-center justify-center text-primary-600 dark:text-primary-400 font-bold text-lg">
                            {{ strtoupper(substr($supplier['name'], 0, 2)) }}
                        </div>
                        <span class="text-xs font-semibold text-gray-900 dark:text-white leading-tight">{{ $supplier['name'] }}</span>
                        <span class="text-[10px] font-medium px-2 py-0.5 rounded-full bg-primary-100 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300">
                            {{ number_format($supplier['count']) }} produtos
                        </span>
                    </button>
                @endforeach
            </div>
        </div>
    @else
        {{-- Header com fornecedor selecionado --}}
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-3">
                <button wire:click="clearSupplier" class="p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                    <x-heroicon-o-arrow-left class="w-5 h-5 text-gray-500" />
                </button>
                <div>
                    <h3 class="text-sm font-bold text-gray-900 dark:text-white">
                        @php
                            $selected = collect($suppliers)->firstWhere('id', $this->selectedSupplier);
                        @endphp
                        {{ $selected['name'] ?? 'Fornecedor' }}
                    </h3>
                    <p class="text-xs text-gray-500">{{ number_format($total) }} produtos</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                {{-- Toggle Grid/Lista --}}
                <button wire:click="toggleView" class="p-2 rounded-lg border border-gray-200 dark:border-gray-700 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                    @if($this->viewMode === 'grid')
                        <x-heroicon-o-list-bullet class="w-4 h-4 text-gray-500" />
                    @else
                        <x-heroicon-o-squares-2x2 class="w-4 h-4 text-gray-500" />
                    @endif
                </button>
            </div>
        </div>

        {{-- Busca --}}
        <div class="mb-4">
            <div class="relative max-w-md">
                <x-heroicon-o-magnifying-glass class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Buscar por nome, SKU ou EAN..."
                    class="w-full pl-10 pr-4 py-2 text-sm border border-gray-200 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                />
            </div>
        </div>

        {{-- Grid de Produtos (padrao seller catalog) --}}
        @if($this->viewMode === 'grid')
            @php $isDark = true; /* Filament default is dark */ @endphp
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px;">
                @forelse($items as $product)
                    <a href="{{ $product['edit_url'] }}" style="display: block; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.06); border-radius: 12px; overflow: hidden; transition: all 0.2s ease; text-decoration: none; color: inherit; position: relative;" onmouseenter="this.style.borderColor='rgba(16,185,129,0.4)'; this.style.transform='translateY(-2px)'; this.style.boxShadow='0 12px 28px rgba(0,0,0,0.25)';" onmouseleave="this.style.borderColor='rgba(255,255,255,0.06)'; this.style.transform='none'; this.style.boxShadow='none';">
                        {{-- Badges --}}
                        @if($product['stock'] <= 0)
                            <span style="position: absolute; top: 8px; left: 8px; z-index: 10; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; padding: 3px 8px; border-radius: 6px; background: rgba(239,68,68,0.85); color: #fff; backdrop-filter: blur(4px);">Sem estoque</span>
                        @endif
                        @if($product['images_count'] > 1)
                            <span style="position: absolute; top: 8px; right: 8px; z-index: 10; font-size: 9px; font-weight: 500; padding: 2px 7px; border-radius: 6px; background: rgba(0,0,0,0.5); color: rgba(255,255,255,0.85); backdrop-filter: blur(4px);">{{ $product['images_count'] }} fotos</span>
                        @endif

                        {{-- Imagem --}}
                        <div style="width: 100%; padding-bottom: 100%; position: relative; background: rgba(255,255,255,0.03);">
                            @if($product['image'])
                                <img src="{{ $product['image'] }}" alt="{{ $product['name'] }}" loading="lazy" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: cover; transition: transform 0.4s ease;" onmouseenter="this.style.transform='scale(1.04)';" onmouseleave="this.style.transform='scale(1)';" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
                                <div style="display: none; position: absolute; top: 0; left: 0; width: 100%; height: 100%; flex-direction: column; align-items: center; justify-content: center; gap: 6px; background: rgba(255,255,255,0.02);">
                                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.15)" stroke-width="1"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
                                    <span style="color: rgba(255,255,255,0.2); font-size: 10px;">Sem foto</span>
                                </div>
                            @else
                                <div style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 6px; background: rgba(255,255,255,0.02);">
                                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.15)" stroke-width="1"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
                                    <span style="color: rgba(255,255,255,0.2); font-size: 10px;">Sem foto</span>
                                </div>
                            @endif
                        </div>

                        {{-- Info --}}
                        <div style="padding: 10px 12px 12px;">
                            <p style="font-size: 12px; font-weight: 500; line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; min-height: 34px; margin: 0; color: rgba(255,255,255,0.85);">{{ $product['name'] }}</p>
                            <div style="display: flex; align-items: baseline; justify-content: space-between; margin-top: 6px;">
                                <span style="font-size: 14px; font-weight: 700; color: #34d399;">R$ {{ $product['price'] }}</span>
                                <span style="font-size: 10px; font-weight: 500; color: {{ $product['stock'] > 0 ? 'rgba(255,255,255,0.35)' : '#f87171' }};">{{ $product['stock'] }}</span>
                            </div>
                        </div>
                    </a>
                @empty
                    <div style="grid-column: 1 / -1; text-align: center; padding: 64px 0; color: rgba(255,255,255,0.3);">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" style="margin: 0 auto 12px;"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8m-4-4v4"/></svg>
                        <p style="font-size: 13px; font-weight: 500;">Nenhum produto encontrado</p>
                    </div>
                @endforelse
            </div>
        @else
            {{-- Lista --}}
            <div style="border-radius: 12px; overflow: hidden; border: 1px solid rgba(255,255,255,0.06);">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.08);">
                            <th style="width: 80px; padding: 14px 20px; text-align: left; font-size: 12px; font-weight: 600; color: rgba(255,255,255,0.45);">Foto</th>
                            <th style="padding: 14px 20px; text-align: left; font-size: 12px; font-weight: 600; color: rgba(255,255,255,0.45);">Produto</th>
                            <th style="padding: 14px 20px; text-align: left; font-size: 12px; font-weight: 600; color: rgba(255,255,255,0.45);">SKU</th>
                            <th style="width: 110px; padding: 14px 20px; text-align: right; font-size: 12px; font-weight: 600; color: rgba(255,255,255,0.45);">Custo</th>
                            <th style="width: 110px; padding: 14px 20px; text-align: right; font-size: 12px; font-weight: 600; color: rgba(255,255,255,0.45);">Preco</th>
                            <th style="width: 80px; padding: 14px 20px; text-align: center; font-size: 12px; font-weight: 600; color: rgba(255,255,255,0.45);">Estoque</th>
                            <th style="width: 50px; padding: 14px 12px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($items as $product)
                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.04); transition: background 0.15s;" onmouseenter="this.style.background='rgba(255,255,255,0.03)';" onmouseleave="this.style.background='transparent';">
                                <td style="padding: 12px 20px;">
                                    <div style="width: 56px; height: 56px; border-radius: 10px; overflow: hidden; background: rgba(255,255,255,0.04);">
                                        @if($product['image'])
                                            <img src="{{ $product['image'] }}" loading="lazy" style="width: 100%; height: 100%; object-fit: cover;" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
                                            <div style="display: none; width: 100%; height: 100%; align-items: center; justify-content: center;">
                                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.15)" stroke-width="1"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
                                            </div>
                                        @else
                                            <div style="width: 100%; height: 100%; display: flex; align-items: center; justify-content: center;">
                                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.15)" stroke-width="1"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
                                            </div>
                                        @endif
                                    </div>
                                </td>
                                <td style="padding: 12px 20px;">
                                    <a href="{{ $product['edit_url'] }}" style="text-decoration: none; color: inherit;">
                                        <p style="font-size: 14px; font-weight: 500; color: rgba(255,255,255,0.9); margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 450px;">{{ $product['name'] }}</p>
                                        @if($product['brand'])
                                            <p style="font-size: 12px; color: rgba(255,255,255,0.35); margin: 3px 0 0;">{{ $product['brand'] }}</p>
                                        @endif
                                    </a>
                                </td>
                                <td style="padding: 12px 20px;">
                                    <span style="font-size: 12px; color: rgba(255,255,255,0.3); font-family: monospace;">{{ $product['sku'] }}</span>
                                </td>
                                <td style="padding: 12px 20px; text-align: right;">
                                    <span style="font-size: 14px; color: rgba(255,255,255,0.45);">R$ {{ $product['cost'] }}</span>
                                </td>
                                <td style="padding: 12px 20px; text-align: right;">
                                    <span style="font-size: 14px; font-weight: 700; color: #34d399;">R$ {{ $product['price'] }}</span>
                                </td>
                                <td style="padding: 12px 20px; text-align: center;">
                                    <span style="font-size: 13px; font-weight: 600; color: {{ $product['stock'] > 0 ? 'rgba(255,255,255,0.5)' : '#f87171' }};">{{ $product['stock'] }}</span>
                                </td>
                                <td style="padding: 12px 12px; text-align: center;">
                                    <a href="{{ $product['edit_url'] }}" style="color: rgba(255,255,255,0.3); transition: color 0.15s;" onmouseenter="this.style.color='#34d399';" onmouseleave="this.style.color='rgba(255,255,255,0.3)';">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 64px 0; color: rgba(255,255,255,0.25);">
                                    <p style="font-size: 14px; font-weight: 500;">Nenhum produto encontrado</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Paginacao --}}
        @if($lastPage > 1)
            <div class="flex items-center justify-between mt-4">
                <p class="text-xs text-gray-500">
                    Mostrando {{ (($currentPage - 1) * $this->perPage) + 1 }} a {{ min($currentPage * $this->perPage, $total) }} de {{ number_format($total) }}
                </p>
                <div class="flex items-center gap-1">
                    <button wire:click="previousPage" @if($currentPage <= 1) disabled @endif class="px-3 py-1.5 text-xs rounded-lg border border-gray-200 dark:border-gray-700 hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-40 disabled:cursor-not-allowed transition-colors">
                        Anterior
                    </button>
                    @for($p = max(1, $currentPage - 2); $p <= min($lastPage, $currentPage + 2); $p++)
                        <button wire:click="goToPage({{ $p }})" class="px-3 py-1.5 text-xs rounded-lg border transition-colors {{ $p === $currentPage ? 'bg-primary-600 text-white border-primary-600' : 'border-gray-200 dark:border-gray-700 hover:bg-gray-100 dark:hover:bg-gray-700' }}">
                            {{ $p }}
                        </button>
                    @endfor
                    <button wire:click="nextPage" @if($currentPage >= $lastPage) disabled @endif class="px-3 py-1.5 text-xs rounded-lg border border-gray-200 dark:border-gray-700 hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-40 disabled:cursor-not-allowed transition-colors">
                        Proximo
                    </button>
                </div>
            </div>
        @endif
    @endif
</x-filament-panels::page>
