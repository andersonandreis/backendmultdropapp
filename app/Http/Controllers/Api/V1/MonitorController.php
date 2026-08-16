<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MonitorController extends Controller
{
    /**
     * Verifica se o usuario autenticado e super_admin.
     */
    private function requireSuperAdmin(Request $request): void
    {
        if ($request->user()?->role !== 'super_admin') {
            abort(403, 'Acesso restrito a super_admin.');
        }
    }

    /**
     * GET /api/v1/admin/monitor/stats
     * Contagens de logs por nivel, canal e top eventos.
     */
    public function stats(Request $request): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $logs24h = AppLog::where('created_at', '>=', now()->subDay())
            ->selectRaw('level, count(*) as cnt')
            ->groupBy('level')
            ->pluck('cnt', 'level');

        $logs7d = AppLog::where('created_at', '>=', now()->subDays(7))
            ->selectRaw('level, count(*) as cnt')
            ->groupBy('level')
            ->pluck('cnt', 'level');

        $byChannel24h = AppLog::where('created_at', '>=', now()->subDay())
            ->selectRaw('channel, count(*) as cnt')
            ->groupBy('channel')
            ->pluck('cnt', 'channel');

        $topEvents = AppLog::where('created_at', '>=', now()->subDay())
            ->selectRaw('event, count(*) as count')
            ->groupBy('event')
            ->orderByDesc('count')
            ->limit(10)
            ->get(['event', 'count'])
            ->toArray();

        $failedJobs = DB::table('failed_jobs')->count();
        $queueSize  = DB::table('jobs')->count();

        $logPath = storage_path('logs/laravel.log');
        $logSizeMb = file_exists($logPath) ? round(filesize($logPath) / 1048576, 2) : 0;

        return response()->json([
            'data' => [
                'logs_24h' => [
                    'error'   => (int) ($logs24h['error']   ?? 0),
                    'warning' => (int) ($logs24h['warning'] ?? 0),
                    'info'    => (int) ($logs24h['info']    ?? 0),
                    'debug'   => (int) ($logs24h['debug']   ?? 0),
                ],
                'logs_7d' => [
                    'error'   => (int) ($logs7d['error']   ?? 0),
                    'warning' => (int) ($logs7d['warning'] ?? 0),
                    'info'    => (int) ($logs7d['info']    ?? 0),
                    'debug'   => (int) ($logs7d['debug']   ?? 0),
                ],
                'by_channel_24h'  => $byChannel24h,
                'top_events_24h'  => $topEvents,
                'failed_jobs'     => (int) $failedJobs,
                'queue_size'      => (int) $queueSize,
                'log_file_size_mb' => $logSizeMb,
            ],
        ]);
    }

    /**
     * GET /api/v1/admin/monitor/logs
     * Listagem paginada de app_logs com filtros.
     */
    public function logs(Request $request): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $query = AppLog::orderByDesc('created_at');

        if ($request->filled('level')) {
            $query->where('level', $request->query('level'));
        }
        if ($request->filled('channel')) {
            $query->where('channel', $request->query('channel'));
        }
        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('message', 'like', "%{$search}%")
                  ->orWhere('event', 'like', "%{$search}%");
            });
        }
        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->query('date_to'));
        }

        $perPage = min((int) ($request->query('per_page', 50)), 200);
        $paginated = $query->paginate($perPage);

        return response()->json($paginated);
    }

    /**
     * GET /api/v1/admin/monitor/health
     * Status dos subsistemas principais.
     */
    public function health(Request $request): JsonResponse
    {
        $this->requireSuperAdmin($request);

        // Database latency
        $dbStart  = microtime(true);
        $dbOk     = true;
        $dbStatus = 'ok';
        try {
            DB::select('SELECT 1');
        } catch (\Throwable $e) {
            $dbOk     = false;
            $dbStatus = 'error: ' . $e->getMessage();
        }
        $dbLatency = (int) ((microtime(true) - $dbStart) * 1000);

        // Queue failed jobs
        $failedCount = DB::table('failed_jobs')->count();

        // Last sync per platform
        $lastSyncs = [];
        try {
            $rows = DB::table('sync_logs')
                ->selectRaw('platform, MAX(created_at) as last_sync')
                ->groupBy('platform')
                ->get();
            foreach ($rows as $row) {
                $lastSyncs[$row->platform] = $row->last_sync;
            }
        } catch (\Throwable) {
            $lastSyncs = [];
        }

        // Laravel log size
        $logPath   = storage_path('logs/laravel.log');
        $logSizeMb = file_exists($logPath) ? round(filesize($logPath) / 1048576, 2) : 0;

        // Last error in app_logs
        $lastError = AppLog::where('level', 'error')
            ->orderByDesc('created_at')
            ->value('created_at');

        return response()->json([
            'data' => [
                'database' => [
                    'status'     => $dbOk ? 'ok' : 'error',
                    'latency_ms' => $dbLatency,
                ],
                'queue_failed' => [
                    'status' => $failedCount === 0 ? 'ok' : 'warning',
                    'count'  => (int) $failedCount,
                ],
                'last_sync'            => $lastSyncs,
                'laravel_log_size_mb'  => $logSizeMb,
                'app_logs_last_error'  => $lastError,
            ],
        ]);
    }
}
