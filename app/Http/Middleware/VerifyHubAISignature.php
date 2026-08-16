<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VerifyHubAISignature
{
    public function handle(Request $request, Closure $next): mixed
    {
        $secret = config('services.hubai.webhook_secret', env('HUBAI_WEBHOOK_SECRET', ''));

        if (empty($secret)) {
            return $next($request);
        }

        $signature = $request->header('X-HubAI-Signature', '');
        $expected  = 'sha256=' . hash_hmac('sha256', $request->getContent(), $secret);

        if (!hash_equals($expected, $signature)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        return $next($request);
    }
}
