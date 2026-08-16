<?php

namespace App\Services\Ai;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SEL-490 — LIMITE DE GERAÇÃO POR PLANO (server-side, impossível burlar pelo front).
 *
 * Ruan 07/08:
 *   - R$29,90 (video_start): 1 vídeo a cada 24h. Contado por user_id no servidor.
 *   - R$149  (video_pro):   ilimitado, SEM prioridade, modelo Lite/grátis.
 *   - R$297  (video_ultra): ilimitado, COM prioridade + alta resolução/ultra.
 *   - Grátis / sem plano de vídeo: NÃO gera (pede upgrade).
 *
 * Atomicidade (teste de corrida): a reserva roda dentro de um lock MariaDB
 * GET_LOCK por user_id. Recarregar, request direto na API, dois logins, aba nova
 * e requests paralelos batem TODOS no mesmo contador — a 2ª geração dentro de 24h
 * é recusada. A tabela `video_generation_reservations` é o contador E a trilha
 * anti-revenda (IP + UA + fingerprint por geração).
 *
 * O gate reaproveita VideoAccessGuard::pode() (free-block, pausa, trava dura,
 * cota em reais). Só decide QUANTIDADE/prioridade por cima disso.
 */
class VideoPlanLimitService
{
    /** SEL (09/08, Ruan): user_ids com video ILIMITADO por excecao (ex: al.producoes133 = 659). */
    private const UNLIMITED_USER_IDS = [659, 2943]; // +2943 marcelo@pluglar (Ruan 10/08)

    /** slug do plano => [daily => int|null (null=ilimitado), priority => bool, tier => 'lite'|'ultra']. */
    private const PLANOS = [
        'video_start' => ['daily' => 1,    'priority' => false, 'tier' => 'lite'],
        // SEL-ILIMITADO-SAI-DO-GRATIS (15/08, ordem do Ruan: "liga pro ultra e
        // ilimitado, mantem gratis no resto"). Era 'lite' — quem pagava R$149
        // rodava na MESMA fila gratis que deixou o cliente aemdcar 2h30 esperando
        // e falhar 8 vezes (ele tinha pago o upgrade em 14/08). `priority` segue
        // false de proposito: a ordem foi sobre o MODELO, nao sobre furar fila.
        'video_pro'   => ['daily' => null, 'priority' => false, 'tier' => 'ilimitado'],
        'video_ultra' => ['daily' => null, 'priority' => true,  'tier' => 'ultra'],
    ];

    /** Plano pago sem regra explícita: não bloqueia quantidade, sem prioridade. */
    private const DEFAULT_PAGO = ['daily' => null, 'priority' => false, 'tier' => 'lite'];

    /**
     * Checa acesso + limite e RESERVA (atômico). Chamar ANTES de enfileirar.
     *
     * @return array{ok:bool, status?:int, motivo:string, message:string,
     *   priority:bool, tier:string, unlimited:bool, reservation_id:?int,
     *   plan_slug:?string, retry_after:?string, used?:int, daily?:int}
     */
    public static function checkAndReserve(?int $userId, Request $request): array
    {
        // 1) Porta de acesso já existente (free-block, pausa, trava dura, cota R$).
        $access = VideoAccessGuard::pode($userId);
        if (! $access['ok']) {
            // grátis/sem assinatura vira 403 (upgrade); cota/pausa mantém a msg.
            $status = in_array($access['motivo'], ['plano_gratuito', 'sem_assinatura_ativa', 'assinatura_sem_plano', 'plano_gratuito'], true) ? 403 : 402;
            return self::bloqueio($access['motivo'], $access['mensagem'], $status);
        }

        // Uso interno: super_admin não entra na cota (ilimitado + prioridade).
        if ($access['motivo'] === 'super_admin') {
            return [
                'ok' => true, 'motivo' => 'super_admin', 'message' => '',
                'priority' => true,
                'tier' => 'lite', 'unlimited' => true, 'reservation_id' => null,
                'plan_slug' => 'super_admin', 'retry_after' => null,
            ];
        }

        // SEL (09/08, Ruan): excecao de video ILIMITADO por usuario (ex: al.producoes).
        if ($userId && in_array($userId, self::UNLIMITED_USER_IDS, true)) {
            return [
                'ok' => true, 'motivo' => 'excecao_ilimitado', 'message' => '',
                'priority' => true, 'tier' => 'lite', 'unlimited' => true,
                'reservation_id' => null, 'plan_slug' => 'excecao_ilimitado', 'retry_after' => null,
            ];
        }

        // 2) Resolve plano de vídeo e regra de quantidade/prioridade.
        // SEL (07/08, Ruan): AFILIADO tem prioridade MAS limita 3 vídeos/dia (evita
        // afiliado só gerar vídeo e não vender). Antes era ilimitado.
        $isAfiliado = $access['motivo'] === 'afiliado';
        $slug = $isAfiliado ? 'afiliado' : self::resolveSlug($userId);
        $cfg  = $isAfiliado
            ? ['daily' => 3, 'priority' => true, 'tier' => 'ultra']
            : (self::PLANOS[$slug] ?? self::DEFAULT_PAGO);
        $daily = $cfg['daily'];

        $ip  = mb_substr((string) $request->ip(), 0, 64);
        $ua  = mb_substr((string) $request->userAgent(), 0, 400);
        $fp  = self::fingerprint($request, $ip, $ua);

        $client = DB::table('clients')->where('user_id', $userId)->value('id');

        // 3) Seção crítica: lock por usuário -> conta janela 24h -> reserva.
        $lockName = 'vgen_user_' . $userId;
        $got = DB::selectOne('SELECT GET_LOCK(?, 8) AS l', [$lockName]);
        if (! $got || (int) $got->l !== 1) {
            // Não conseguiu o lock em 8s: outra geração desse usuário em andamento.
            return self::bloqueio('geracao_em_andamento',
                'Você já tem um vídeo sendo gerado agora. Espere ele terminar pra gerar outro.', 429);
        }

        try {
            if ($daily !== null) {
                $since = now()->subDay();
                $used = DB::table('video_generation_reservations')
                    ->where('user_id', $userId)
                    ->where('status', 'reserved')
                    ->where('created_at', '>=', $since)
                    ->count();

                if ($used >= $daily) {
                    $primeira = DB::table('video_generation_reservations')
                        ->where('user_id', $userId)
                        ->where('status', 'reserved')
                        ->where('created_at', '>=', $since)
                        ->min('created_at');
                    $retry = $primeira ? Carbon::parse($primeira)->addDay() : now()->addDay();
                    return array_merge(
                        self::bloqueio('limite_diario',
                            $isAfiliado
                                ? "Você atingiu o limite de vídeos de hoje. Volte amanhã pra gerar mais. :)"
                                : "Seu plano gera {$daily} vídeo por dia. Faça upgrade pro plano Ilimitado e gere quando quiser.", 429),
                        ['retry_after' => $retry->toIso8601String(), 'used' => $used, 'daily' => $daily]
                    );
                }
            }

            $rid = DB::table('video_generation_reservations')->insertGetId([
                'user_id'     => $userId,
                'client_id'   => $client ?: null,
                'plan_slug'   => $slug,
                'status'      => 'reserved',
                'ip'          => $ip,
                'user_agent'  => $ua,
                'fingerprint' => $fp,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        } finally {
            DB::statement('DO RELEASE_LOCK(?)', [$lockName]);
        }

        // 4) Anti-revenda: alerta se o MESMO login gerou de IPs muito diferentes.
        self::alertaIpDivergente($userId, $ip);

        return [
            'ok' => true, 'motivo' => 'ok_' . $slug, 'message' => '',
            'priority' => $cfg['priority'], 'tier' => $cfg['tier'],
            'unlimited' => $daily === null, 'reservation_id' => $rid,
            'plan_slug' => $slug, 'retry_after' => null,
        ];
    }

    /** Marca a reserva como refundada (ex: falhou ao enfileirar) — libera a cota. */
    public static function refund(?int $reservationId): void
    {
        if (! $reservationId) {
            return;
        }
        try {
            DB::table('video_generation_reservations')->where('id', $reservationId)
                ->update(['status' => 'refunded', 'updated_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning('[SEL-490] falha ao refundar reserva', ['id' => $reservationId, 'err' => $e->getMessage()]);
        }
    }

    /** Vincula a reserva ao pipeline criado (rastreio). */
    public static function attachPipeline(?int $reservationId, int|string $pipelineId): void
    {
        if (! $reservationId) {
            return;
        }
        try {
            DB::table('video_generation_reservations')->where('id', $reservationId)
                ->update(['pipeline_id' => (int) $pipelineId, 'updated_at' => now()]);
        } catch (\Throwable $e) { /* rastreio secundário */ }
    }

    /** slug do plano de vídeo ativo do usuário (ou null). */
    public static function resolveSlug(?int $userId): ?string
    {
        $client = DB::table('clients')->where('user_id', $userId)->value('id');
        if (! $client) {
            return null;
        }
        return DB::table('subscriptions as s')
            ->leftJoin('plans as p', 'p.id', '=', 's.plan_id')
            ->where('s.client_id', $client)
            ->whereIn('s.status', ['active'])
            ->orderByDesc('s.id')
            ->value('p.slug');
    }

    private static function fingerprint(Request $request, string $ip, string $ua): string
    {
        // front pode mandar um fingerprint explícito; senão derivamos de sinais estáveis.
        $explicit = (string) ($request->input('fingerprint') ?? $request->header('X-Device-Fingerprint') ?? '');
        if ($explicit !== '') {
            return mb_substr($explicit, 0, 128);
        }
        $al = (string) $request->header('Accept-Language');
        return hash('sha256', $ip . '|' . $ua . '|' . $al);
    }

    private static function alertaIpDivergente(?int $userId, string $ip): void
    {
        try {
            $ips = DB::table('video_generation_reservations')
                ->where('user_id', $userId)
                ->where('created_at', '>=', now()->subHour())
                ->distinct()->pluck('ip')->filter()->values();
            if ($ips->count() >= 3) {
                Log::warning('[SEL-490][anti-revenda] mesmo login gerando de IPs diferentes', [
                    'user_id' => $userId, 'ips_1h' => $ips->all(), 'ip_atual' => $ip,
                ]);
            }
        } catch (\Throwable $e) { /* alerta secundário */ }
    }

    private static function bloqueio(string $motivo, string $msg, int $status): array
    {
        return [
            'ok' => false, 'status' => $status, 'motivo' => $motivo, 'message' => $msg,
            'priority' => false, 'tier' => 'lite', 'unlimited' => false,
            'reservation_id' => null, 'plan_slug' => null, 'retry_after' => null,
        ];
    }
}
