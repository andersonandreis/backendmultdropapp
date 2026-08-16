<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SEL-490b — rede de seguranca do STATUS de geracao de video.
 *
 * Bug 07/08: pipeline ficou step='render' com output_url preenchido (o vídeo
 * existia mas a tela do cliente girava pra sempre), porque a finalizacao dependia
 * do job de POLL sobreviver. Se o worker recicla (max-time) ou cai no meio do
 * poll, a pipeline fica orfa em 'render'.
 *
 * Este comando roda a cada minuto (cron) e reconcilia:
 *   A) tem output_url mas nao esta done  -> marca DONE (o vídeo existe).
 *   B) sem output_url ha muito tempo      -> marca FAILED com msg clara + alerta.
 * O cliente nunca mais fica preso em "gerando...".
 */
class VideoReconcileStuck extends Command
{
    protected $signature = 'video:reconcile-stuck
                            {--done-after=90 : segundos com output_url antes de marcar done}
                            {--fail-after=1200 : segundos sem output_url antes de marcar failed}';

    protected $description = 'Reconcilia pipelines de vídeo presos (render com url -> done; travado sem url -> failed).';

    /** Steps que NÃO são finais. */
    private const NAO_FINAIS = ['queued', 'render', 'processing', 'voice', 'lipsync', 'queued_wait'];

    public function handle(): int
    {
        $doneAfter = (int) $this->option('done-after');
        $failAfter = (int) $this->option('fail-after');

        // A) tem output_url mas ficou preso -> DONE
        $done = DB::table('ai_video_pipelines')
            ->whereIn('step', self::NAO_FINAIS)
            ->whereNotNull('output_url')
            ->where('updated_at', '<', now()->subSeconds($doneAfter))
            ->update(['step' => 'done', 'updated_at' => now()]);
        if ($done > 0) {
            Log::info('[SEL-490b][reconcile] pipelines com output_url finalizadas como done', ['qtd' => $done]);
        }

        // B) travado sem output_url ha muito tempo -> FAILED (msg clara ao cliente)
        $presos = DB::table('ai_video_pipelines')
            ->whereIn('step', self::NAO_FINAIS)
            ->whereNull('output_url')
            ->where('updated_at', '<', now()->subSeconds($failAfter))
            ->get(['id', 'user_id', 'step', 'payloads', 'created_at']);
        $ignorados = 0;
        foreach ($presos as $p) {
            // SEL-RESILIENCIA (12/08) — ESTE COMANDO NAO DECIDE MAIS SOZINHO.
            //
            // Ele rodava a cada minuto e falhava qualquer pipeline parada ha 20min.
            // Numa hora de pico isso pega justamente quem esta na FILA esperando
            // motor (nao mexe na linha enquanto espera) — o cliente perdia o video
            // por estar na fila, que e o oposto de uma rede de seguranca.
            //
            // Quem reenfileira agora e um so: o render-hung-heal.sh (dono unico do
            // requeue, evita dois reapers despachando o mesmo pedido em duplicata).
            // Aqui a gente so encerra o que ja esgotou as tentativas OU o que passou
            // de 90min de vida — e com mensagem honesta, sem culpar o cliente.
            $pl   = json_decode((string) ($p->payloads ?? ''), true) ?: [];
            $tent = (int) ($pl['tentativas_infra'] ?? 0);
            $vida = now()->diffInMinutes(\Illuminate\Support\Carbon::parse($p->created_at));

            if ($tent < \App\Services\Ai\VideoResilience::MAX_TENTATIVAS_INFRA && $vida < 90) {
                $ignorados++;
                continue;
            }

            DB::table('ai_video_pipelines')->where('id', $p->id)->update([
                'step'          => 'failed',
                'error_message' => 'Tivemos um problema do nosso lado e nao conseguimos terminar seu video. '
                                 . 'Nao foi cobrado — toque em Gerar novamente.',
                'updated_at'    => now(),
            ]);
            // D: sinaliza pra investigar (o worker/pool deveria ter entregue ou trocado de conta).
            Log::warning('[SEL-490b][reconcile] pipeline travada sem vídeo -> failed', [
                'pipeline_id' => $p->id, 'user_id' => $p->user_id, 'step_anterior' => $p->step,
            ]);
        }

        // C) FANTASMA (Ruan 11/08): step=done mas SEM output_url (nunca renderizou)
        //    -> failed com msg clara. Rede DEFINITIVA: pega ate updates via DB::table
        //    que o Model observer (Eloquent) nao intercepta. Cliente nunca ve
        //    "concluido" sem video. dry_run tem output fake, nao e pego.
        $fantasmas = DB::table("ai_video_pipelines")
            ->where("step", "done")
            ->where(function ($q) { $q->whereNull("output_url")->orWhere("output_url", ""); })
            ->where("created_at", ">", now()->subDays(7))
            ->update([
                "step"          => "failed",
                "error_message" => "A geração não completou. Tente gerar de novo.",
                "updated_at"    => now(),
            ]);
        if ($fantasmas > 0) {
            Log::error("[SEL-490b][reconcile][FANTASMA] done sem video corrigido -> failed", ["qtd" => $fantasmas]);
        }

        $this->info("reconcile: done={$done} failed=" . (count($presos) - $ignorados)
            . " ainda_com_chance={$ignorados}");
        return self::SUCCESS;
    }
}
