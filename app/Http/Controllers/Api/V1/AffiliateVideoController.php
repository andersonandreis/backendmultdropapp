<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SEL-486c (hotfix aplicado direto em producao, NAO commitado) — Galeria de videos
 * gerados PARA O AFILIADO.
 *
 * Ruan (02/08): expor TODOS os videos gerados na galeria do painel do afiliado, pra
 * ele visualizar o maximo possivel, com botao de EXCLUIR (da visao DELE).
 *
 * Regras:
 *  - LISTA todos os videos prontos (ai_video_pipelines + ai_generations com output_url),
 *    SEM vazar dados sensiveis de outros usuarios (NAO retorna email/plano/nome de quem
 *    gerou — diferente do VideoFeedController do admin).
 *  - "Excluir" = OCULTAR da visao DAQUELE afiliado (soft, por user_id, na tabela settings).
 *    NAO apaga o registro nem o arquivo mp4 (que e compartilhado). Reversivel (restore).
 *  - Sem migration (repo compartilhado por 7 backends) — usa `settings` igual o SEL-405.
 */
class AffiliateVideoController extends Controller
{
    /** GET /api/v1/affiliate/videos — lista todos os videos gerados, menos os ocultos deste afiliado. */
    public function index(Request $request)
    {
        $userId  = (int) ($request->user()?->id ?? 0);
        $perPage = max(1, min(60, (int) $request->query('per_page', 24)));
        $page    = max(1, (int) $request->query('page', 1));

        // Fonte 1: ai_video_pipelines prontos
        $pipeQ = DB::table('ai_video_pipelines as vp')
            ->whereNotNull('vp.output_url')
            ->where('vp.step', 'done')
            // SEL 10/08 dedup galeria: o pipeline com video ja vira linha em
            // ai_generations (registrarNaGaleria, provider_task_id='studio-pipe-<id>').
            // A galeria une as 2 tabelas -> mesmo video aparecia 2x. Aqui exclui o
            // pipe-* que JA tem gen-* (mantem o gen, mais rico). Zero exclusao de dado.
            ->whereRaw("NOT EXISTS (SELECT 1 FROM ai_generations g WHERE g.provider_task_id = CONCAT('studio-pipe-', vp.id))")
            ->select([
                DB::raw("CONCAT('pipe-', vp.id) AS id"),
                'vp.output_url',
                'vp.mode',
                DB::raw("NULL AS product_name"),
                'vp.created_at',
            ]);

        // Fonte 2: ai_generations (service=video) prontos
        $genQ = DB::table('ai_generations as ag')
            ->where('ag.service', 'video')
            ->whereNotNull('ag.output_url')
            ->select([
                DB::raw("CONCAT('gen-', ag.id) AS id"),
                'ag.output_url',
                DB::raw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(ag.wizard_payload, '$.style')), 'video') AS mode"),
                DB::raw("JSON_UNQUOTE(JSON_EXTRACT(ag.wizard_payload, '$.product_name')) AS product_name"),
                'ag.created_at',
            ]);

        $union = $pipeQ->unionAll($genQ);

        $rows = DB::table(DB::raw("({$union->toSql()}) as g"))
            ->mergeBindings($union)
            ->orderByDesc('created_at')
            ->get();

        // Oculta o que ESTE afiliado escondeu (por user_id).
        $ocultos = array_flip(self::idsOcultos($userId));
        $rows = $rows->reject(fn ($r) => isset($ocultos[(string) $r->id]))->values();

        $total  = $rows->count();
        $offset = ($page - 1) * $perPage;
        $data = $rows->slice($offset, $perPage)->values()->map(function ($r) {
            return [
                'id'         => $r->id,
                'video_url'  => $r->output_url,   // mp4 (serve de preview no <video>)
                'thumb_url'  => null,             // (thumbnail on-demand = melhoria futura)
                'mode'       => $r->mode ?? 'video',
                'product'    => $r->product_name,
                'created_at' => $r->created_at,
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'last_page'    => (int) ceil(max(1, $total) / $perPage),
            ],
        ]);
    }

    /** DELETE /api/v1/affiliate/videos/{id} — oculta da visao deste afiliado (nao apaga). */
    public function hide(Request $request, string $id)
    {
        $userId = (int) ($request->user()?->id ?? 0);
        $chave  = trim($id);
        $ocultos = self::idsOcultos($userId);
        if (! in_array($chave, $ocultos, true)) {
            $ocultos[] = $chave;
            self::salvaOcultos($userId, $ocultos);
        }
        return response()->json(['ok' => true, 'oculto' => $chave, 'total_ocultos' => count($ocultos)]);
    }

    /** POST /api/v1/affiliate/videos/{id}/restore — devolve o video pra visao do afiliado. */
    public function restore(Request $request, string $id)
    {
        $userId = (int) ($request->user()?->id ?? 0);
        $chave  = trim($id);
        $ocultos = array_values(array_filter(self::idsOcultos($userId), fn ($x) => $x !== $chave));
        self::salvaOcultos($userId, $ocultos);
        return response()->json(['ok' => true, 'restaurado' => $chave, 'total_ocultos' => count($ocultos)]);
    }

    // ── storage dos ocultos por afiliado (settings, sem migration) ──────────────
    private static function chave(int $userId): string
    {
        return 'affiliate_video_hidden:' . $userId;
    }

    public static function idsOcultos(int $userId): array
    {
        try {
            $v = DB::table('settings')->where('key', self::chave($userId))->value('value');
            $d = $v ? json_decode($v, true) : [];
            return is_array($d) ? $d : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function salvaOcultos(int $userId, array $ids): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => self::chave($userId)],
            ['group' => 'affiliate_video', 'value' => json_encode(array_values(array_unique($ids))), 'updated_at' => now()]
        );
    }
}
