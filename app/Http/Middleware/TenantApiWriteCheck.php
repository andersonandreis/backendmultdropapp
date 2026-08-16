<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Bloqueia escrita se o tenant nao tem write_enabled=true.
 * Roda DEPOIS de TenantApiAuth.
 */
class TenantApiWriteCheck
{
    public function handle(Request $request, Closure $next)
    {
        $tenant = $request->attributes->get('tenant');
        if (!$tenant || !$tenant->write_enabled) {
            return response()->json([
                'error'  => 'write_not_enabled',
                'detail' => 'Write API is not enabled for this tenant. Contact HubAI to activate.',
            ], 403);
        }
        return $next($request);
    }
}
