<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SEL-TRAVA-SOLTA (14/08) — solta sozinho a trava de geração de vídeo.
 *
 * O QUE ACONTECIA: cada geração RESERVA uma vaga antes de enfileirar. A vaga só
 * era devolvida no caminho feliz. Quando o vídeo terminava por outro caminho
 * (falhou, reprovado pelo conferente, worker morreu no meio), a reserva ficava
 * aberta PARA SEMPRE — e no plano de 1 vídeo por dia isso trava o cliente por
 * 24h com o vídeo já pronto na galeria.
 *
 * Medido hoje: 279 reservas penduradas de uma vez, e mais 11 durante a tarde.
 * Eu estava limpando na mão a cada reclamação — isso aqui é pra nunca mais.
 */
class LiberaReservasCommand extends Command
{
    protected $signature = 'video:libera-reservas {--minutos=30 : idade da reserva órfã (sem pipeline) que já pode cair}';
    protected $description = 'Devolve a vaga de geração quando o vídeo já terminou (ou nunca chegou a existir)';

    public function handle(): int
    {
        // 1) vídeo já acabou (de qualquer jeito) mas a vaga continuou presa
        $ids = DB::table('video_generation_reservations as r')
            ->join('ai_video_pipelines as p', 'p.id', '=', 'r.pipeline_id')
            ->where('r.status', 'reserved')
            ->whereIn('p.step', ['done', 'failed', 'error', 'cancelled', 'removido_vazamento'])
            ->pluck('r.id');

        $terminadas = $ids->count()
            ? DB::table('video_generation_reservations')->whereIn('id', $ids)
                ->update(['status' => 'refunded', 'updated_at' => now()])
            : 0;

        // 2) reserva que nunca virou pipeline (o pedido morreu antes de enfileirar).
        //    Só depois de uma folga generosa, pra não derrubar quem está começando agora.
        $orfas = DB::table('video_generation_reservations')
            ->where('status', 'reserved')
            ->whereNull('pipeline_id')
            ->where('created_at', '<', now()->subMinutes((int) $this->option('minutos')))
            ->update(['status' => 'refunded', 'updated_at' => now()]);

        $abertas = DB::table('video_generation_reservations')->where('status', 'reserved')->count();

        if ($terminadas || $orfas) {
            Log::info('[SEL-TRAVA-SOLTA] vagas devolvidas', [
                'video_ja_terminou' => $terminadas,
                'sem_pipeline'      => $orfas,
                'ainda_abertas'     => $abertas,
            ]);
        }

        $this->info("devolvidas: {$terminadas} (vídeo terminou) + {$orfas} (sem pipeline) · ainda abertas: {$abertas}");

        return self::SUCCESS;
    }
}
