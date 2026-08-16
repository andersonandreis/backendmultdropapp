<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * SEL-082 F1 + SEL-085 hardening â€” cerca as brechas do tier tiktok_free.
 *
 * Estrategia: allowlist restrita + sanitiza query params + limita per_page.
 * Fecha vulnerabilidades achadas em pentest 14/07/2026:
 *  - #3 directory-suppliers?per_page=999 bypassava amostra de 30
 *  - #4 /trends?with_raw=1&per_page=200 vazava dados brutos completos
 *  - #6 api/v1/me/* amplo demais + survey sem rate limit
 *  - #9 trends entregava 200 registros com raw pra qualquer tier
 */
class RestrictFreeAccess
{
    private const ALLOWED_PATTERNS = [
        // Auth basico
        'api/logout',
        'api/user/token',
        'api/register*',
        'api/login',
        'api/verify-2fa',
        'api/public-config',
        'api/google-login',

        // Perfil e sessao - SEL-085 vuln #6 fix: restrito ao inves de /me/*
        'api/v1/me',
        'api/v1/me/survey',
        'api/v1/me/survey/skip',
        // SEL-179 Ruan 16:10: bonus 50% catalogo agora vale pra TODO cliente
        // (incluindo tiktok_free), entao /me/bonus-status precisa ser acessivel
        // pra free (senao front recebe 403 e nao aplica desconto silencioso).
        'api/v1/me/bonus-status',
        'api/v2/auth/me',
        'api/v1/profile',
        'api/v1/user',
        // SEL-182 pentest fix: allowlist estava bloqueando troca de senha do
        // proprio free user (ele nao conseguia mais mudar credencial ate
        // fazer upgrade). Basica higiene de conta deve ser sempre permitida.
        'api/v1/password',

        // TikTok Shopping (unico funil)
        'api/v1/tiktok*',
        'api/v1/trends*',
        'api/v1/tiktok-shop*',

        // SEL-203: Criadores IA — free vê 30 (cap per_page abaixo)
        'api/v1/ai-creators*',

        // Amostra de fornecedores
        'api/v1/directory-suppliers*',
        'api/v1/suppliers-preview*',
        // SEL-159: catalogo /dropshipping tier free - amostra limitada a 30 produtos
        'api/v1/suppliers',
        'api/v1/suppliers/*/catalog',
        'api/v1/suppliers/*/catalog/categories',

        // Upgrade path
        'api/checkout/*',

        // OAuth callbacks
        'api/oauth/*',

        // Push
        'api/v1/push/*',

        // Logout universal
        'logout-all',

        // Sanctum
        'sanctum/csrf-cookie',
    ];

    // SEL-085 vuln #3+#4+#7 fix: caps de per_page e query params permitidos por rota
    private const PER_PAGE_CAPS = [
        'api/v1/directory-suppliers*' => 60,  // SEL-088 fix Ruan 18:21
        'api/v1/trends*'              => 500,  // SEL-174 Ruan 13:02: free ve os 504 (nao ha razao pra limitar a 20)
        'api/v1/suppliers-preview*'   => 30,
        'api/v1/suppliers/*/catalog'  => 30,  // SEL-159 cap amostra free
        'api/v1/suppliers'            => 30,  // SEL-159 cap lista fornecedores free
        'api/v1/ai-creators*'         => 30,  // SEL-203 free vê 30 criadores IA
    ];

    // SEL-085 vuln #4+#15 fix: params bloqueados pra tier free em rotas sensiveis
    private const BLOCKED_PARAMS = [
        // SEL-088: with_raw liberado (parseCreator precisa) â€” cap per_page=20 ja limita volume
    ];

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user) return $next($request);

        $client = $user->client ?? null;
        if (! $client) return $next($request);

        $sub = $client->subscriptions()
            ->with('plan:id,slug')
            ->whereIn('status', ['active', 'trialing'])
            ->latest('id')
            ->first();

        $planSlug = $sub?->plan?->slug;
        if ($planSlug !== 'tiktok_free') return $next($request);

        $path = $request->path();

        // Rota permitida?
        $allowed = false;
        foreach (self::ALLOWED_PATTERNS as $pattern) {
            if ($request->is($pattern)) { $allowed = true; break; }
        }
        if (! $allowed) {
            return response()->json([
                'error'         => 'upgrade_required',
                'message'       => 'Este recurso e exclusivo dos planos pagos. Faca upgrade para desbloquear.',
                'current_tier'  => 'tiktok_free',
                'path_blocked'  => $path,
            ], 403);
        }

        // SEL-085 vuln #4+#15: sanitiza params bloqueados
        foreach (self::BLOCKED_PARAMS as $pattern => $params) {
            if ($request->is($pattern)) {
                foreach ($params as $p) {
                    if ($request->has($p)) $request->query->remove($p);
                }
            }
        }

        // SEL-085 vuln #3+#7: cap per_page
        foreach (self::PER_PAGE_CAPS as $pattern => $max) {
            if ($request->is($pattern)) {
                $pp = (int) $request->query('per_page', $max);
                if ($pp > $max) $request->query->set('per_page', $max);
                break;
            }
        }

        return $next($request);
    }
}
