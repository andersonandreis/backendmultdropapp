<?php

namespace App\Http\Middleware;

use App\Services\InviteTrialService;
use Closure;
use Illuminate\Http\Request;

/**
 * SEL-CONVITE Fase A — corta a sessao do trial quando passa das 24h.
 *
 * Aplicado nos grupos de rota que o trial usa de fato (studio-chat / studio /
 * videostudio). Se o usuario tem trial e o relogio do SERVIDOR ja passou de
 * expires_at: marca expirado, REVOGA os tokens (desloga) e devolve 402
 * convite_expired + oferta. O front (interceptor em api.ts) captura o 402,
 * limpa o token e mostra a parede de upgrade.
 *
 * Nao confia no relogio do cliente: expires_at é do servidor.
 */
class EnsureInviteTrialActive
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user) return $next($request);

        // Tem trial ATIVO ainda dentro das 24h? activeTrial() expira sozinho se venceu.
        $trial = \Illuminate\Support\Facades\DB::table('trial_invites')
            ->where('user_id', $user->id)->where('status', 'active')
            ->orderByDesc('id')->first();

        if (! $trial) return $next($request);

        if ($trial->expires_at && \Carbon\Carbon::parse($trial->expires_at)->isPast()) {
            InviteTrialService::expireAndRevoke($user);
            return response()->json([
                'error'   => 'convite_expired',
                'stage'   => 'convite_expired',
                'offer'   => InviteTrialService::offer(),
                'message' => 'Seu teste de 24h expirou. Desbloqueie tudo com o plano completo.',
            ], 402);
        }

        return $next($request);
    }
}
