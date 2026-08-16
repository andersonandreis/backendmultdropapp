{{-- HUB-032 — Modal de detalhes de log de integracao --}}
<div class="space-y-4 text-sm">
    <div class="grid grid-cols-2 gap-3">
        <div>
            <div class="text-gray-500 text-xs uppercase">Integracao</div>
            <div class="font-semibold">{{ $record->integration_name }}</div>
        </div>
        <div>
            <div class="text-gray-500 text-xs uppercase">Direcao</div>
            <div class="font-semibold">{{ strtoupper($record->direction) }}</div>
        </div>
        <div>
            <div class="text-gray-500 text-xs uppercase">Metodo / URL</div>
            <div class="font-mono text-xs break-all">{{ $record->method }} {{ $record->url }}</div>
        </div>
        <div>
            <div class="text-gray-500 text-xs uppercase">HTTP / Status</div>
            <div>{{ $record->status_code ?? '—' }} / {{ $record->status ?? '—' }}</div>
        </div>
        <div>
            <div class="text-gray-500 text-xs uppercase">Tempo</div>
            <div>{{ $record->response_time_ms ? $record->response_time_ms . ' ms' : '—' }}</div>
        </div>
        <div>
            <div class="text-gray-500 text-xs uppercase">Quando</div>
            <div>{{ $record->occurred_at?->format('d/m/Y H:i:s') ?? $record->created_at?->format('d/m/Y H:i:s') }}</div>
        </div>
        <div>
            <div class="text-gray-500 text-xs uppercase">Tenant</div>
            <div>{{ $record->tenant_slug ?? '—' }}</div>
        </div>
        <div>
            <div class="text-gray-500 text-xs uppercase">Recurso relacionado</div>
            <div>{{ $record->related_resource_type }} / {{ $record->related_resource_id ?? '—' }}</div>
        </div>
        <div>
            <div class="text-gray-500 text-xs uppercase">Correlation</div>
            <div class="font-mono text-xs">{{ $record->correlation_id ?? '—' }}</div>
        </div>
        <div>
            <div class="text-gray-500 text-xs uppercase">Origem (tabela)</div>
            <div>{{ $record->source_table }} #{{ $record->source_id }}</div>
        </div>
    </div>

    @if ($record->error_message)
        <div>
            <div class="text-red-500 text-xs uppercase mb-1">Erro</div>
            <pre class="bg-red-50 dark:bg-red-900/30 text-red-800 dark:text-red-200 p-2 rounded text-xs whitespace-pre-wrap">{{ $record->error_message }}</pre>
        </div>
    @endif

    <div>
        <div class="text-gray-500 text-xs uppercase mb-1">Request payload</div>
        <pre class="bg-gray-50 dark:bg-gray-900 p-2 rounded text-xs overflow-auto max-h-72">{{ json_encode($record->request_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '—' }}</pre>
    </div>

    <div>
        <div class="text-gray-500 text-xs uppercase mb-1">Response body</div>
        <pre class="bg-gray-50 dark:bg-gray-900 p-2 rounded text-xs overflow-auto max-h-72">{{ json_encode($record->response_body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '—' }}</pre>
    </div>
</div>
