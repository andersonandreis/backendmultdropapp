<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SEL-358: Feed global de vídeos gerados — visível só pra super_admin.
 * GET /api/v1/admin/videostudio/feed
 * Une ai_generations (service=video) + ai_video_pipelines (output_url IS NOT NULL).
 */
class VideoFeedController extends Controller
{
    public function index(Request $request)
    {
        $perPage    = (int) $request->query('per_page', 24);
        $perPage    = max(1, min(100, $perPage));
        $page       = (int) $request->query('page', 1);
        $page       = max(1, $page);
        $modeFilter = $request->query('mode', 'all');
        $userFilter = $request->query('user_id');
        $search     = $request->query('search');

        // ----------------------------------------------------------------
        // Fonte 1: ai_generations WHERE service='video' AND output_url IS NOT NULL
        // ----------------------------------------------------------------
        $genQ = DB::table('ai_generations as ag')
            ->leftJoin('users as u', 'u.id', '=', 'ag.user_id')
            ->leftJoin('clients as c', 'c.id', '=', 'ag.tenant_id')
            ->leftJoin('subscriptions as s', function ($j) {
                $j->on('s.client_id', '=', 'c.id')
                  ->whereIn('s.status', ['active', 'trialing'])
                  ->whereRaw('s.id = (SELECT MAX(s2.id) FROM subscriptions s2 WHERE s2.client_id = c.id)');
            })
            ->leftJoin('plans as p', 'p.id', '=', 's.plan_id')
            ->where('ag.service', 'video')
            ->whereNotNull('ag.output_url')
            ->select([
                DB::raw("CONCAT('gen-', ag.id) AS id"),
                DB::raw("'ai_generation' AS source"),
                'ag.user_id',
                'u.name as user_name',
                'u.email as user_email',
                'u.role as user_role',
                'p.slug as plan_slug',
                'p.name as plan_name',
                DB::raw("NULL AS product_key"),
                DB::raw("JSON_UNQUOTE(JSON_EXTRACT(ag.wizard_payload, '$.product_name')) AS product_name"),
                DB::raw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(ag.wizard_payload, '$.style')), 'video') AS mode"),
                'ag.provider',
                'ag.output_url',
                'ag.cost_usd',
                'ag.credits_debited as credits',
                'ag.status',
                'ag.created_at',
            ]);

        // ----------------------------------------------------------------
        // Fonte 2: ai_video_pipelines WHERE output_url IS NOT NULL
        // ----------------------------------------------------------------
        $pipeQ = DB::table('ai_video_pipelines as vp')
            ->leftJoin('users as u', 'u.id', '=', 'vp.user_id')
            ->leftJoin('clients as c', 'c.user_id', '=', 'vp.user_id')
            ->leftJoin('subscriptions as s', function ($j) {
                $j->on('s.client_id', '=', 'c.id')
                  ->whereIn('s.status', ['active', 'trialing'])
                  ->whereRaw('s.id = (SELECT MAX(s2.id) FROM subscriptions s2 WHERE s2.client_id = c.id)');
            })
            ->leftJoin('plans as p', 'p.id', '=', 's.plan_id')
            ->whereNotNull('vp.output_url')
            // SEL 10/08 dedup: exclui pipe-* que ja tem gen-* (mesmo video em 2 tabelas).
            ->whereRaw("NOT EXISTS (SELECT 1 FROM ai_generations g WHERE g.provider_task_id = CONCAT('studio-pipe-', vp.id))")
            ->select([
                DB::raw("CONCAT('pipe-', vp.id) AS id"),
                DB::raw("'pipeline' AS source"),
                'vp.user_id',
                'u.name as user_name',
                'u.email as user_email',
                'u.role as user_role',
                'p.slug as plan_slug',
                'p.name as plan_name',
                'vp.product_key',
                DB::raw("NULL AS product_name"),
                'vp.mode',
                DB::raw("'kling' AS provider"),
                'vp.output_url',
                DB::raw("0 AS cost_usd"),
                DB::raw("0 AS credits"),
                DB::raw("CASE WHEN vp.step = 'done' THEN 'succeeded' ELSE vp.step END AS status"),
                'vp.created_at',
            ]);

        // ----------------------------------------------------------------
        // Aplicar filtros
        // ----------------------------------------------------------------
        if ($modeFilter && $modeFilter !== 'all') {
            $genQ->where(DB::raw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(ag.wizard_payload, '$.style')), 'video')"), $modeFilter);
            $pipeQ->where('vp.mode', $modeFilter);
        }

        if ($userFilter) {
            $genQ->where('ag.user_id', (int) $userFilter);
            $pipeQ->where('vp.user_id', (int) $userFilter);
        }

        if ($search) {
            $like = '%' . $search . '%';
            $genQ->where(function ($q) use ($like) {
                $q->where('u.name', 'like', $like)
                  ->orWhere('u.email', 'like', $like)
                  ->orWhere(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(ag.wizard_payload, '$.product_name'))"), 'like', $like);
            });
            $pipeQ->where(function ($q) use ($like) {
                $q->where('u.name', 'like', $like)
                  ->orWhere('u.email', 'like', $like)
                  ->orWhere('vp.product_key', 'like', $like);
            });
        }

        // ----------------------------------------------------------------
        // UNION + paginação manual
        // ----------------------------------------------------------------
        $union = $genQ->unionAll($pipeQ);

        // Total sem paginação
        $totalResult = DB::table(DB::raw("({$union->toSql()}) as feed_union"))
            ->mergeBindings($union)
            ->count();
        $totalResult = max(0, $totalResult - count(self::idsOcultos()));

        // Dados paginados
        $offset = ($page - 1) * $perPage;
        $rows = DB::table(DB::raw("({$union->toSql()}) as feed_union"))
            ->mergeBindings($union)
            ->orderByDesc('created_at')
            ->offset($offset)
            ->limit($perPage)
            ->get();

        // SEL-405: tira da galeria o que o admin escondeu (nao apaga o registro).
        // Filtro em PHP porque o union ja vem montado; volume da galeria e pequeno.
        $ocultosSet = array_flip(self::idsOcultos());
        $rows = $rows->reject(function ($row) use ($ocultosSet) {
            return isset($ocultosSet[(string) $row->id]);
        })->values();

        $data = $rows->map(function ($row) {
            return [
                'id'         => $row->id,
                'source'     => $row->source,
                'user'       => [
                    'id'    => $row->user_id,
                    'name'  => $row->user_name ?? 'Desconhecido',
                    'email' => $row->user_email ?? '',
                    'role'  => $row->user_role ?? 'client',
                    'plan'  => $row->plan_name ?? ($row->plan_slug ?? 'free'),
                ],
                'product'    => [
                    'key'  => $row->product_key,
                    'name' => $row->product_name,
                ],
                'mode'       => $row->mode ?? 'video',
                'provider'   => $row->provider ?? 'kling',
                'output_url' => $row->output_url,
                'cost_usd'   => (float) $row->cost_usd,
                'credits'    => (int) $row->credits,
                'status'     => $row->status ?? 'succeeded',
                'created_at' => $row->created_at,
            ];
        });

        // Lista de users distintos para o filtro do front
        $usersForFilter = DB::table('users')
            ->whereIn('id', function ($q) {
                $q->select('user_id')->from('ai_generations')
                  ->where('service', 'video')->whereNotNull('output_url')
                  ->union(
                      DB::table('ai_video_pipelines')->select('user_id')->whereNotNull('output_url')
                  );
            })
            ->select('id', 'name', 'email')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $data,
            'meta' => [
                'total'        => $totalResult,
                'per_page'     => $perPage,
                'current_page' => $page,
                'last_page'    => (int) ceil($totalResult / $perPage),
            ],
            'filters' => [
                'users' => $usersForFilter,
            ],
        ]);
    }

    /**
     * SEL-405: galeria do admin — Ruan quer TODOS os videos gerados guardados e
     * poder tirar da tela os que nao quer mostrar na live.
     *
     * "Excluir" aqui = ocultar da galeria, nao apagar o registro. O historico de
     * geracao continua intacto (e reversivel), e nada some do banco por engano.
     * Guardado na tabela `settings` pra nao exigir migration em repo compartilhado
     * pelos 7 backends.
     */
    private const CHAVE_OCULTOS = 'video_feed_ocultos';

    public static function idsOcultos(): array
    {
        try {
            $v = \DB::table('settings')->where('key', self::CHAVE_OCULTOS)->value('value');
            $d = $v ? json_decode($v, true) : [];
            return is_array($d) ? $d : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function salvaOcultos(array $ids): void
    {
        \DB::table('settings')->updateOrInsert(
            ['key' => self::CHAVE_OCULTOS],
            ['group' => 'video', 'value' => json_encode(array_values(array_unique($ids))), 'updated_at' => now()]
        );
    }

    /** DELETE /v1/admin/videostudio/feed/{origem}/{id} — tira da galeria */
    public function ocultarDaGaleria(string $id)
    {
        $chave  = trim($id);
        $ocultos = self::idsOcultos();
        if (! in_array($chave, $ocultos, true)) {
            $ocultos[] = $chave;
            self::salvaOcultos($ocultos);
        }
        return response()->json(['ok' => true, 'oculto' => $chave, 'total_ocultos' => count($ocultos)]);
    }

    /** POST /v1/admin/videostudio/feed/{origem}/{id}/restaurar — devolve pra galeria */
    public function restaurarNaGaleria(string $id)
    {
        $chave  = trim($id);
        $ocultos = array_values(array_filter(self::idsOcultos(), fn ($c) => $c !== $chave));
        self::salvaOcultos($ocultos);
        return response()->json(['ok' => true, 'restaurado' => $chave, 'total_ocultos' => count($ocultos)]);
    }
}
