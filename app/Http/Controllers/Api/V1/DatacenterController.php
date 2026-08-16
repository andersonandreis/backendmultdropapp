<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-DC — Data Center ao vivo: estado real do pool de geração de video.
 * Read-only, protegido por chave simples (a pagina estatica /datacenter le com a chave).
 * Nao expoe PII: cliente aparece so como "Cliente #id" e contagens agregadas.
 */
class DatacenterController extends Controller
{
    private const KEY = 'sel-datacenter-live-2026';

    public function live(Request $request)
    {
        if ($request->query('key') !== self::KEY) {
            return response()->json(['error' => 'forbidden'], 403)
                ->header('Access-Control-Allow-Origin', '*');
        }

        $isVideo = null;
        // descobre o tool_type de video a partir do motor Mac
        $mac = DB::table('ai_engines')->where('name', 'like', '%Mac%')->first();
        $videoType = $mac->tool_type ?? null;

        $today = now()->toDateString();
        $usage = DB::table('ai_engine_usage')->where('date', $today)->get()->keyBy('engine_id');

        // ---- MOTORES ----
        $engines = [];
        $rows = DB::table('ai_engines')->orderBy('priority')->get();
        foreach ($rows as $e) {
            $cfg = json_decode($e->config_json ?? '{}', true) ?: [];
            $u = $usage[$e->id] ?? null;
            $kind = $e->tool_type === $videoType ? 'video' : ($e->tool_type ?: 'outro');
            $cooldown = $e->cooldown_until ?? null;
            $onCooldown = $cooldown && strtotime($cooldown) > time();
            // Rotulo GENERICO pra live (sem email/Flow/nome). Ex: "Data 12".
            $display = $cfg['display'] ?? ('Data ' . str_pad((string) $e->id, 2, '0', STR_PAD_LEFT));
            $engines[] = [
                'id'            => $e->id,
                'name'          => $display,
                'display'       => $display,
                'email'         => null, // nunca expor email no painel
                'precisa_reconectar' => (bool) ($cfg['precisa_reconectar'] ?? false),
                'saldo'         => isset($cfg['saldo']) ? (int) $cfg['saldo'] : null,
                'credito_baixo' => isset($cfg['saldo']) && (int) $cfg['saldo'] < 2000,
                'kind'          => $kind,
                'provider'      => $e->provider ?? null,
                'active'        => (int) $e->is_active === 1,
                'priority'      => (int) $e->priority,
                'quota_per_day' => $cfg['quota_per_day'] ?? null,
                'quota_type'    => $cfg['quota_type'] ?? null,
                'credits'       => $cfg['credits'] ?? null,      // preenchido pelo pool-manager
                'videos_today'  => $u->generated_count ?? 0,
                'reserved_today'=> $u->reserved_count ?? 0,
                'last_used_at'  => $u->last_used_at ?? null,
                'health'        => $onCooldown ? 'cooldown' : ((int) $e->is_active === 1 ? 'ok' : 'off'),
                'cooldown_until'=> $onCooldown ? $cooldown : null,
                'success_24h'   => $e->success_rate_24h ?? null,
                'last_error'    => $e->last_error ? mb_substr($e->last_error, 0, 80) : null,
            ];
        }

        // ---- AO VIVO (ai_video_pipelines) ----
        $live = ['generating_now' => [], 'active_clients' => 0, 'videos_today' => 0, 'avg_seconds_today' => null, 'success_rate_today' => null, 'failed_today' => 0];
        if (Schema::hasTable('ai_video_pipelines')) {
            // gerando agora = sem output, sem erro, mexido nos ultimos 8 min
            $now = DB::table('ai_video_pipelines')
                ->whereNull('output_url')->whereNull('error_message')
                ->where('updated_at', '>', now()->subMinutes(8))
                ->orderByDesc('updated_at')->limit(20)->get();
            foreach ($now as $p) {
                $live['generating_now'][] = [
                    'pipeline'   => $p->id,
                    'client'     => 'Cliente #' . $p->user_id,
                    'product'    => $p->product_key,
                    'mode'       => $p->mode,
                    'step'       => $p->step,
                    'started_at' => $p->created_at,
                    'elapsed_s'  => max(0, time() - strtotime($p->created_at)),
                ];
            }
            $live['active_clients'] = DB::table('ai_video_pipelines')
                ->whereNull('output_url')->whereNull('error_message')
                ->where('updated_at', '>', now()->subMinutes(8))
                ->distinct()->count('user_id');
            $live['videos_today'] = DB::table('ai_video_pipelines')
                ->whereDate('created_at', today())->whereNotNull('output_url')->count();
            $live['failed_today'] = DB::table('ai_video_pipelines')
                ->whereDate('created_at', today())->whereNotNull('error_message')->count();
            // tempo medio (concluidos hoje) = updated - created
            $done = DB::table('ai_video_pipelines')
                ->whereDate('created_at', today())->whereNotNull('output_url')
                ->select(DB::raw('AVG(TIMESTAMPDIFF(SECOND, created_at, updated_at)) as avg_s'))->first();
            $live['avg_seconds_today'] = $done && $done->avg_s ? round((float) $done->avg_s) : null;
            $tot = $live['videos_today'] + $live['failed_today'];
            $live['success_rate_today'] = $tot > 0 ? round($live['videos_today'] / $tot * 100) : null;
        }

        $videoEngines = array_values(array_filter($engines, fn ($e) => $e['kind'] === 'video'));
        $vidOn = count(array_filter($videoEngines, fn ($e) => $e['active']));
        // Contas que deslogaram e precisam reconectar (Camada 3 auto-cura).
        $reconectar = array_values(array_map(fn ($e) => $e['display'], array_filter($videoEngines, fn ($e) => $e['precisa_reconectar'])));
        // Contas com crédito baixo (pra ficar antenado).
        $creditoBaixo = array_values(array_map(fn ($e) => $e['display'] . ' (' . number_format($e['saldo'], 0, ',', '.') . ')', array_filter($videoEngines, fn ($e) => $e['credito_baixo'])));
        $saldoTotal = array_sum(array_map(fn ($e) => $e['saldo'] ?? 0, $videoEngines));

        // SEL security 08/08 (Ruan): ANTES tinha nome real de vendor aqui (Veo 3,
        // Imagen/Whisk, NotebookLM, Deep Research) -- vazava motor real pra quem
        // pegasse a chave estatica do /datacenter (rota sem auth:sanctum, so a
        // chave). Mesma regra de camuflagem que o resto do arquivo ja seguia pros
        // 'engines' (Rotulo GENERICO, sem vendor/email) agora vale aqui tambem.
        $sgFunctions = [
            ['nome' => 'Geracao de Video',    'motor' => 'SG Video Engine',   'status' => $vidOn > 0 ? 'ativo' : 'parado', 'detalhe' => $vidOn . ' contas gerando'],
            ['nome' => 'Geracao de Imagem',   'motor' => 'SG Image Engine',   'status' => 'disponivel', 'detalhe' => 'criativos e avatares'],
            ['nome' => 'Roteiro / Texto',     'motor' => 'SG Text Engine',    'status' => 'ativo',      'detalhe' => 'roteiro automatico'],
            ['nome' => 'Pesquisa de Mercado', 'motor' => 'SG Research Engine','status' => 'disponivel', 'detalhe' => 'analise de nicho'],
            ['nome' => 'Base de Conhecimento','motor' => 'SG Knowledge Base', 'status' => 'disponivel', 'detalhe' => 'docs e runbooks'],
            ['nome' => 'Armazenamento',       'motor' => '30 TB',             'status' => 'ativo',      'detalhe' => 'videos e lives'],
        ];

        return response()->json([
            'ts'               => now()->toIso8601String(),
            'video_engines'    => $videoEngines,
            'video_on'         => $vidOn,
            'video_total'      => count($videoEngines),
            'reconectar'       => $reconectar,
            'credito_baixo'    => $creditoBaixo,
            'saldo_total'      => $saldoTotal,
            'google_functions'    => $sgFunctions,
            'live'             => $live,
        ])->header('Access-Control-Allow-Origin', '*');
    }
}
