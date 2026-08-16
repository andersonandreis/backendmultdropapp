<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * SEL-259 — Job de alerta de lives ativas por nicho.
 *
 * Roda a cada 5min via cron (Kernel.php: $schedule->job(...)->everyFiveMinutes()).
 * Lê tt_live_snapshots dos últimos 30min, cruza com push_preferences de clientes
 * com live_alerts_enabled=1 cujo nicho bate, e envia push.
 *
 * Anti-spam: máx 1 push por (client_id, live external_id) a cada 3h.
 *   Controle via cache (file driver ok, sem Redis nas WLs).
 *
 * Respeita quiet_hours_start/end (UTC — client_tz não temos; usar horário Brasília GMT-3).
 */
class AlertActiveLivesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 120;

    public function handle(): void
    {
        if (!config('services.vapid.public') || !config('services.vapid.private')) {
            Log::info('[AlertActiveLivesJob] VAPID não configurado — skip.');
            return;
        }

        // 1) Lives ativas nos últimos 30min
        $cutoff = now()->subMinutes(30);
        $lives  = DB::table('tt_live_snapshots')
            ->where('snapshot_at', '>=', $cutoff)
            ->orderByDesc('viewers_now')
            ->get();

        if ($lives->isEmpty()) {
            Log::info('[AlertActiveLivesJob] Nenhuma live ativa nos últimos 30min.');
            return;
        }

        // 2) Clientes com live_alerts_enabled
        $prefs = DB::table('push_preferences')
            ->where('live_alerts_enabled', 1)
            ->get();

        if ($prefs->isEmpty()) {
            return;
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject'    => config('services.vapid.subject'),
                'publicKey'  => config('services.vapid.public'),
                'privateKey' => config('services.vapid.private'),
            ],
        ]);

        $nowBrasilia = now()->setTimezone('America/Sao_Paulo');
        $dispatched  = 0;
        $skipped     = 0;

        foreach ($prefs as $pref) {
            // Verifica quiet_hours (fuso Brasília)
            if ($this->inQuietHours($pref, $nowBrasilia)) {
                $skipped++;
                continue;
            }

            $niches = json_decode($pref->niches ?? '[]', true) ?: [];

            // Acha primeira live que bate no nicho do cliente
            $match = null;
            foreach ($lives as $live) {
                if (empty($niches) || in_array($live->niche, $niches, true)) {
                    // Anti-spam: 1 push por (client, live) a cada 3h
                    $cacheKey = "live_alert_{$pref->client_id}_{$live->external_id}";
                    $lastSent = DB::table('push_subscriptions')
                        ->where('client_id', $pref->client_id)
                        ->value('last_used_at');

                    // Simples: usa cache via tabela auxiliar ou arquivo
                    $antiSpamKey = "live_alert:{$pref->client_id}:{$live->external_id}";
                    if (cache()->has($antiSpamKey)) {
                        continue; // já enviou nas últimas 3h
                    }

                    $match = $live;
                    break;
                }
            }

            if (!$match) {
                continue;
            }

            // Busca subscriptions do cliente
            $subs = DB::table('push_subscriptions')
                ->where('client_id', $pref->client_id)
                ->get();

            if ($subs->isEmpty()) {
                continue;
            }

            $viewers    = number_format($match->viewers_now, 0, ',', '.');
            $liveName   = $match->title ?: $match->handle ?: 'uma live';
            $payload    = json_encode([
                'title' => "Tem {$viewers} pessoas ao vivo em {$liveName} agora",
                'body'  => 'Boa oportunidade — produtos em alta com audiência real. Ver ao vivo agora.',
                'url'   => $match->tiktok_url ?? '/tiktok-shopping',
                'image' => $match->image_url,
            ], JSON_UNESCAPED_UNICODE);

            foreach ($subs as $sub) {
                $webPush->queueNotification(
                    Subscription::create([
                        'endpoint' => $sub->endpoint,
                        'keys'     => ['p256dh' => $sub->p256dh, 'auth' => $sub->auth],
                    ]),
                    $payload
                );
            }

            // Marca anti-spam por 3h
            cache()->put("live_alert:{$pref->client_id}:{$match->external_id}", 1, now()->addHours(3));
            $dispatched++;
        }

        // Flush
        $sent   = 0;
        $failed = 0;
        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                $sent++;
                continue;
            }
            $failed++;
            $status = optional($report->getResponse())->getStatusCode();
            if (in_array($status, [404, 410], true)) {
                DB::table('push_subscriptions')
                    ->where('endpoint_hash', hash('sha256', $report->getEndpoint()))
                    ->delete();
            }
        }

        Log::info('[AlertActiveLivesJob] concluido', [
            'lives_found' => $lives->count(),
            'clients'     => $prefs->count(),
            'dispatched'  => $dispatched,
            'skipped_qh'  => $skipped,
            'sent'        => $sent,
            'failed'      => $failed,
        ]);
    }

    /**
     * Verifica se agora é dentro do quiet_hours do cliente.
     * Horário em Brasília (GMT-3).
     */
    private function inQuietHours(object $pref, \Carbon\Carbon $now): bool
    {
        if (!$pref->quiet_hours_start || !$pref->quiet_hours_end) {
            return false;
        }

        $hhmm = $now->format('H:i');

        $start = $pref->quiet_hours_start; // HH:MM
        $end   = $pref->quiet_hours_end;

        if ($start <= $end) {
            // Ex: 22:00 - 08:00 NÃO entra aqui; 10:00 - 12:00 sim
            return $hhmm >= $start && $hhmm < $end;
        } else {
            // Span meia-noite: ex: quiet 22:00 até 08:00
            return $hhmm >= $start || $hhmm < $end;
        }
    }
}
