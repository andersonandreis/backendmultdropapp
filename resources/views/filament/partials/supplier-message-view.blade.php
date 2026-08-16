@php
    /** @var \App\Models\SupplierMessage $record */
@endphp
<div class="space-y-4 text-sm">
    <div class="grid grid-cols-2 gap-3">
        <div>
            <div class="text-gray-500">Assunto</div>
            <div class="font-medium">{{ $record->subject ?? '—' }}</div>
        </div>
        <div>
            <div class="text-gray-500">Canal</div>
            <div class="font-medium">{{ strtoupper($record->channel ?? 'in_app') }}</div>
        </div>
        <div>
            <div class="text-gray-500">Status</div>
            <div class="font-medium">{{ $record->status ?? '—' }}</div>
        </div>
        <div>
            <div class="text-gray-500">Destinatários</div>
            <div class="font-medium">
                {{ $record->recipients_count ?? 0 }}
                (entregues: {{ $record->delivered_count ?? 0 }}, falhas: {{ $record->failed_count ?? 0 }})
            </div>
        </div>
        <div>
            <div class="text-gray-500">Criado em</div>
            <div class="font-medium">{{ $record->created_at?->format('d/m/Y H:i') }}</div>
        </div>
        <div>
            <div class="text-gray-500">Enviado em</div>
            <div class="font-medium">{{ $record->sent_at?->format('d/m/Y H:i') ?? '—' }}</div>
        </div>
    </div>

    @if ($record->body)
        <div>
            <div class="text-gray-500 mb-1">Conteúdo</div>
            <div class="border rounded p-3 bg-gray-50 dark:bg-gray-900 whitespace-pre-wrap">{{ $record->body }}</div>
        </div>
    @endif

    @if ($record->error_message)
        <div>
            <div class="text-rose-600 mb-1 font-semibold">Erro</div>
            <div class="border border-rose-200 rounded p-3 bg-rose-50 dark:bg-rose-950 text-rose-700">{{ $record->error_message }}</div>
        </div>
    @endif

    @if ($record->payload)
        <div>
            <div class="text-gray-500 mb-1">Payload</div>
            <pre class="border rounded p-3 bg-gray-50 dark:bg-gray-900 text-xs overflow-auto">{{ json_encode($record->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        </div>
    @endif
</div>
