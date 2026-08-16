<?php

namespace App\Console\Commands;

use App\Models\IntegrationLog;
use App\Services\IntegrationLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * HUB-032 — Agregador (estrategia A).
 *
 * Le as ultimas linhas das tabelas existentes de log e copia para
 * `integration_logs` em formato unificado. Idempotente via UNIQUE
 * (source_table, source_id).
 *
 * Mapeamento atual:
 *   webhook_logs        -> inbound (Mercado Livre, Shopee, plataformas)
 *   webhook_deliveries  -> outbound (entrega de evento para WL)
 *   bridge_relay_queue  -> outbound (relay para legado / cross-WL)
 *   app_logs            -> outbound|inbound (canal=api, http.*)
 *   legacy_sync_runs    -> outbound (import_orders, sync_products, etc)
 *   email_logs          -> outbound (notificacoes transacionais)
 *
 * Execucao: schedule a cada 5 min em routes/console.php.
 */
class AggregateIntegrationLogsCommand extends Command
{
    protected $signature = 'integration:aggregate-logs
                            {--limit=2000 : Linhas por tabela por execucao}
                            {--full : Faz varredura completa (primeira carga)}';

    protected $description = 'HUB-032 - Agrega logs das tabelas existentes em integration_logs';

    public function handle(): int
    {
        $limit  = (int) $this->option('limit');
        $full   = (bool) $this->option('full');
        $start  = microtime(true);
        $total  = 0;

        $jobs = [
            'webhook_logs'       => 'syncWebhookLogs',
            'webhook_deliveries' => 'syncWebhookDeliveries',
            'bridge_relay_queue' => 'syncBridgeRelayQueue',
            'app_logs'           => 'syncAppLogs',
            'legacy_sync_runs'   => 'syncLegacySyncRuns',
            'email_logs'         => 'syncEmailLogs',
        ];

        foreach ($jobs as $source => $method) {
            if (! Schema::hasTable($source)) {
                $this->line("  {$source}: tabela ausente, pulando");
                continue;
            }
            try {
                $n = $this->{$method}($limit, $full);
                $this->line(sprintf('  %-22s %d', $source, $n));
                $total += $n;
            } catch (Throwable $e) {
                $this->error("  {$source}: " . $e->getMessage());
            }
        }

        $ms = (int) ((microtime(true) - $start) * 1000);
        $this->info("HUB-032 aggregate: {$total} linhas em {$ms} ms");

        return self::SUCCESS;
    }

    private function lastSyncedId(string $sourceTable): int
    {
        $id = IntegrationLog::query()
            ->where('source_table', $sourceTable)
            ->max('source_id_bigint');

        return (int) ($id ?? 0);
    }

    private function syncWebhookLogs(int $limit, bool $full): int
    {
        $since = $full ? 0 : $this->lastSyncedId('webhook_logs');

        $rows = DB::table('webhook_logs')
            ->where('id', '>', $since)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $count = 0;
        foreach ($rows as $r) {
            $written = IntegrationLogger::inbound([
                'integration_name'      => $r->platform,
                'method'                => 'POST',
                'url'                   => $r->resource,
                'status'                => $r->status,
                'status_code'           => $r->status === 'processed' ? 200 : ($r->status === 'failed' ? 500 : null),
                'request_payload'       => $r->payload,
                'error_message'         => $r->error_message ?: null,
                'related_resource_type' => $r->topic,
                'related_resource_id'   => null,
                'source_table'          => 'webhook_logs',
                'source_id'             => (string) $r->id,
                'occurred_at'           => $r->created_at,
            ]);
            if ($written) {
                $count++;
            }
        }
        return $count;
    }

    private function syncWebhookDeliveries(int $limit, bool $full): int
    {
        $since = $full ? null : IntegrationLog::query()
            ->where('source_table', 'webhook_deliveries')
            ->max('occurred_at');

        $q = DB::table('webhook_deliveries as wd')
            ->leftJoin('tenant_webhook_endpoints as twe', 'wd.endpoint_id', '=', 'twe.id')
            ->leftJoin('tenants as t', 'twe.tenant_id', '=', 't.id')
            ->select([
                'wd.id', 'wd.event', 'wd.status', 'wd.response_code', 'wd.response_body',
                'wd.payload', 'wd.attempt', 'wd.created_at', 'wd.updated_at',
                'twe.url as endpoint_url', 't.slug as tenant_slug',
            ])
            ->orderBy('wd.updated_at')
            ->limit($limit);

        if ($since) {
            $q->where('wd.updated_at', '>', $since);
        }

        $count = 0;
        foreach ($q->get() as $r) {
            $written = IntegrationLogger::outbound([
                'integration_name'      => 'wl-webhook',
                'method'                => 'POST',
                'url'                   => $r->endpoint_url,
                'status'                => $r->status,
                'status_code'           => $r->response_code,
                'tenant_slug'           => $r->tenant_slug,
                'request_payload'       => $r->payload,
                'response_body'         => $r->response_body ?: null,
                'related_resource_type' => $r->event,
                'related_resource_id'   => null,
                'correlation_id'        => $r->id,
                'source_table'          => 'webhook_deliveries',
                'source_id'             => $r->id,
                'occurred_at'           => $r->updated_at ?? $r->created_at,
            ]);
            if ($written) {
                $count++;
            }
        }
        return $count;
    }

    private function syncBridgeRelayQueue(int $limit, bool $full): int
    {
        $since = $full ? 0 : $this->lastSyncedId('bridge_relay_queue');

        $rows = DB::table('bridge_relay_queue')
            ->where('id', '>', $since)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $count = 0;
        foreach ($rows as $r) {
            $written = IntegrationLogger::outbound([
                'integration_name'      => 'bridge-relay-' . $r->platform,
                'method'                => 'POST',
                'url'                   => 'legacy://' . $r->platform . '/' . $r->event_type,
                'status'                => $r->status,
                'status_code'           => $r->status === 'sent' ? 200 : ($r->status === 'failed' ? 500 : null),
                'request_payload'       => $r->payload,
                'error_message'         => $r->last_error ?: null,
                'related_resource_type' => 'order',
                'related_resource_id'   => $r->order_id ? (string) $r->order_id : null,
                'source_table'          => 'bridge_relay_queue',
                'source_id'             => (string) $r->id,
                'occurred_at'           => $r->updated_at ?? $r->created_at,
            ]);
            if ($written) {
                $count++;
            }
        }
        return $count;
    }

    private function syncAppLogs(int $limit, bool $full): int
    {
        $since = $full ? 0 : $this->lastSyncedId('app_logs');

        $rows = DB::table('app_logs')
            ->where('id', '>', $since)
            ->where('channel', 'api')
            ->where(function ($q) {
                $q->where('event', 'like', 'http.%')
                  ->orWhere('event', 'like', '%webhook%')
                  ->orWhere('event', 'like', '%api.%');
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $count = 0;
        foreach ($rows as $r) {
            $context = json_decode($r->context ?? 'null', true) ?: [];
            $url     = $context['url'] ?? $context['endpoint'] ?? null;
            $status  = $context['status'] ?? null;
            $integration = $context['integration']
                ?? (str_starts_with($r->event, 'http.') ? 'http' : ($r->channel ?: 'app'));

            $written = IntegrationLogger::outbound([
                'integration_name'      => substr($integration, 0, 64),
                'method'                => $context['method'] ?? null,
                'url'                   => $url ? substr((string) $url, 0, 2048) : substr($r->message ?? '', 0, 2048),
                'status'                => $r->level === 'error' ? 'failed' : ($r->level === 'warning' ? 'warning' : 'success'),
                'status_code'           => is_numeric($status) ? (int) $status : null,
                'response_time_ms'      => $r->duration_ms,
                'request_payload'       => $context,
                'error_message'         => $r->level === 'error' ? $r->message : null,
                'correlation_id'        => $r->request_id ?: null,
                'source_table'          => 'app_logs',
                'source_id'             => (string) $r->id,
                'occurred_at'           => $r->created_at,
            ]);
            if ($written) {
                $count++;
            }
        }
        return $count;
    }

    private function syncLegacySyncRuns(int $limit, bool $full): int
    {
        $since = $full ? 0 : $this->lastSyncedId('legacy_sync_runs');

        $rows = DB::table('legacy_sync_runs')
            ->where('id', '>', $since)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $count = 0;
        foreach ($rows as $r) {
            $written = IntegrationLogger::outbound([
                'integration_name'      => 'legacy-' . $r->job,
                'method'                => 'JOB',
                'url'                   => 'legacy://' . $r->job,
                'status'                => $r->status,
                'status_code'           => $r->status === 'success' ? 200 : ($r->status === 'failed' ? 500 : null),
                'response_time_ms'      => $r->duration_ms,
                'request_payload'       => ['processed' => $r->processed, 'errors' => $r->errors],
                'response_body'         => $r->message ? ['message' => $r->message] : null,
                'error_message'         => $r->errors > 0 ? ($r->message ?: null) : null,
                'source_table'          => 'legacy_sync_runs',
                'source_id'             => (string) $r->id,
                'occurred_at'           => $r->finished_at ?? $r->started_at ?? $r->created_at,
            ]);
            if ($written) {
                $count++;
            }
        }
        return $count;
    }

    private function syncEmailLogs(int $limit, bool $full): int
    {
        $since = $full ? 0 : $this->lastSyncedId('email_logs');

        $rows = DB::table('email_logs')
            ->where('id', '>', $since)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $count = 0;
        foreach ($rows as $r) {
            $written = IntegrationLogger::outbound([
                'integration_name'      => 'email-' . ($r->email_type ?: 'generic'),
                'method'                => 'SMTP',
                'url'                   => 'mailto:' . $r->to_email,
                'status'                => $r->status,
                'status_code'           => $r->status === 'sent' ? 200 : ($r->status === 'failed' ? 500 : null),
                'request_payload'       => ['to' => $r->to_email, 'type' => $r->email_type],
                'error_message'         => $r->failed_reason ?: null,
                'client_id'             => $r->user_id ?: null,
                'related_resource_type' => 'email',
                'related_resource_id'   => (string) $r->id,
                'source_table'          => 'email_logs',
                'source_id'             => (string) $r->id,
                'occurred_at'           => $r->sent_at ?? $r->created_at,
            ]);
            if ($written) {
                $count++;
            }
        }
        return $count;
    }
}
