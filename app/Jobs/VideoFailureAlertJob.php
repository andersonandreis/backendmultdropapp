<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SEL-406 / SEL-NERVO: alarme de vídeo — falha em série E ausência.
 *
 * SEL-406 (29/07) nasceu porque em 28-29/07 o Studio gerou 143 vídeos, 111
 * falharam (78%) e ninguém viu. Só que do jeito que ele ficou, quase nunca
 * armava. Medido em 12/08:
 *
 *   - exigia 6 tentativas na MESMA hora; o volume real é ~3,6 vídeos/hora e de
 *     madrugada é zero. Um apagão às 2h levava ~2h só pra o alarme poder armar.
 *   - contava `canceled` no denominador. Na hora das 19h de 12/08 houve 19
 *     pipelines, ZERO entregues, 7 falhas e 12 cancelamentos -> 7/19 = 37%, sob
 *     o limite de 50%. Uma hora inteira de entrega ZERO não gerou alerta.
 *   - só olhava FALHA. Se parava de chegar pedido (frontend quebrado, fila
 *     travada, nenhum motor disponível), total = 0 -> return. Silêncio absoluto.
 *   - último disparo real: 11/08 14:02. Depois disso, 40 falhas em 24h, nenhum
 *     aviso.
 *
 * O que mudou:
 *   1. cancelamento NOSSO sai do denominador — não é falha do sistema;
 *   2. mínimo de tentativas 6 -> 3, que é o que funciona no volume real;
 *   3. entrou ALARME DE AUSÊNCIA (o que ninguém vigiava e é o que mata calado):
 *      - nada entregue em N horas com fila/demanda existindo;
 *      - nenhum pedido chegando em N horas dentro do horário comercial;
 *   4. anti-spam por tipo, com recuo exponencial (1h -> 2h -> 4h -> 8h) e teto
 *      global por hora. Alarme que grita toda hora é tão inútil quanto nenhum.
 *   5. o envio agora é CONFERIDO: se o Telegram não confirmar `ok`, isso vira
 *      erro no log. "Marcou enviado" não é o mesmo que "chegou".
 *
 * Roda INLINE no schedule (Schedule::call), não pela fila. De propósito: se a
 * fila morrer, o alarme que avisa que a fila morreu não pode morrer junto.
 */
class VideoFailureAlertJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    /** Passos que ainda estão em andamento (cliente esperando). */
    private const EM_ANDAMENTO = ['queued', 'queued_wait', 'render', 'processing'];

    public function handle(): void
    {
        try {
            $gritouFalha = $this->falhaEmSerie();
            $this->semEntrega($gritouFalha);
            $this->semPedido();
        } catch (\Throwable $e) {
            Log::error('[SEL-NERVO] alarme de video quebrou', [
                'erro' => $e->getMessage(), 'linha' => $e->getLine(),
            ]);
        }
    }

    // ── 1. FALHA EM SÉRIE ────────────────────────────────────────────────────
    // "está tentando e quebrando"
    private function falhaEmSerie(): bool
    {
        $minimo  = (int) (env('SEL_ALARME_MIN_TENTATIVAS', 3));
        $limite  = (float) (env('SEL_ALARME_TAXA', 0.5));
        $janela  = (int) (env('SEL_ALARME_JANELA_MIN', 60));
        $desde   = now()->subMinutes($janela);

        $l = DB::table('ai_video_pipelines')
            ->where('created_at', '>=', $desde)
            ->selectRaw("COUNT(*) as total,
                         SUM(step = 'canceled') as cancelados,
                         SUM(step = 'failed')   as falhas,
                         SUM(step = 'done')     as prontos")
            ->first();

        $total      = (int) ($l->total ?? 0);
        $cancelados = (int) ($l->cancelados ?? 0);
        $falhas     = (int) ($l->falhas ?? 0);
        $prontos    = (int) ($l->prontos ?? 0);

        // cancelamento nosso não é falha do sistema — fora do denominador
        $tentativas = max(0, $total - $cancelados);

        if ($tentativas < $minimo) { $this->acalma('falha'); return false; }

        $taxa = $falhas / $tentativas;
        if ($taxa < $limite) { $this->acalma('falha'); return false; }

        $motivo = DB::table('ai_video_pipelines')
            ->where('created_at', '>=', $desde)
            ->where('step', 'failed')
            ->whereNotNull('error_message')
            ->selectRaw('LEFT(error_message, 70) as m, COUNT(*) as q')
            ->groupBy('m')->orderByDesc('q')->first();

        $pct = (int) round($taxa * 100);

        Log::error('[SEL-NERVO] taxa de falha de video alta', [
            'tentativas' => $tentativas, 'falhas' => $falhas,
            'prontos' => $prontos, 'cancelados' => $cancelados, 'pct' => $pct,
        ]);

        return $this->alarme('falha',
            "🚨 *Vídeo falhando em série — seller.global*\n\n"
            . "Última hora: *{$falhas} de {$tentativas}* tentativas falharam (*{$pct}%*).\n"
            . "Entregues: *{$prontos}*." . ($cancelados > 0 ? " (+{$cancelados} cancelados, fora da conta)" : '') . "\n"
            . ($motivo ? "Erro dominante: `" . str_replace('`', '', $motivo->m) . "` ({$motivo->q}x)\n" : '')
            . "\nCada tentativa queima crédito. Conferir `ai_video_pipelines` step=failed."
        );
    }

    // ── 2. AUSÊNCIA DE ENTREGA ───────────────────────────────────────────────
    // "tem gente esperando e não sai nada" — o silêncio que ninguém vigiava
    private function semEntrega(bool $jaGritou): void
    {
        $horas = (int) (env('SEL_ALARME_SEM_ENTREGA_H', 2));
        $desde = now()->subHours($horas);

        $entregues = DB::table('ai_video_pipelines')
            ->where('step', 'done')->where('updated_at', '>=', $desde)->count();

        if ($entregues > 0) { $this->acalma('sem_entrega'); return; }

        // sem demanda não existe ausência de entrega — é só madrugada quieta
        $pedidos = DB::table('ai_video_pipelines')
            ->where('created_at', '>=', $desde)->where('step', '<>', 'canceled')->count();

        $parados = DB::table('ai_video_pipelines')
            ->whereIn('step', self::EM_ANDAMENTO)
            ->where(fn ($q) => $q->whereNull('output_url')->orWhere('output_url', ''))
            ->count();

        if ($pedidos === 0 && $parados === 0) { $this->acalma('sem_entrega'); return; }

        // a falha em série já explicou o mesmo buraco nesta rodada
        if ($jaGritou) { return; }

        $maisVelho = DB::table('ai_video_pipelines')
            ->whereIn('step', self::EM_ANDAMENTO)
            ->where(fn ($q) => $q->whereNull('output_url')->orWhere('output_url', ''))
            ->min('created_at');

        // abs(): no Carbon 3 o diff e SINALIZADO (now()->diffInMinutes(passado) da
        // negativo). Sem isto o alarme diria "esperando -37 min".
        $espera = $maisVelho
            ? (int) round(abs(now()->diffInMinutes(\Illuminate\Support\Carbon::parse($maisVelho))))
            : 0;

        Log::error('[SEL-NERVO] nenhum video entregue', [
            'horas' => $horas, 'pedidos' => $pedidos, 'parados' => $parados,
        ]);

        $this->alarme('sem_entrega',
            "🕳️ *Nada saindo — seller.global*\n\n"
            . "*ZERO vídeo entregue nas últimas {$horas}h.*\n"
            . "Pedidos no período: *{$pedidos}*. Na fila agora: *{$parados}*.\n"
            . ($espera > 0 ? "Cliente mais antigo esperando: *{$espera} min*.\n" : '')
            . "\nNão é falha em série — é a esteira parada. Ver worker, fila e motor."
        );
    }

    // ── 3. AUSÊNCIA DE PEDIDO ────────────────────────────────────────────────
    // "parou de chegar trabalho" = frontend/login/checkout quebrado
    private function semPedido(): void
    {
        $horas  = (int) (env('SEL_ALARME_SEM_PEDIDO_H', 3));
        $abre   = (int) (env('SEL_ALARME_COMERCIAL_INICIO', 9));
        $fecha  = (int) (env('SEL_ALARME_COMERCIAL_FIM', 21));

        // só avalia quando a janela inteira cabe dentro do horário comercial,
        // senão a madrugada (silêncio normal) vira alarme falso todo dia.
        $h = (int) now()->format('G');
        if ($h < $abre + $horas || $h >= $fecha) { return; }

        $pedidos = DB::table('ai_video_pipelines')
            ->where('created_at', '>=', now()->subHours($horas))->count();

        if ($pedidos > 0) { $this->acalma('sem_pedido'); return; }

        Log::error('[SEL-NERVO] nenhum pedido de video chegou', ['horas' => $horas]);

        $this->alarme('sem_pedido',
            "📉 *Parou de chegar pedido — seller.global*\n\n"
            . "*Nenhum vídeo pedido nas últimas {$horas}h*, em horário comercial ({$abre}h-{$fecha}h).\n"
            . "\nIsso normalmente é o cliente NÃO CONSEGUINDO pedir: front quebrado,\n"
            . "login caído ou botão de gerar morto. Abrir o Studio e tentar gerar."
        );
    }

    // ── anti-spam + envio conferido ──────────────────────────────────────────

    /** Zera o recuo quando a condição volta ao normal. */
    private function acalma(string $tipo): void
    {
        Cache::forget("sel_alarme:rep:{$tipo}");
    }

    /**
     * Manda no Telegram com trava por tipo e recuo exponencial.
     *
     * 1º aviso sai na hora. Se a condição PERSISTIR, a janela dobra a cada
     * repetição (1h -> 2h -> 4h -> 8h) para não virar ruído que o dono aprende
     * a ignorar. Some um teto global por hora, que é a rede de segurança contra
     * tempestade de alarme.
     */
    private function alarme(string $tipo, string $texto): bool
    {
        $base = (int) (env('SEL_ALARME_JANELA_ANTISPAM_MIN', 60));
        $teto = (int) (env('SEL_ALARME_TETO_HORA', 4));

        if (Cache::has("sel_alarme:trava:{$tipo}")) {
            return false;
        }

        $chaveTeto = 'sel_alarme:teto:' . now()->format('YmdH');
        $enviados  = (int) Cache::get($chaveTeto, 0);
        if ($enviados >= $teto) {
            // Log::error de proposito: com LOG_LEVEL=error no .env, warning nao
            // chega no disco -- foi exatamente o defeito que este ticket veio
            // consertar. Alarme engolido pelo teto tem que deixar rastro.
            Log::error('[SEL-NERVO] teto de alarme por hora atingido, segurando', [
                'tipo' => $tipo, 'teto' => $teto,
            ]);
            return false;
        }

        $rep    = (int) Cache::get("sel_alarme:rep:{$tipo}", 0);
        $janela = $base * (2 ** min($rep, 3));   // 1h, 2h, 4h, 8h

        Cache::put("sel_alarme:trava:{$tipo}", 1, $janela * 60);
        Cache::put("sel_alarme:rep:{$tipo}", $rep + 1, 86400);
        Cache::put($chaveTeto, $enviados + 1, 3700);

        if ($rep > 0) {
            $texto .= "\n\n_(repetição {$rep}; próximo aviso deste tipo só daqui a {$janela}min)_";
        }

        return $this->telegram($tipo, $texto);
    }

    /** Envia e CONFERE. "Enviado" sem confirmação do outro lado não vale nada. */
    private function telegram(string $tipo, string $texto): bool
    {
        $token  = env('TELEGRAM_BOT_TOKEN_CHAT') ?: env('TELEGRAM_BOT_TOKEN');
        $chatId = env('TELEGRAM_CHAT_ID_RUAN') ?: env('TELEGRAM_CHAT_ID');

        if (! $token || ! $chatId) {
            Log::error('[SEL-NERVO] alarme SEM CANAL: TELEGRAM_* ausente no .env', ['tipo' => $tipo]);
            return false;
        }

        try {
            $r = Http::timeout(15)->asForm()
                ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $chatId, 'text' => $texto, 'parse_mode' => 'Markdown',
                ]);

            if ($r->successful() && $r->json('ok') === true) {
                Log::error('[SEL-NERVO] alarme ENTREGUE no Telegram', [
                    'tipo' => $tipo, 'message_id' => $r->json('result.message_id'),
                ]);
                return true;
            }

            Log::error('[SEL-NERVO] Telegram RECUSOU o alarme', [
                'tipo' => $tipo, 'http' => $r->status(),
                'corpo' => mb_substr((string) $r->body(), 0, 200),
            ]);
        } catch (\Throwable $e) {
            Log::error('[SEL-NERVO] alarme NAO SAIU do servidor', [
                'tipo' => $tipo, 'erro' => $e->getMessage(),
            ]);
        }

        return false;
    }
}
