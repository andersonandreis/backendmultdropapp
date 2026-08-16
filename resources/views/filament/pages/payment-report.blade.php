<x-filament-panels::page>
    {{-- KPI Cards --}}
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4 mb-6">
        <div class="rounded-xl border border-emerald-500/20 bg-emerald-500/5 p-4">
            <div class="text-xs text-slate-400 mb-1">Total Cobrado</div>
            <div class="text-xl font-bold text-emerald-400">
                R$ {{ number_format($this->totalCobrado, 2, ',', '.') }}
            </div>
            <div class="text-xs text-slate-500 mt-1">Pagamentos confirmados</div>
        </div>

        <div class="rounded-xl border border-cyan-500/20 bg-cyan-500/5 p-4">
            <div class="text-xs text-slate-400 mb-1">Total via PIX</div>
            <div class="text-xl font-bold text-cyan-400">
                R$ {{ number_format($this->totalPix, 2, ',', '.') }}
            </div>
            <div class="text-xs text-slate-500 mt-1">Soma dos PIX pagos</div>
        </div>

        <div class="rounded-xl border border-indigo-500/20 bg-indigo-500/5 p-4">
            <div class="text-xs text-slate-400 mb-1">Total via Wallet</div>
            <div class="text-xl font-bold text-indigo-400">
                R$ {{ number_format($this->totalWallet, 2, ',', '.') }}
            </div>
            <div class="text-xs text-slate-500 mt-1">Débitos de saldo</div>
        </div>

        <div class="rounded-xl border border-amber-500/20 bg-amber-500/5 p-4">
            <div class="text-xs text-slate-400 mb-1">Taxa Média</div>
            <div class="text-xl font-bold text-amber-400">
                R$ {{ number_format($this->taxaMedia, 2, ',', '.') }}
            </div>
            <div class="text-xs text-slate-500 mt-1">Por transação</div>
        </div>

        <div class="rounded-xl border border-red-500/20 bg-red-500/5 p-4">
            <div class="text-xs text-slate-400 mb-1">Total Reembolsos</div>
            <div class="text-xl font-bold text-red-400">
                R$ {{ number_format($this->totalReembolsos, 2, ',', '.') }}
            </div>
            <div class="text-xs text-slate-500 mt-1">Estornos realizados</div>
        </div>
    </div>

    {{-- Tabela detalhada --}}
    {{ $this->table }}
</x-filament-panels::page>
