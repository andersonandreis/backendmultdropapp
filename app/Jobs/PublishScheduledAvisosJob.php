<?php

namespace App\Jobs;

use App\Models\Aviso;
use App\Models\Client;
use App\Models\PushSubscription;
use App\Services\WebPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * SEL-264 — dispara push automático dos avisos que atingiram published_at.
 * Cron a cada 1min. Respeita quiet_hours + anti-spam 30min entre avisos.
 */
class PublishScheduledAvisosJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;

    public function handle(WebPushService $push): void
    {
        $avisos = Aviso::pendingPush()->get();
        if ($avisos->isEmpty()) return;

        foreach ($avisos as $aviso) {
            $sent = 0;
            $failed = 0;

            // Todos os clientes com push_subscriptions ativas
            $subs = PushSubscription::query()
                ->when(true, function ($q) use ($aviso) {
                    // Filtro por plano se especificado
                    if ($aviso->requires_plan) {
                        $q->whereHas('client.user', function ($u) use ($aviso) {
                            $u->where('plan', $aviso->requires_plan);
                        });
                    }
                })
                ->get();

            foreach ($subs as $sub) {
                // Anti-spam: máx 1 aviso por cliente a cada 30min
                $lockKey = "aviso_push:client:{$sub->client_id}";
                if (Cache::has($lockKey)) { continue; }

                // Quiet hours (via push_preferences se existir)
                $prefs = $sub->client
                    ? \DB::table('push_preferences')->where('client_id', $sub->client_id)->first()
                    : null;
                if ($prefs) {
                    $tz = 'America/Sao_Paulo';
                    $nowH = (int) now($tz)->format('H');
                    $start = $prefs->quiet_hours_start ? (int) substr($prefs->quiet_hours_start, 0, 2) : null;
                    $end   = $prefs->quiet_hours_end   ? (int) substr($prefs->quiet_hours_end, 0, 2) : null;
                    if ($start !== null && $end !== null) {
                        $isQuiet = $start <= $end
                            ? ($nowH >= $start && $nowH < $end)
                            : ($nowH >= $start || $nowH < $end);
                        if ($isQuiet) { continue; }
                    }
                }

                try {
                    $ok = $push->send($sub, [
                        'title' => $aviso->titulo,
                        'body'  => $aviso->body_push,
                        'icon'  => $aviso->cover_url ?: '/pwa-192.png',
                        'data'  => [
                            'aviso_id' => $aviso->id,
                            'url'      => '/avisos/' . $aviso->id,
                            'cta_url'  => $aviso->cta_url,
                        ],
                    ]);
                    if ($ok) {
                        $sent++;
                        Cache::put($lockKey, 1, now()->addMinutes(30));
                    } else {
                        $failed++;
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    Log::warning('PublishScheduledAvisosJob push falhou', [
                        'aviso' => $aviso->id, 'sub' => $sub->id, 'err' => $e->getMessage(),
                    ]);
                }
            }

            $aviso->update(['push_sent_at' => now()]);
            Log::info('PublishScheduledAvisosJob concluído', [
                'aviso'  => $aviso->id,
                'sent'   => $sent,
                'failed' => $failed,
            ]);
        }
    }
}
