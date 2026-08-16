<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * NOV-XXX — Gate de cobranca do DONO da WL (role=supplier) no painel Filament /admin.
 *
 * Irmao do EnforceWlBillingGate (SEL-424, que trava role=client via middleware da API).
 * Este trava o fornecedor/dono da WL (role=supplier) dentro do painel Filament quando a
 * WL esta marcada como inadimplente em `whitelabel_billing_config` (Supabase HubAI).
 *
 * NAO mexe no cliente final (role=client nem acessa o painel /admin, so o /app) nem no
 * super_admin (Ruan sempre passa, independente do estado da WL).
 *
 * Reusa a MESMA chave de cache Redis do EnforceWlBillingGate (`wl_billing_gate:{slug}`)
 * — e o mesmo dado (is_blocked da mesma WL) — entao o flush feito pelo painel
 * seller.global (AdminWlController::flushBillingCache, SEL-430) libera os dois gates
 * juntos, sem esperar o TTL de 60s.
 *
 * Licao do incidente SEL-424 (06/08/2026 02:27 — MultDrop bloqueado sem querer porque o
 * gate nasceu numa branch e chegou ao main compartilhado por 7 backends): este gate
 * nasce DIRETO em main, mas 100% inerte por padrao. Só age no backend cujo .env tiver
 * WL_OWNER_BILLING_GATE_ENABLED=true explicitamente. Nunca mudar esse default no código.
 *
 * Aplicado apenas no stack de middleware do painel Filament `admin` (ver
 * AdminPanelProvider::panel()) — não é middleware global de API, não toca em nenhuma
 * rota /api/*.
 */
class EnforceWlOwnerBillingGate
{
    /** MESMA chave de cache do EnforceWlBillingGate (SEL-424) — dado é o mesmo. */
    private const CACHE_PREFIX = 'wl_billing_gate:';
    private const CACHE_TTL    = 60; // segundos

    /** Slug da página de bloqueio — nunca redireciona pra ela mesma (evita loop). */
    public const BLOCKED_PAGE_SLUG = 'pagamento-pendente';

    /** Sufixos de rota do painel que sempre passam, mesmo com a WL bloqueada. */
    private const BYPASS_PATH_SUFFIXES = [
        self::BLOCKED_PAGE_SLUG,
        'logout',
    ];

    /**
     * Mapeamento APP_TENANT → empresa_nome na tabela Supabase.
     * Cópia intencional do mesmo mapa do EnforceWlBillingGate (SEL-424) — arquivo
     * novo e independente para não arriscar regressão no gate de cliente já em produção.
     */
    private const TENANT_MAP = [
        'multdrop'    => 'MultDrop',
        'fornecefy'   => 'Fornecefy',
        'mestoredrop' => 'MEStoreDrop',
        'jtdrop'      => 'JTDrop',
        'dropksr'     => 'DropKsr',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // 0. Bypass: rota da própria página de bloqueio / logout — evita loop de redirect.
        $path = trim($request->path(), '/');
        foreach (self::BYPASS_PATH_SUFFIXES as $suffix) {
            if (str_ends_with($path, $suffix)) {
                return $next($request);
            }
        }

        if (self::isCurrentUserGated()) {
            $user = $request->user();
            Log::info('[WL-OWNER-GATE] WL bloqueada — dono do WL redirecionado', [
                'tenant'  => config('app.tenant', env('APP_TENANT', 'hubai')),
                'user_id' => $user?->id,
                'path'    => $path,
            ]);

            return redirect()->to('/admin/' . self::BLOCKED_PAGE_SLUG);
        }

        return $next($request);
    }

    /**
     * True se o usuário logado agora (role=supplier) deve ser barrado por
     * inadimplência da própria WL. Usado tanto pelo middleware (redirect) quanto
     * pelo render hook de reforço no AdminPanelProvider (overlay visual).
     *
     * Sempre false se a flag estiver desligada, se o tenant for o hub (hubai), se
     * não houver usuário autenticado, ou se o usuário não for role=supplier.
     */
    public static function isCurrentUserGated(): bool
    {
        // Desligado por padrão — só age no backend que ligar explicitamente no .env.
        if (! config('services.wl_owner_billing_gate.enabled')) {
            return false;
        }

        // Hub (plataforma-mãe) nunca bloqueia — supplier ali pode pertencer a
        // qualquer WL via ScopePanelToSupplier, não faz sentido gatear pelo tenant.
        $tenant = config('app.tenant', env('APP_TENANT', 'hubai'));
        if ($tenant === 'hubai') {
            return false;
        }

        $user = auth()->user();
        if (! $user || $user->role !== 'supplier') {
            return false;
        }

        $empresaNome = self::TENANT_MAP[$tenant] ?? null;
        if (! $empresaNome) {
            Log::warning('[WL-OWNER-GATE] APP_TENANT desconhecido', ['tenant' => $tenant]);
            return false;
        }

        return self::isWlBlocked($empresaNome);
    }

    /**
     * Consulta Supabase (com cache Redis 60s, compartilhado com o EnforceWlBillingGate)
     * e retorna true se a WL está bloqueada. Falha de conexão → fail-open (retorna
     * false com log de warning) — nunca travar o dono por instabilidade de infra.
     */
    private static function isWlBlocked(string $empresaNome): bool
    {
        $cacheKey = self::CACHE_PREFIX . strtolower(str_replace(' ', '_', $empresaNome));

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($empresaNome) {
            try {
                $url = rtrim(config('services.supabase.url', env('SUPABASE_URL')), '/');
                $key = config('services.supabase.anon_key', env('SUPABASE_ANON_KEY'));

                if (! $url || ! $key) {
                    Log::warning('[WL-OWNER-GATE] SUPABASE_URL ou SUPABASE_ANON_KEY não configurado', [
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
                    Log::warning('[WL-OWNER-GATE] Supabase retornou erro', [
                        'empresa' => $empresaNome,
                        'status'  => $response->status(),
                    ]);
                    return false; // fail-open
                }

                $rows = $response->json();
                if (empty($rows)) {
                    Log::warning('[WL-OWNER-GATE] WL não encontrada na tabela billing_config', [
                        'empresa' => $empresaNome,
                    ]);
                    return false; // fail-open: WL não cadastrada não bloqueia
                }

                $row = $rows[0];

                return (bool) ($row['is_blocked'] ?? false)
                    || ! empty($row['blocked_at']);
            } catch (\Throwable $e) {
                Log::warning('[WL-OWNER-GATE] Erro ao consultar Supabase billing gate', [
                    'empresa' => $empresaNome,
                    'error'   => $e->getMessage(),
                ]);
                return false; // fail-open: nunca travar por falha de infra
            }
        });
    }
}
