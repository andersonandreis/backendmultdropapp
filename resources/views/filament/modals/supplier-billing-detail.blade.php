@php
    /** @var \App\Models\SupplierBillingCycle $record */
@endphp
<div class="space-y-4 text-sm">
    <div class="grid grid-cols-2 gap-4">
        <div>
            <div class="text-gray-500">Período</div>
            <div class="font-medium">{{ $record->period_start->format('d/m/Y') }} a {{ $record->period_end->format('d/m/Y') }}</div>
        </div>
        <div>
            <div class="text-gray-500">Vencimento</div>
            <div class="font-medium {{ $record->isOverdue() ? 'text-red-600' : '' }}">{{ $record->due_date->format('d/m/Y') }}</div>
        </div>
        <div>
            <div class="text-gray-500">Lojistas ativos</div>
            <div class="font-medium">{{ $record->clients_active }}</div>
        </div>
        <div>
            <div class="text-gray-500">Pedidos</div>
            <div class="font-medium">{{ $record->orders_count }}</div>
        </div>
    </div>

    <div class="border rounded p-3 bg-gray-50">
        <div class="grid grid-cols-3 gap-2">
            <div>
                <div class="text-gray-500">Usuários</div>
                <div>R$ {{ number_format($record->amount_users, 2, ',', '.') }}</div>
            </div>
            <div>
                <div class="text-gray-500">Pedidos</div>
                <div>R$ {{ number_format($record->amount_orders, 2, ',', '.') }}</div>
            </div>
            <div>
                <div class="text-gray-500">Extras</div>
                <div>R$ {{ number_format($record->amount_extra, 2, ',', '.') }}</div>
            </div>
        </div>
        <div class="mt-3 pt-3 border-t flex justify-between">
            <span class="font-semibold">Total</span>
            <span class="font-bold text-lg">R$ {{ number_format($record->amount_total, 2, ',', '.') }}</span>
        </div>
    </div>

    @if($record->isPaid())
        <div class="rounded p-3 bg-green-50 text-green-800">
            Pago em {{ $record->paid_at?->format('d/m/Y H:i') }}
        </div>
    @elseif($record->payment_url)
        <a href="{{ $record->payment_url }}" target="_blank" rel="noopener"
           class="block text-center px-4 py-2 bg-primary-600 text-white rounded hover:bg-primary-700">
            Pagar agora (link externo)
        </a>
    @endif

    @if($record->pix_qr_code)
        <div class="border rounded p-3 break-all bg-gray-50">
            <div class="text-gray-500 mb-1">PIX copia e cola:</div>
            <code class="text-xs">{{ $record->pix_qr_code }}</code>
        </div>
    @endif

    @if($record->notes)
        <div class="text-gray-600 italic">{{ $record->notes }}</div>
    @endif
</div>
