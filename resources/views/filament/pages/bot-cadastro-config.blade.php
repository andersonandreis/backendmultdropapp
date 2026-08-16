<x-filament-panels::page>
    {{-- Stats --}}
    <div class="grid grid-cols-4 gap-4 mb-6">
        @php $stats = $this->getStats(); @endphp
        <x-filament::section>
            <div class="text-center">
                <div class="text-2xl font-bold text-warning-500">{{ $stats['pending'] }}</div>
                <div class="text-sm text-gray-500">Pendentes</div>
            </div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-center">
                <div class="text-2xl font-bold text-primary-500">{{ $stats['processing'] }}</div>
                <div class="text-sm text-gray-500">Processando</div>
            </div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-center">
                <div class="text-2xl font-bold text-success-500">{{ $stats['completed_today'] }}</div>
                <div class="text-sm text-gray-500">Hoje</div>
            </div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-center">
                <div class="text-2xl font-bold text-danger-500">{{ $stats['failed_today'] }}</div>
                <div class="text-sm text-gray-500">Erros Hoje</div>
            </div>
        </x-filament::section>
    </div>

    {{-- Form --}}
    <form wire:submit="save">
        {{ $this->form }}
        <div class="mt-6">
            <x-filament::button type="submit" icon="heroicon-o-check">
                Salvar Configurações
            </x-filament::button>
        </div>
    </form>

{{-- Atividade Recente --}}
<div class="mt-6">
    <x-filament::section>
        <x-slot name="heading">Atividade Recente do Bot</x-slot>
        <x-slot name="description">Últimos 15 itens processados</x-slot>

        @php $recent = $this->getRecentActivity(); @endphp

        @if(count($recent) === 0)
            <p class="text-sm text-gray-400 text-center py-8">Nenhuma atividade registrada ainda.</p>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-700">
                        <th class="text-left py-2 px-3 text-gray-500">Título Gerado</th>
                        <th class="text-left py-2 px-3 text-gray-500">Status</th>
                        <th class="text-left py-2 px-3 text-gray-500">Tentativas</th>
                        <th class="text-left py-2 px-3 text-gray-500">Quando</th>
                        <th class="text-left py-2 px-3 text-gray-500">Erro</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($recent as $item)
                    <tr class="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-900">
                        <td class="py-2 px-3 font-medium max-w-xs truncate">
                            {{ $item['generated_title'] ?? $item['title'] ?? '(sem título)' }}
                        </td>
                        <td class="py-2 px-3">
                            @php
                                $statusColors = ['completed' => 'success', 'failed' => 'danger', 'processing' => 'warning', 'pending' => 'gray'];
                                $statusColor = $statusColors[$item['status']] ?? 'gray';
                            @endphp
                            <x-filament::badge :color="$statusColor">
                                {{ ucfirst($item['status']) }}
                            </x-filament::badge>
                        </td>
                        <td class="py-2 px-3 text-gray-500">{{ $item['attempts'] ?? 0 }}</td>
                        <td class="py-2 px-3 text-gray-400 text-xs">
                            {{ \Carbon\Carbon::parse($item['created_at'])->setTimezone('America/Sao_Paulo')->diffForHumans() }}
                        </td>
                        <td class="py-2 px-3 text-red-400 text-xs max-w-xs truncate">
                            {{ $item['error_message'] ?? '' }}
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </x-filament::section>
</div>

</x-filament-panels::page>
