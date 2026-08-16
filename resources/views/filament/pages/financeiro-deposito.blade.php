<x-filament-panels::page>
    @php($saldos = $this->getSaldosData())

    {{-- MUL-226-06: saldos separados — total / ativo / bloqueado --}}
    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">Saldo Total</div>
            <div class="text-2xl font-bold">{{ $saldos['total'] }}</div>
            <div class="text-xs text-gray-400 mt-1">Ativo + bloqueado</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">Saldo Ativo (disponível)</div>
            <div class="text-2xl font-bold" style="color:#10b981;">{{ $saldos['ativo'] }}</div>
            <div class="text-xs text-gray-400 mt-1">Disponível nas contas dos remetentes</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">Saldo Bloqueado</div>
            <div class="text-2xl font-bold" style="color:#f59e0b;">{{ $saldos['bloqueado'] }}</div>
            <div class="text-xs text-gray-400 mt-1">Saques aguardando aprovação/pagamento</div>
        </x-filament::section>
    </div>

    <x-filament::section>
        <p class="text-sm text-gray-500 mb-4">Depósitos agrupados por data. Clique em "Ver Detalhe" para conferir as contas e valores de cada dia — a soma do detalhe bate com o total do dia.</p>
        {{ $this->table }}
    </x-filament::section>
</x-filament-panels::page>
