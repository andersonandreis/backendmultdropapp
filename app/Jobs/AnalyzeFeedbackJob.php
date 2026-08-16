<?php
namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SEL-361 Fase C - AnalyzeFeedbackJob
 *
 * Cron diario: agrega feedbacks do dia e atualiza pipeline_learnings.
 * Calcula win_rate por (categoria x pipeline x vibe x hook_type).
 *
 * Schedule: daily 04:00 BRT
 */
class AnalyzeFeedbackJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries   = 2;

    public function handle(): void
    {
        Log::info("[SEL-361 AnalyzeFeedbackJob] inicio");

        try {
            // Buscar grupos de feedback
            $groups = DB::table("video_feedback")
                ->select([
                    "pipeline", "category", "vibe", "hook_type",
                    DB::raw("SUM(CASE WHEN rating = \"great\" THEN 1 ELSE 0 END) as wins"),
                    DB::raw("SUM(CASE WHEN rating = \"bad\"   THEN 1 ELSE 0 END) as losses"),
                    DB::raw("SUM(CASE WHEN rating = \"ok\"    THEN 1 ELSE 0 END) as neutrals"),
                    DB::raw("COUNT(*) as total"),
                ])
                ->groupBy("pipeline", "category", "vibe", "hook_type")
                ->having("total", ">=", 1)
                ->get();

            $updated = 0;
            foreach ($groups as $g) {
                $total   = $g->wins + $g->losses + $g->neutrals;
                $winRate = $total > 0 ? round($g->wins / $total, 4) : 0;

                DB::table("pipeline_learnings")->updateOrInsert(
                    [
                        "category"  => $g->category ?? "geral",
                        "pipeline"  => $g->pipeline ?? "unknown",
                        "vibe"      => $g->vibe,
                        "hook_type" => $g->hook_type,
                    ],
                    [
                        "wins"             => $g->wins,
                        "losses"           => $g->losses,
                        "neutrals"         => $g->neutrals,
                        "win_rate"         => $winRate,
                        "is_baseline"      => false,
                        "last_analyzed_at" => now(),
                        "updated_at"       => now(),
                        "created_at"       => now(),
                    ]
                );
                $updated++;
            }

            Log::info("[SEL-361 AnalyzeFeedbackJob] concluido", [
                "grupos_atualizados" => $updated,
                "feedbacks_processados" => $groups->sum("total"),
            ]);

        } catch (Throwable $e) {
            Log::error("[SEL-361 AnalyzeFeedbackJob] erro", ["err" => $e->getMessage()]);
        }
    }
}
