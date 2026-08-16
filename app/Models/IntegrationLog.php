<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * HUB-032 — Log unificado de integração.
 *
 * Append-only. Cada linha representa uma chamada HTTP (inbound webhook
 * recebido ou outbound chamada feita pelo sistema) com qualquer parceiro
 * (Pagar.me, ML, Shopee, Bling, Chatwoot, OpenAI, WL-relay, etc).
 *
 * Populado por dois caminhos complementares:
 *  1. Command `integration:aggregate-logs` (estratégia A) — ETL a cada 5 min
 *     a partir das tabelas existentes (webhook_logs, webhook_deliveries,
 *     app_logs, legacy_sync_runs, bridge_relay_queue, email_logs).
 *  2. `App\Services\IntegrationLogger::log()` (estratégia B) — chamado pelo
 *     middleware inbound e `Http::loggedClient()` para outbound em tempo real.
 */
class IntegrationLog extends Model
{
    const UPDATED_AT = null;

    protected $table = 'integration_logs';

    protected $fillable = [
        'integration_name',
        'direction',
        'method',
        'url',
        'status_code',
        'status',
        'response_time_ms',
        'request_payload',
        'response_body',
        'error_message',
        'tenant_slug',
        'client_id',
        'related_resource_type',
        'related_resource_id',
        'correlation_id',
        'source_table',
        'source_id',
        'occurred_at',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_body'   => 'array',
        'occurred_at'     => 'datetime',
        'created_at'      => 'datetime',
    ];

    public const DIRECTION_INBOUND  = 'inbound';
    public const DIRECTION_OUTBOUND = 'outbound';

    public function scopeByIntegration(Builder $q, string $name): Builder
    {
        return $q->where('integration_name', $name);
    }

    public function scopeByStatus(Builder $q, int $code): Builder
    {
        return $q->where('status_code', $code);
    }

    public function scopeRecent(Builder $q, int $hours = 24): Builder
    {
        return $q->where('created_at', '>=', now()->subHours($hours));
    }

    public function scopeFailed(Builder $q): Builder
    {
        return $q->where(function ($w) {
            $w->whereIn('status', ['failed', 'dead'])
              ->orWhere('status_code', '>=', 400);
        });
    }

    public function scopeInbound(Builder $q): Builder
    {
        return $q->where('direction', self::DIRECTION_INBOUND);
    }

    public function scopeOutbound(Builder $q): Builder
    {
        return $q->where('direction', self::DIRECTION_OUTBOUND);
    }

    public function scopeByTenant(Builder $q, string $slug): Builder
    {
        return $q->where('tenant_slug', $slug);
    }
}
