<?php

namespace App\Http\Middleware;

use App\Models\TenantApiCredential;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class TenantApiAuth
{
    public function handle(Request $request, Closure $next, ?string $requiredScope = null)
    {
        $auth = $request->header('Authorization', '');
        if (!preg_match('/^Bearer\s+(ht_live_[A-Za-z0-9]+)\.([A-Za-z0-9]+)$/', $auth, $m)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }
        [$_, $keyId, $secret] = $m;

        $cred = TenantApiCredential::with('tenant')->where('key_id', $keyId)->first();
        if (!$cred || $cred->isRevoked() || !$cred->tenant) {
            return response()->json(['error' => 'unauthorized'], 401);
        }
        if (!password_verify($secret, $cred->key_hash)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }
        if ($requiredScope && !$cred->hasScope($requiredScope)) {
            return response()->json(['error' => 'insufficient_scope', 'required' => $requiredScope], 403);
        }
        if ($cred->tenant->status === 'suspended') {
            return response()->json(['error' => 'tenant_suspended'], 403);
        }

        $rateLimit = (int) ($cred->tenant->rate_limit_per_min ?? 100);
        $bucket = 'tenantapi:' . $cred->id;
        try {
            if (RateLimiter::tooManyAttempts($bucket, $rateLimit)) {
                $retry = RateLimiter::availableIn($bucket);
                return response()->json(['error' => 'rate_limit'], 429)
                    ->header('Retry-After', (string) $retry);
            }
            RateLimiter::hit($bucket, 60);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[TenantApiAuth] RateLimiter falhou, continuando sem limite', [
                'error' => $e->getMessage(), 'tenant' => $cred->tenant->slug,
            ]);
        }

        $request->attributes->set('tenant', $cred->tenant);
        $request->attributes->set('tenant_id', $cred->tenant->id);
        $request->attributes->set('api_credential', $cred);

        app()->instance('current_tenant_actor', [
            'type' => 'tenant',
            'id'   => $cred->tenant->slug,
            'key'  => $cred->key_id,
        ]);

        $cred->forceFill(['last_used_at' => now()])->saveQuietly();

        return $next($request);
    }
}
