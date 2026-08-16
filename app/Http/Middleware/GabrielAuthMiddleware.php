<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class GabrielAuthMiddleware
{
    /**
     * Maximo de requisicoes por minuto por IP.
     */
    private const RATE_LIMIT = 20;

    public function handle(Request $request, Closure $next): Response
    {
        $ip     = $request->ip();
        $token  = $request->header('X-Gabriel-Token', '');
        $secret = config('app.gabriel_api_token', env('GABRIEL_API_TOKEN', ''));

        // Token vazio ou secret nao configurado → 401
        if (empty($token) || empty($secret)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Comparacao timing-safe
        if (! hash_equals($secret, $token)) {
            Log::channel('gabriel')->warning('gabriel.auth_fail', [
                'ip'      => $ip,
                'path'    => $request->path(),
            ]);
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Rate limit: maximo RATE_LIMIT req/min por IP
        $cacheKey = "gabriel_ratelimit_{$ip}";
        $count    = Cache::get($cacheKey, 0);

        if ($count >= self::RATE_LIMIT) {
            Log::channel('gabriel')->warning('gabriel.rate_limit', [
                'ip'    => $ip,
                'path'  => $request->path(),
                'count' => $count,
            ]);
            return response()->json(['error' => 'Too Many Requests'], 429);
        }

        // Incrementa contador (TTL 60s)
        if ($count === 0) {
            Cache::put($cacheKey, 1, 60);
        } else {
            Cache::increment($cacheKey);
        }

        return $next($request);
    }
}
