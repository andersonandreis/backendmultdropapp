<?php

namespace App\Services;

use App\Models\AppLog;
use Illuminate\Support\Facades\Log;

class AppLoggerService
{
    /**
     * Registra uma entrada no log estruturado (tabela app_logs).
     * Nunca lanca excecao — falha silenciosamente para nao derrubar a request.
     */
    public static function log(
        string $level,
        string $channel,
        string $event,
        string $message,
        array $context = [],
        ?int $durationMs = null
    ): void {
        try {
            $request = request();

            AppLog::create([
                'tenant_id'   => $request?->header('X-Tenant-ID') ?? auth()->user()?->tenant_id ?? null,
                'user_id'     => auth()->id(),
                'level'       => $level,
                'channel'     => $channel,
                'event'       => $event,
                'message'     => $message,
                'context'     => !empty($context) ? $context : null,
                'ip'          => $request?->ip(),
                'request_id'  => $request?->header('X-Request-ID'),
                'duration_ms' => $durationMs,
            ]);
        } catch (\Throwable $e) {
            Log::error('AppLogger failed: ' . $e->getMessage());
        }
    }

    public static function error(string $channel, string $event, string $message, array $ctx = [], ?int $durationMs = null): void
    {
        self::log('error', $channel, $event, $message, $ctx, $durationMs);
    }

    public static function warning(string $channel, string $event, string $message, array $ctx = [], ?int $durationMs = null): void
    {
        self::log('warning', $channel, $event, $message, $ctx, $durationMs);
    }

    public static function info(string $channel, string $event, string $message, array $ctx = [], ?int $durationMs = null): void
    {
        self::log('info', $channel, $event, $message, $ctx, $durationMs);
    }

    public static function debug(string $channel, string $event, string $message, array $ctx = [], ?int $durationMs = null): void
    {
        self::log('debug', $channel, $event, $message, $ctx, $durationMs);
    }
}
