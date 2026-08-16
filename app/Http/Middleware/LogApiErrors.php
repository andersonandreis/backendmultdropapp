<?php

namespace App\Http\Middleware;

use App\Services\AppLoggerService;
use Closure;
use Illuminate\Http\Request;

class LogApiErrors
{
    public function handle(Request $request, Closure $next): mixed
    {
        $start    = microtime(true);
        $response = $next($request);
        $status   = $response->getStatusCode();

        if ($status >= 400) {
            $durationMs = (int) ((microtime(true) - $start) * 1000);
            $level      = $status >= 500 ? 'error' : 'warning';

            AppLoggerService::log(
                $level,
                'api',
                "http.{$status}",
                "HTTP {$status}: {$request->method()} {$request->path()}",
                [
                    'status'      => $status,
                    'query'       => $request->query->all(),
                    'duration_ms' => $durationMs,
                ],
                $durationMs
            );
        }

        return $response;
    }
}
