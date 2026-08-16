<?php

namespace App\Http\Middleware;

use App\Services\LiveOnlyAccessService;
use Closure;
use Illuminate\Http\Request;

/**
 * SEL-408 — cerca a conta "só-live" (extensão de LIVE + /lives, nada mais).
 *
 * Mesmo padrão do RestrictFreeAccess (SEL-082): allowlist de path, 403 fora
 * dela. A diferença é o gatilho: aqui é por CONCESSÃO (subscriptions com
 * payment_method='live_only_grant'), não por plano de checkout.
 *
 * Regra de decisão:
 *  - Se o client tem alguma assinatura ativa que NÃO é a concessão só-live
 *    (ou seja, um plano de verdade, pago ou trial) → acesso total, essa
 *    middleware nem entra em ação. Cobre o caso "cliente pagante que também
 *    ganhou a concessão por cima" — a concessão não pode restringir quem já
 *    paga por algo maior.
 *  - Se a ÚNICA assinatura ativa do client é a concessão só-live → cerca tudo
 *    fora da allowlist. Cobre tanto a conta nova criada só pra isso quanto o
 *    cliente existente cuja assinatura paga caducou e o admin liberou só a
 *    live por cima.
 */
class RestrictLiveOnlyAccess
{
    private const ALLOWED_PATTERNS = [
        // Auth básico / higiene de conta
        'api/logout',
        'api/login',
        'api/verify-2fa',
        'api/public-config',

        // Perfil mínimo
        'api/v1/me',

        // A extensão de LIVE inteira
        'api/v1/live/*',

        // Painel /lives (dados vêm do Kalodata, escopo travado só nesses dois endpoints)
        'api/v1/insights/tiktok/lives',
        'api/v1/insights/tiktok/lives-ranking',

        // Sanctum
        'sanctum/csrf-cookie',
    ];

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $client = $user->client ?? null;
        if (! $client) {
            return $next($request);
        }

        $assinaturasAtivas = $client->subscriptions()
            ->whereIn('status', ['active', 'trialing'])
            ->whereNull('cancelled_at')
            ->get(['id', 'payment_method']);

        if ($assinaturasAtivas->isEmpty()) {
            // sem assinatura nenhuma — não é problema dessa middleware (outros
            // gates, se houver, cuidam disso)
            return $next($request);
        }

        $temAcessoDeVerdade = $assinaturasAtivas->contains(
            fn ($sub) => $sub->payment_method !== LiveOnlyAccessService::GRANT_METHOD
        );
        if ($temAcessoDeVerdade) {
            return $next($request);
        }

        // A partir daqui: TODAS as assinaturas ativas são concessão só-live.
        $path = $request->path();
        foreach (self::ALLOWED_PATTERNS as $pattern) {
            if ($request->is($pattern)) {
                return $next($request);
            }
        }

        return response()->json([
            'error'   => 'acesso_restrito_live',
            'message' => 'Essa conta tem acesso só à LIVE. Fale com quem te deu o acesso pra liberar o resto do sistema.',
        ], 403);
    }
}
