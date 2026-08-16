<div class="rounded-xl border border-emerald-500/20 bg-emerald-500/5 p-5 transition-all hover:border-emerald-500/40">
    <div class="flex items-center justify-between mb-4">
        <div class="flex items-center gap-2">
            <div class="w-8 h-8 rounded-lg bg-emerald-500/20 flex items-center justify-center">
                <x-filament::icon icon="heroicon-o-wallet" class="w-4 h-4 text-emerald-400"/>
            </div>
            <span class="text-sm font-semibold text-slate-300">Saldo em Wallet</span>
        </div>
        <a href="{{ $walletUrl }}" class="text-xs text-emerald-400 hover:text-emerald-300 transition-colors">
            Ver extrato
        </a>
    </div>

    <div class="text-3xl font-bold text-emerald-400 mb-4 font-variant-numeric: tabular-nums">
        R$ {{ number_format($total, 2, ',', '.') }}
    </div>

    @if($balances->isNotEmpty())
        <div class="space-y-2">
            @foreach($balances as $balance)
                @php
                    $saldo = (float) $balance->balance;
                    $color = $saldo <= 0 ? 'text-red-400' : ($saldo < 50 ? 'text-amber-400' : 'text-emerald-400');
                @endphp
                <div class="flex items-center justify-between text-sm">
                    <span class="text-slate-400 truncate max-w-[60%]">
                        {{ $balance->supplier->company_name ?? 'Fornecedor' }}
                    </span>
                    <span class="font-medium {{ $color }}">
                        R$ {{ number_format($saldo, 2, ',', '.') }}
                    </span>
                </div>
            @endforeach
        </div>
    @else
        <p class="text-xs text-slate-500">Nenhum saldo disponível.</p>
    @endif

    <a
        href="{{ $walletUrl }}"
        class="mt-4 w-full flex items-center justify-center gap-2 rounded-lg py-2 text-xs font-medium
               bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-400 transition-colors"
    >
        <x-filament::icon icon="heroicon-o-arrow-right" class="w-3.5 h-3.5"/>
        Ver extrato completo
    </a>
</div>
