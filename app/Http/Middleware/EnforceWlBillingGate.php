<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * SEL-424 — Gate de cobrança por White Label.
 *
 * Consulta a tabela `whitelabel_billing_config` no Supabase HubAI e bloqueia
 * clientes comuns (role=client) com HTTP 402 se a WL estiver marcada como
 * inadimplente (is_blocked=true OU blocked_at IS NOT NULL).
 *
 * Bypass automático:
 *   - Rotas públicas: /api/health, /api/login, /api/register*, /api/webhooks/*,
 *     /api/internal/*, /api/checkout/*, /api/public-config, /api/forgot-password,
 *     /api/reset-password, /api/v1/live/login, dropauto, tenant-api, federation
 *   - Usuários com role != 'client' (admin, supplier, super_admin entram sempre)
 *   - Backends com APP_TENANT=hubai (plataforma interna — nunca bloqueia)
 *
 * Cache Redis TTL 60 s por chave `wl_billing_gate:{empresa_nome}`.
 * Em caso de erro ao consultar o Supabase, permite a passagem (fail-open)
 * e registra log de warning para não travar clientes por falha de infra.
 *
 * Mapeamento APP_TENANT → empresa_nome (Supabase):
 *   hubai       → Goolhub   (is_internal=true, nunca bloqueia)
 *   multdrop    → MultDrop
 *   fornecefy   → Fornecefy
 *   mestoredrop → MEStoreDrop
 *   jtdrop      → JTDrop
 *   dropksr     → DropKsr
 */
class EnforceWlBillingGate
{
    /** Prefixo da chave de cache Redis (TTL 60s). */
    private const CACHE_PREFIX = 'wl_billing_gate:';
    private const CACHE_TTL    = 60; // segundos

    /** Padrões de path (prefixo) que NUNCA passam pelo gate. */
    private const BYPASS_PREFIXES = [
        'api/health',
        'api/login',
        'api/google-login',
        'api/verify-2fa',
        'api/register',
        'api/forgot-password',
        'api/reset-password',
        'api/public-config',
        'api/webhooks',
        'api/internal',
        'api/checkout',
        'api/v1/live/login',
        'dropauto',
        'tenant-api',
        'federation',
        'up',
    ];

    /**
     * Mapeamento APP_TENANT → empresa_nome na tabela Supabase.
     * A plataforma-mãe (hubai/Goolhub) é is_internal=true e nunca bloqueia.
     */
    private const TENANT_MAP = [
        'multdrop'    => 'MultDrop',
        'fornecefy'   => 'Fornecefy',
        'mestoredrop' => 'MEStoreDrop',
        'jtdrop'      => 'JTDrop',
        'dropksr'     => 'DropKsr',
        // hubai = Goolhub (is_internal=true) — listado só para documentação,
        // mas o early-return abaixo trata antes de chegar aqui.
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // 0. Desligado por padrao. O gate nasceu na branch do api.seller.global e chegou ao
        //    main num merge; como os 7 backends compartilham o repo, ele passou a valer em
        //    todos e em 06/08/2026 02:27 bloqueou o proprio MultDrop por "nao pagamento".
        //    Fica no codigo, mas so age onde for ligado explicitamente no .env.
        if (! config('services.wl_billing_gate.enabled')) {
            return $next($request);
        }

        // 1. Bypass: tenant interno (plataforma-mãe HubAI nunca bloqueia)
        $tenant = config('app.tenant', env('APP_TENANT', 'hubai'));
        if ($tenant === 'hubai') {
            return $next($request);
        }

        // 2. Bypass: rotas públicas / webhooks / internal
        $path = ltrim($request->path(), '/');
        foreach (self::BYPASS_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return $next($request);
            }
        }

        // 3. Bypass: usuário não autenticado (login ainda não aconteceu)
        //    ou usuário com role diferente de client (admin/supplier passam)
        $user = $request->user();
        if (! $user || $user->role !== 'client') {
            return $next($request);
        }

        // 4. Resolve empresa_nome pelo APP_TENANT
        $empresaNome = self::TENANT_MAP[$tenant] ?? null;
        if (! $empresaNome) {
            // Tenant desconhecido — fail-open com log
            Log::warning('[SEL-424] EnforceWlBillingGate: APP_TENANT desconhecido', [
                'tenant' => $tenant,
                'path'   => $path,
            ]);
            return $next($request);
        }

        // 5. Consulta flag de bloqueio (com cache Redis 60s)
        $isBlocked = $this->isWlBlocked($empresaNome);

        if ($isBlocked) {
            Log::info('[SEL-424] WL bloqueada — acesso negado', [
                'tenant'      => $tenant,
                'empresa'     => $empresaNome,
                'user_id'     => $user->id,
                'path'        => $path,
            ]);

            return response()->json([
                'error'   => 'billing_blocked',
                'message' => 'Sua conta está temporariamente suspensa por pendência financeira. Entre em contato com o suporte para regularizar.',
            ], Response::HTTP_PAYMENT_REQUIRED); // 402
        }

        return $next($request);
    }

    /**
     * Consulta Supabase e retorna true se a WL está bloqueada.
     * Falha de conexão → fail-open (retorna false com log de warning).
     */
    private function isWlBlocked(string $empresaNome): bool
    {
        $cacheKey = self::CACHE_PREFIX . strtolower(str_replace(' ', '_', $empresaNome));

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($empresaNome) {
            try {
                $url    = rtrim(config('services.supabase.url', env('SUPABASE_URL')), '/');
                $key    = config('services.supabase.anon_key', env('SUPABASE_ANON_KEY'));

                if (! $url || ! $key) {
                    Log::warning('[SEL-424] SUPABASE_URL ou SUPABASE_ANON_KEY não configurado', [
                        'empresa' => $empresaNome,
                    ]);
                    return false; // fail-open
                }

                $response = Http::timeout(3)
                    ->withHeaders([
                        'apikey'        => $key,
                        'Authorization' => 'Bearer ' . $key,
                    ])
                    ->get("{$url}/rest/v1/whitelabel_billing_config", [
                        'select'       => 'is_blocked,blocked_at',
                        'empresa_nome' => 'eq.' . $empresaNome,
                        'limit'        => '1',
                    ]);

                if (! $response->successful()) {
                    Log::warning('[SEL-424] Supabase retornou erro', [
                        'empresa' => $empresaNome,
                        'status'  => $response->status(),
                    ]);
                    return false; // fail-open
                }

                $rows = $response->json();
                if (empty($rows)) {
                    Log::warning('[SEL-424] WL não encontrada na tabela billing_config', [
                        'empresa' => $empresaNome,
                    ]);
                    return false; // fail-open: WL não cadastrada não bloqueia
                }

                $row = $rows[0];
                return (bool) ($row['is_blocked'] ?? false)
                    || ! empty($row['blocked_at']);

            } catch (\Throwable $e) {
                Log::warning('[SEL-424] Erro ao consultar Supabase billing gate', [
                    'empresa' => $empresaNome,
                    'error'   => $e->getMessage(),
                ]);
                return false; // fail-open: nunca travar cliente por falha de infra
            }
        });
    }
}
