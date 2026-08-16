<x-filament-panels::page>
    <div class="space-y-4">

        {{-- Filtros por categoria --}}
        <div class="flex gap-2 flex-wrap">
            @foreach($this->getCategorias() as $slug => $label)
                <button
                    wire:click="filtrarCategoria('{{ $slug }}')"
                    class="{{ $this->categoriaFiltro === $slug ? 'px-3 py-1.5 rounded-full text-sm font-medium bg-primary-600 text-white' : 'px-3 py-1.5 rounded-full text-sm font-medium bg-gray-100 text-gray-600 hover:bg-gray-200' }}"
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>

        @if(empty($this->chamados))
            <div class="rounded-xl bg-white border border-gray-200 shadow-sm p-8 text-center text-gray-400">
                <x-heroicon-o-lifebuoy class="mx-auto w-12 h-12 mb-3 opacity-40" />
                <p>Nenhum chamado encontrado. Clique em "Novo Chamado" para criar.</p>
            </div>
        @else
            <div class="rounded-xl bg-white border border-gray-200 shadow-sm overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                        <tr>
                            <th class="px-4 py-3 text-left">#</th>
                            <th class="px-4 py-3 text-left">Data</th>
                            <th class="px-4 py-3 text-left">Seller</th>
                            <th class="px-4 py-3 text-left">Categoria</th>
                            <th class="px-4 py-3 text-left">Titulo</th>
                            <th class="px-4 py-3 text-left">Prioridade</th>
                            <th class="px-4 py-3 text-left">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($this->chamados as $chamado)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 text-gray-400 font-mono text-xs">#{{ $chamado->id }}</td>
                                <td class="px-4 py-3 text-gray-500">{{ \Carbon\Carbon::parse($chamado->created_at)->format('d/m/Y H:i') }}</td>
                                <td class="px-4 py-3 font-medium text-gray-800">{{ $chamado->seller_name ?? '-' }}</td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                                        {{ $this->getLabelCategoria($chamado->category ?? 'other') }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-gray-700">{{ $chamado->title ?? '-' }}</td>
                                <td class="px-4 py-3">
                                    @php
                                        $prioridade = $chamado->priority ?? 'medium';
                                        $prClasses = match($prioridade) {
                                            'high'   => 'bg-red-100 text-red-700',
                                            'medium' => 'bg-yellow-100 text-yellow-700',
                                            'low'    => 'bg-blue-100 text-blue-700',
                                            default  => 'bg-gray-100 text-gray-600',
                                        };
                                        $prLabel = match($prioridade) {
                                            'high'   => 'Alta',
                                            'medium' => 'Media',
                                            'low'    => 'Baixa',
                                            default  => ucfirst($prioridade),
                                        };
                                    @endphp
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $prClasses }}">
                                        {{ $prLabel }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    @php
                                        $st = $chamado->status ?? 'new';
                                        $stClasses = match($st) {
                                            'new'         => 'bg-blue-100 text-blue-700',
                                            'in_progress' => 'bg-yellow-100 text-yellow-700',
                                            'resolved'    => 'bg-green-100 text-green-700',
                                            'closed'      => 'bg-gray-100 text-gray-500',
                                            default       => 'bg-gray-100 text-gray-600',
                                        };
                                    @endphp
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $stClasses }}">
                                        {{ $this->getLabelStatus($chamado->status ?? 'new') }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-filament-panels::page>
