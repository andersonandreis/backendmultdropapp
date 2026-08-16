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
 * QueueHealthCheckJob — vigia fila parada, a cada 15min.
 *
 * SEL-404: a versao anterior so olhava a fila `default` e so gritava acima de
 * 50 mil jobs — e usava TELEGRAM_BOT_TOKEN/TELEGRAM_CHAT_ID, que nao existem
 * neste .env. Ou seja: nunca alertou nada, e ficou silenciada desde 29/06.
 *
 * Enquanto isso o seller.global passou mais de um dia sem worker nenhum em
 * `inventory`, `reconciliation` e `high-priority`: 4.419 jobs parados, anuncio
 * de cliente que nunca foi publicado no Mercado Livre. Volume nao denuncia isso
 * — IDADE denuncia. Agora o alerta e por fila que parou de andar.
 */
class QueueHealthCheckJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    /** Minutos que um job pode esperar antes de virar sinal de fila parada. */
    private const LIMITE_MINUTOS = 30;

    public function handle(): void
    {
        $limite = now()->subMinutes(self::LIMITE_MINUTOS)->timestamp;

        $paradas = DB::table('jobs')
            ->select('queue', DB::raw('COUNT(*) as total'), DB::raw('MIN(available_at) as mais_antigo'))
            ->whereNull('reserved_at')
            ->where('available_at', '<=', now()->timestamp)
            ->groupBy('queue')
            ->havingRaw('MIN(available_at) <= ?', [$limite])
            ->get();

        if ($paradas->isEmpty()) {
            return;
        }

        foreach ($paradas as $fila) {
            $chave = 'queue_health_alert:' . $fila->queue;
            if (Cache::has($chave)) {
                continue;
            }
            Cache::put($chave, true, 3600);

            $minutos = (int) round((now()->timestamp - $fila->mais_antigo) / 60);
            $texto = "\u{26A0}\u{FE0F} *Fila parada — seller.global*\n\n"
                   . "Fila: `{$fila->queue}`\n"
                   . "Jobs esperando: *{$fila->total}*\n"
                   . "O mais antigo espera ha *{$minutos} min*\n\n"
                   . "Provavel worker fora do ar. Conferir:\n"
                   . "`supervisorctl status | grep sellerapp`";

            Log::warning('[QueueHealthCheck] Fila parada', [
                'queue'         => $fila->queue,
                'total'         => $fila->total,
                'espera_minutos' => $minutos,
            ]);

            $token  = env('TELEGRAM_BOT_TOKEN_CHAT') ?: env('TELEGRAM_BOT_TOKEN');
            $chatId = env('TELEGRAM_CHAT_ID_RUAN') ?: env('TELEGRAM_CHAT_ID');
            if (! $token || ! $chatId) {
                continue;
            }

            try {
                Http::timeout(10)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id'    => $chatId,
                    'text'       => $texto,
                    'parse_mode' => 'Markdown',
                ]);
            } catch (\Throwable $e) {
                Log::warning('[QueueHealthCheck] falha ao avisar no Telegram', ['erro' => $e->getMessage()]);
            }
        }
    }
}
