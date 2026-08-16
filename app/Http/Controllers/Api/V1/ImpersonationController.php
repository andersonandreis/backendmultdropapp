<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ImpersonationController extends Controller
{
    private const TTL_SECONDS = 900; // 15 minutos

    /**
     * Gera um token de impersonação de uso único.
     * Restrito a super_admin.
     *
     * POST /api/v1/admin/impersonate/{userId}
     */
    public function generate(Request $request, int $userId): JsonResponse
    {
        // Apenas super_admin pode impersonar
        if ($request->user()?->role !== 'super_admin') {
            return response()->json(['message' => 'Acesso restrito a super_admin.'], 403);
        }

        $targetUser = User::findOrFail($userId);

        $token     = (string) Str::uuid();
        $expiresAt = now()->addSeconds(self::TTL_SECONDS);

        DB::table('impersonation_logs')->insert([
            'token'          => $token,
            'admin_id'       => $request->user()->id,
            'target_user_id' => $targetUser->id,
            'ip_address'     => $request->ip(),
            'used_at'        => null,
            'expires_at'     => $expiresAt,
            'created_at'     => now(),
        ]);

        $frontendUrl = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'https://multdrop.app')), '/');
        $url         = $frontendUrl . '/impersonate?token=' . $token;

        return response()->json([
            'token'      => $token,
            'url'        => $url,
            'expires_in' => self::TTL_SECONDS,
        ]);
    }

    /**
     * Troca o token de impersonação por um Sanctum token real.
     * Endpoint público, uso único.
     *
     * POST /api/v1/impersonate/exchange
     */
    public function exchange(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        $log = DB::table('impersonation_logs')
            ->where('token', $request->token)
            ->first();

        // Token inexistente
        if (! $log) {
            return response()->json(['message' => 'Token inválido ou expirado.'], 422);
        }

        // Token já utilizado
        if ($log->used_at !== null) {
            return response()->json(['message' => 'Token já utilizado.'], 422);
        }

        // Token expirado
        if (now()->isAfter($log->expires_at)) {
            return response()->json(['message' => 'Token expirado.'], 422);
        }

        // Marcar como utilizado (uso único)
        DB::table('impersonation_logs')
            ->where('token', $request->token)
            ->update(['used_at' => now()]);

        $user        = User::findOrFail($log->target_user_id);
        $accessToken = $user->createToken('impersonation-token')->plainTextToken;

        // SEL-097 fix Ruan 21:06: exchange incluia so id/name/email/role,
        // frontend TierContext caia em RESTRICTIVE_DEFAULT (tiktok_free) porque
        // subscription/plan_slug nao vinha. Cliente pagante entrava como free e
        // integracao ML/Shopee falhava. Fix: incluir subscription completa +
        // plan_slug top-level (espelha o payload do /me).
        $user->load('client');
        $subscription = $user->client?->subscriptions()->with('plan:id,slug,name,price_monthly')->latest()->first();
        $planSlug = $subscription?->plan?->slug;
        $planId = $subscription?->plan_id;

        return response()->json([
            'access_token'  => $accessToken,
            'user'          => [
                'id'         => $user->id,
                'name'       => $user->name,
                'email'      => $user->email,
                'role'       => $user->role,
                'client'     => $user->client,
                'plan_id'    => $planId,
                'plan_slug'  => $planSlug,
                'subscription' => $subscription ? array_merge(
                    $subscription->only(['status', 'plan_id', 'trial_ends_at', 'current_period_end']),
                    ['plan' => $subscription->plan ? [
                        'id'    => $subscription->plan->id,
                        'slug'  => $subscription->plan->slug,
                        'name'  => $subscription->plan->name ?? null,
                        'price' => $subscription->plan->price_monthly ?? null,
                    ] : null]
                ) : null,
            ],
            'impersonating' => true,
        ]);
    }
}
