<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * SEL-271 Ruan 19/07 15:09 — endpoints de onboarding do seller.global.
 * Marca eventos de onboarding pra não repetir (network group redirect, etc).
 */
class OnboardingController extends Controller
{
    /** POST /api/v1/onboarding/mark-network-redirect — marca que já redirecionamos o user pro grupo VIP */
    public function markNetworkRedirect(Request $r)
    {
        $u = $r->user();
        if (!$u) return response()->json(['error' => 'unauthenticated'], 401);
        if (!$u->network_group_redirected_at) {
            $u->update(['network_group_redirected_at' => now()]);
        }
        return response()->json(['ok' => true, 'at' => $u->network_group_redirected_at]);
    }
}
