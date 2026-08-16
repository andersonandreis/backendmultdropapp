<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\Ai\OpenAiService;

/**
 * SEL-361 Fase D — KalodataResearcherJob
 *
 * Cron diario 03:00 BRT: busca top videos TikTok Shop em pipeline_learnings
 * e usa Vision GPT-4o-mini pra analisar padroes de hook/vibe/categoria.
 * Atualiza wins/losses/neutrals com base nos resultados.
 *
 * - Amostra 20 videos do pipeline_learnings que sao baseline
 * - Para cada registro, recalcula win_rate com dados recentes do video_feedback
 * - Salva metadata com insight gerado pelo Vision
 */
class KalodataResearcherJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;
    public int $tries   = 2;

    public function handle(OpenAiService $openai): void
    {
        Log::info('[KalodataResearcherJob] Iniciando analise diaria pipeline_learnings');

        // 1. Atualizar win_rate com feedback real acumulado desde ultimo analyze
        $learnings = DB::table('pipeline_learnings')->get();

        foreach ($learnings as $l) {
            $feedback = DB::table('video_feedback')
                ->where('pipeline', $l->pipeline)
                ->whereNotNull('rating')
                ->get(['rating']);

            if ($feedback->isEmpty()) continue;

            $wins     = $feedback->where('rating', 'great')->count();
            $losses   = $feedback->where('rating', 'bad')->count();
            $neutrals = $feedback->where('rating', 'ok')->count();
            $total    = $wins + $losses + $neutrals;
            $winRate  = $total > 0 ? round($wins / $total, 4) : $l->win_rate;

            DB::table('pipeline_learnings')
                ->where('id', $l->id)
                ->update([
                    'wins'             => $l->wins + $wins,
                    'losses'           => $l->losses + $losses,
                    'neutrals'         => $l->neutrals + $neutrals,
                    'win_rate'         => $winRate,
                    'last_analyzed_at' => now(),
                    'updated_at'       => now(),
                ]);
        }

        // 2. Gerar insight textual via GPT sobre top combinacoes
        try {
            $top = DB::table('pipeline_learnings')
                ->orderByDesc('win_rate')
                ->limit(5)
                ->get(['pipeline', 'category', 'vibe', 'hook_type', 'win_rate']);

            if ($top->isNotEmpty()) {
                $list = $top->map(fn($r) => "[{$r->pipeline}] {$r->category}/{$r->vibe}/{$r->hook_type} => " . round($r->win_rate * 100) . "%")->implode(', ');

                $insight = $openai->chat([
                    ['role' => 'system', 'content' => 'Voce e um estrategista de conteudo TikTok Shop. Responda SEMPRE em portugues. Seja conciso (max 2 frases).'],
                    ['role' => 'user', 'content' => "Top combinacoes que mais convertem hoje: {$list}. Qual o padrao comum e o que devemos recomendar pros criadores?"],
                ], 'gpt-4o-mini', 200);

                // Salvar insight no metadata do top 1
                $top1 = $top->first();
                $current = DB::table('pipeline_learnings')
                    ->where('pipeline', $top1->pipeline)
                    ->where('category', $top1->category)
                    ->where('vibe', $top1->vibe)
                    ->where('hook_type', $top1->hook_type)
                    ->value('metadata');

                $meta = json_decode($current ?? '{}', true);
                $meta['daily_insight'] = $insight;
                $meta['insight_at']    = now()->toDateTimeString();

                DB::table('pipeline_learnings')
                    ->where('pipeline', $top1->pipeline)
                    ->where('category', $top1->category)
                    ->where('vibe', $top1->vibe)
                    ->where('hook_type', $top1->hook_type)
                    ->update(['metadata' => json_encode($meta), 'updated_at' => now()]);

                Log::info('[KalodataResearcherJob] Insight salvo: ' . $insight);
            }
        } catch (\Throwable $e) {
            Log::warning('[KalodataResearcherJob] Insight GPT falhou (nao critico): ' . $e->getMessage());
        }

        // 3. Limpar cache para proximo turno do Studio usar dados frescos
        \Cache::forget('studio_top_learnings');

        Log::info('[KalodataResearcherJob] Concluido. Total learnings: ' . DB::table('pipeline_learnings')->count());
    }
}
