<?php

namespace App\Observers;

use App\Models\MissedOrderAlert;
use App\Notifications\MissedOrderDetectedNotification;
use Illuminate\Support\Facades\Log;

/**
 * Observa o ciclo de vida de MissedOrderAlert.
 *
 * No evento `created`, dispara notificação in-app (+ e-mail em planos Pro/Scale)
 * para o usuário dono do client, respeitando o cap de 3 notificações via canNotify().
 */
class MissedOrderAlertObserver
{
    /**
     * Handle the MissedOrderAlert "created" event.
     *
     * Guard de duplicação: canNotify() verifica notification_count < 3
     * E status !== dismissed/converted. Incremento atômico após dispatch
     * evita duplo-envio mesmo que o observer seja chamado por race condition.
     */
    public function created(MissedOrderAlert $alert): void
    {
        if (! $alert->canNotify()) {
            return;
        }

        // Client → User via belongsTo (client.user_id)
        $client = $alert->client;

        if (! $client) {
            Log::warning('MissedOrderAlertObserver: alert sem client', ['alert_id' => $alert->id]);
            return;
        }

        $user = $client->user;

        if (! $user) {
            Log::warning('MissedOrderAlertObserver: client sem user', [
                'alert_id'  => $alert->id,
                'client_id' => $alert->client_id,
            ]);
            return;
        }

        // Dispara notificação (queued via ShouldQueue)
        $user->notify(new MissedOrderDetectedNotification($alert));

        // Atualiza contadores no alert
        $alert->increment('notification_count');

        if ($alert->notified_at === null) {
            $alert->update(['notified_at' => now()]);
        }
    }
}
