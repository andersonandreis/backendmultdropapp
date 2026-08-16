<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * NOV-071 — Centro de Comando: endpoint de saude do sistema.
 *
 * Autenticado via X-Internal-Key (InternalKeyMiddleware).
 *
 * GET /api/internal/system-health
 *
 * Retorna metricas de CPU, RAM, disco, uptime, filas e jobs.
 */
class SystemHealthController extends Controller
{
    /** Filas monitoradas. */
    private const QUEUES = ['default', 'product-listing', 'sync'];

    public function index(): JsonResponse
    {
        return response()->json([
            'server'  => $this->serverMetrics(),
            'queues'  => $this->queueMetrics(),
            'workers' => $this->workerStatus(),
            'jobs'    => $this->jobsMetrics(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Server metrics
    // -------------------------------------------------------------------------

    private function serverMetrics(): array
    {
        return [
            'cpu_percent'   => $this->cpuPercent(),
            'ram_used_mb'   => $this->ramUsedMb(),
            'ram_total_mb'  => $this->ramTotalMb(),
            'disk_used_gb'  => $this->diskUsedGb(),
            'disk_total_gb' => $this->diskTotalGb(),
            'uptime_days'   => $this->uptimeDays(),
        ];
    }

    /**
     * Calcula percentual de CPU lendo /proc/stat duas vezes com intervalo de
     * 200 ms e calculando o delta idle/total.
     */
    private function cpuPercent(): float
    {
        $read = function (): array {
            $line = @file('/proc/stat')[0] ?? '';
            // cpu  user nice system idle iowait irq softirq steal guest guest_nice
            $parts = array_slice(preg_split('/\s+/', trim($line)), 1);
            $total = array_sum($parts);
            $idle  = isset($parts[3]) ? (int) $parts[3] : 0;
            return ['total' => $total, 'idle' => $idle];
        };

        $a = $read();
        usleep(200_000); // 200 ms
        $b = $read();

        $deltaTotal = $b['total'] - $a['total'];
        $deltaIdle  = $b['idle']  - $a['idle'];

        if ($deltaTotal <= 0) {
            return 0.0;
        }

        return round((1 - $deltaIdle / $deltaTotal) * 100, 1);
    }

    private function ramTotalMb(): int
    {
        return (int) round($this->memInfoKb('MemTotal') / 1024);
    }

    private function ramUsedMb(): int
    {
        $total     = $this->memInfoKb('MemTotal');
        $available = $this->memInfoKb('MemAvailable');
        return (int) round(($total - $available) / 1024);
    }

    /** Le um campo de /proc/meminfo e retorna o valor em kB. */
    private function memInfoKb(string $field): int
    {
        $content = @file_get_contents('/proc/meminfo') ?? '';
        if (preg_match("/^{$field}:\s+(\d+)\s+kB/m", $content, $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    private function diskTotalGb(): float
    {
        $bytes = @disk_total_space('/');
        return $bytes ? round($bytes / 1_073_741_824, 1) : 0.0;
    }

    private function diskUsedGb(): float
    {
        $total = @disk_total_space('/');
        $free  = @disk_free_space('/');
        if (!$total || $free === false) {
            return 0.0;
        }
        return round(($total - $free) / 1_073_741_824, 1);
    }

    private function uptimeDays(): float
    {
        $content = @file_get_contents('/proc/uptime') ?? '';
        $seconds = (float) explode(' ', $content)[0];
        return round($seconds / 86400, 1);
    }

    // -------------------------------------------------------------------------
    // Queue metrics
    // -------------------------------------------------------------------------

    private function queueMetrics(): array
    {
        $result = [];

        foreach (self::QUEUES as $queue) {
            $pending    = $this->safeCount('jobs', ['queue' => $queue, 'reserved_at' => null]);
            $processing = $this->safeCountWhere(
                'SELECT COUNT(*) as cnt FROM jobs WHERE queue = ? AND reserved_at IS NOT NULL',
                [$queue]
            );
            $failed = $this->safeCountWhere(
                "SELECT COUNT(*) as cnt FROM failed_jobs WHERE queue LIKE ?",
                ["%{$queue}%"]
            );

            $result[$queue] = [
                'pending'    => $pending,
                'processing' => $processing,
                'failed'     => $failed,
            ];
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Worker status
    // -------------------------------------------------------------------------

    private function workerStatus(): array
    {
        $supervisorOutput = @shell_exec('supervisorctl status 2>/dev/null') ?? '';
        $result = [];

        foreach (self::QUEUES as $queue) {
            $result[$queue] = $this->detectWorkerStatus($queue, $supervisorOutput);
        }

        return $result;
    }

    private function detectWorkerStatus(string $queue, string $supervisorOutput): string
    {
        // 1. Verificar via supervisorctl
        if (!empty($supervisorOutput)) {
            $pattern = '/' . preg_quote($queue, '/') . '.*RUNNING/i';
            if (preg_match($pattern, $supervisorOutput)) {
                return 'running';
            }
            // Se supervisorctl retornou algo mas nao achou o processo
            if (strlen($supervisorOutput) > 10) {
                return 'stopped';
            }
        }

        // 2. Fallback: se ha jobs sendo processados nos ultimos 5 min, worker esta ativo
        $recentProcessing = $this->safeCountWhere(
            'SELECT COUNT(*) as cnt FROM jobs WHERE queue = ? AND reserved_at > ?',
            [$queue, now()->subMinutes(5)->timestamp]
        );

        if ($recentProcessing > 0) {
            return 'running';
        }

        // 3. Sem como detectar — retornar unknown
        return 'unknown';
    }

    // -------------------------------------------------------------------------
    // Jobs metrics
    // -------------------------------------------------------------------------

    private function jobsMetrics(): array
    {
        $since24h = now()->subHours(24)->timestamp;

        // Jobs que falharam nas ultimas 24h
        $failed24h = $this->safeCountWhere(
            'SELECT COUNT(*) as cnt FROM failed_jobs WHERE failed_at >= ?',
            [now()->subHours(24)->toDateTimeString()]
        );

        // Jobs criados nas ultimas 24h (proxy para processados — jobs removidos apos sucesso)
        $created24h = $this->safeCountWhere(
            'SELECT COUNT(*) as cnt FROM jobs WHERE created_at >= ?',
            [$since24h]
        );

        return [
            'failed_24h'    => $failed24h,
            'processed_24h' => $created24h,
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** COUNT seguro com where array (igualdade simples). */
    private function safeCount(string $table, array $where): int
    {
        try {
            $query = DB::table($table);
            foreach ($where as $col => $val) {
                if ($val === null) {
                    $query->whereNull($col);
                } else {
                    $query->where($col, $val);
                }
            }
            return (int) $query->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** COUNT seguro com SQL raw e bindings. */
    private function safeCountWhere(string $sql, array $bindings = []): int
    {
        try {
            $rows = DB::select($sql, $bindings);
            return (int) ($rows[0]->cnt ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
