<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SEL-462 Fase 1: Autentica requests de ingest do worker Kalodata
 * via header X-Kalodata-Token comparado com env KALODATA_INGEST_TOKEN.
 */
class AuthKalodataToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('services.kalodata.ingest_token');

        if (empty($token)) {
            return response()->json(['message' => 'Ingest token not configured.'], 500);
        }

        if (!$request->hasHeader('X-Kalodata-Token') ||
            !hash_equals($token, $request->header('X-Kalodata-Token'))) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
