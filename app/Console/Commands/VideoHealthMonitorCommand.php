<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Carbon\Carbon;

/**
 * SEL (07/08, Ruan "pra nunca mais acontecer") — Monitor de saude da geracao de video.
 * Roda a cada 5min. Detecta: jobs travados, falhas em serie, Flow deslogado (loginwall).
 * Se achar problema, DISPARA PUSH pro telefone do Ruan (alert:ruan). Cooldown de 30min
 * pra nao spammar. Assim ele sabe na HORA, nunca mais descobre por acaso.
 */
class VideoHealthMonitorCommand extends Command
{
    protected $signature = "video:health-monitor";
    protected $description = "Monitora a geracao de video e alerta o Ruan se travar";

    public function handle(): int
    {
        $problems = [];

        // 1) jobs travados ha +15min em passo intermediario
        $stuck = DB::table("ai_video_pipelines")
            ->whereIn("step", ["queued", "render", "lipsync", "voice"])
            ->where("user_id","!=",2957)->where("updated_at", "<", now()->subMinutes(15))
            ->count();
        if ($stuck >= 1) $problems[] = "$stuck video(s) travado(s) ha +15min";

        // 2) falhas em serie (ultimos 8 em 30min, >=3 abortos/falhas)
        $recent = DB::table("ai_video_pipelines")
            ->where("updated_at", ">=", now()->subMinutes(30))
            ->where("user_id","!=",2957)->orderByDesc("id")->limit(8)->get(["step", "error_message"]);
        $fails = $recent->filter(function ($r) {
            $e = strtolower($r->error_message ?? "");
            return $r->step === "failed" || str_contains($e, "abort") || str_contains($e, "not_attached");
        })->count();
        if ($recent->count() >= 3 && $fails >= 3) $problems[] = "$fails de " . $recent->count() . " geracoes falharam em 30min";

        // 3) Flow deslogado (loginwall) no log recente
        $loginwall = false;
        foreach (["/tmp/veo-worker-logs"] as $dir) {
            if (!is_dir($dir)) continue;
            foreach (glob($dir . "/*.log") as $f) {
                if (filemtime($f) < time() - 900) continue;
                $c = @file_get_contents($f);
                if ($c && (str_contains($c, "loginwall") || str_contains($c, "03_login"))) { $loginwall = true; break 2; }
            }
        }
        if ($loginwall) $problems[] = "Flow deslogou (loginwall) - precisa relogar o Google";

        // SEL (09/08, Ruan "essas notificacoes sao pra VOCE resolver, nao me pingar"):
        // travado/falhas AGORA se auto-resolvem (crons sentinela-unico/wedge-heal/
        // video-events/reconcile-stuck). So o LOGIN-WALL precisa do Ruan (so ele
        // reloga o Google). Travado/falhas -> LOG (o agente ve), SEM push pro Ruan.
        if (!empty($problems)) {
            @file_put_contents("/home/api.seller.global/logs/video-health.log",
                "[" . now()->toDateTimeString() . "] " . implode(" | ", $problems) . "\n", FILE_APPEND);
        }
        if (!$loginwall) { $this->info("problemas logados (auto-resolve cuida); sem push ao Ruan"); return 0; }
        $problems = ["Flow deslogou (loginwall) - precisa relogar o Google no PC"];

        // cooldown 30min
        $key = "video_health_last_alert";
        $last = DB::table("settings")->where("key", $key)->value("value");
        if ($last && Carbon::parse($last)->gt(now()->subMinutes(30))) { $this->info("em cooldown, nao re-alerta"); return 0; }

        Artisan::call("alert:ruan", [
            "title" => "Geracao de video com problema",
            "body"  => implode(" | ", $problems),
            "url"   => "/admin",
            "--email" => "ruanipanema2@gmail.com",
        ]);
        DB::table("settings")->updateOrInsert(
            ["key" => $key],
            ["value" => now()->toDateTimeString(), "group" => "video", "updated_at" => now(), "created_at" => now()]
        );
        $this->warn("ALERTADO Ruan: " . implode(" | ", $problems));
        return 0;
    }
}
