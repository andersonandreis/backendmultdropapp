<?php

namespace App\Console\Commands;

use App\Services\Ai\KlingBrowserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SEL-GEN-ORFA (14/08) — costura o vídeo pronto de volta na galeria do cliente.
 *
 * ACHADO ao testar o botão "Gerar outro, com as mesmas escolhas": o caminho
 * `/api/v1/ai/video/generate` cria a linha em `ai_generations` como `processing`
 * e joga o trabalho no worker de navegador. O worker TERMINA, grava o resultado
 * no estado da tarefa (`kling_browser:<task>` no cache) — e ninguém escreve isso
 * de volta na linha. Resultado medido: gen#608 ficou `processing` com
 * `output_url` NULL enquanto o mp4 existia e abria (2.732.102 bytes).
 *
 * Ou seja: a fábrica entregava e o cliente não via. Sem erro, sem alarme —
 * exatamente a falha silenciosa que mais custa caro aqui.
 *
 * Este comando fecha o buraco de fora: varre as gerações penduradas, lê o estado
 * da tarefa e conclui (succeeded com a URL, ou failed com o motivo). Roda a cada
 * minuto; é idempotente e só toca em quem tem `provider_task_id` de navegador.
 */
class ReconcileBrowserGenerations extends Command
{
    protected $signature = 'video:reconcile-generations {--minutos=2 : idade mínima da geração pendurada}';
    protected $description = 'Fecha ai_generations que ficaram em processing embora o worker de navegador já tenha terminado';

    public function handle(): int
    {
        $idade = (int) $this->option('minutos');

        $pendentes = DB::table('ai_generations')
            ->where('status', 'processing')
            ->whereNotNull('provider_task_id')
            ->where('provider_task_id', 'like', 'kbrowser_%')
            ->where('created_at', '<', now()->subMinutes($idade))
            ->get(['id', 'user_id', 'provider_task_id', 'created_at']);

        if ($pendentes->isEmpty()) {
            $this->info('nada pendurado');
            return self::SUCCESS;
        }

        $cache = KlingBrowserService::cache();
        $fechadas = 0;
        $falhadas = 0;

        foreach ($pendentes as $g) {
            $estado = $cache->get("kling_browser:{$g->provider_task_id}", []);
            $status = is_array($estado) ? ($estado['task_status'] ?? null) : null;

            if ($status === 'succeed' && ! empty($estado['output_url'])) {
                DB::table('ai_generations')->where('id', $g->id)->update([
                    'status'     => 'succeeded',
                    'output_url' => $estado['output_url'],
                    'updated_at' => now(),
                ]);
                $fechadas++;
                $this->line("  gen#{$g->id} (user {$g->user_id}) -> succeeded");
                Log::info('[SEL-GEN-ORFA] geracao costurada de volta', [
                    'gen' => $g->id, 'task' => $g->provider_task_id, 'url' => $estado['output_url'],
                ]);
                continue;
            }

            if ($status === 'failed') {
                DB::table('ai_generations')->where('id', $g->id)->update([
                    'status'     => 'failed',
                    'updated_at' => now(),
                ]);
                $falhadas++;
                $this->line("  gen#{$g->id} (user {$g->user_id}) -> failed");
                continue;
            }

            // sem estado no cache (expira em 2h) e velha demais: o cliente está
            // olhando pra uma bolinha que nunca vai parar. Marca como falha pra
            // ele poder tentar de novo em vez de esperar pra sempre.
            if ($status === null && $g->created_at < now()->subHours(2)) {
                DB::table('ai_generations')->where('id', $g->id)->update([
                    'status'     => 'failed',
                    'updated_at' => now(),
                ]);
                $falhadas++;
                $this->line("  gen#{$g->id} (user {$g->user_id}) -> failed (estado sumiu do cache)");
            }
        }

        $this->info("costuradas: {$fechadas} · marcadas como falha: {$falhadas} · olhadas: " . $pendentes->count());

        return self::SUCCESS;
    }
}
