<?php

namespace App\Jobs;

use App\Models\MarketplaceAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * FOR-081 -- Job de email transacional para contas com token expirado.
 *
 * NAO DISPARAR SEM OK EXPLICIO DO RUAN.
 * Dedup: tabela integration_expiry_notifications (client_id, platform, notified_at).
 * Se tabela nao existir: usa last_expiry_notification_at em marketplace_accounts (nao criado ainda).
 * Fallback dedup: limitar a 1 email por client_id por plataforma por dia via cache.
 *
 * Dispatcher manual (artisan):
 *   sudo -u apifrn0001 php artisan tinker
 *   >>> App\Jobs\NotifyExpiredIntegrationsJob::dispatch();
 *
 * REGRA: NAO adicionar no schedule sem OK do Ruan.
 */
class NotifyExpiredIntegrationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 300;

    public function handle(): void
    {
        Log::info('[NotifyExpiredIntegrations] FOR-081 iniciando varredura de contas expiradas');

        $now = now();

        // Buscar contas expiradas com relacao a cliente e usuario para email
        $expiredAccounts = MarketplaceAccount::with(['client.user'])
            ->where(function ($q) use ($now) {
                $q->where(function ($sq) use ($now) {
                    $sq->whereNotNull('token_expires_at')
                       ->where('token_expires_at', '<', $now);
                })->orWhere(function ($sq) use ($now) {
                    $sq->whereNotNull('ml_token_expires_at')
                       ->where('ml_token_expires_at', '<', $now);
                })->orWhere(function ($sq) use ($now) {
                    $sq->whereNotNull('refresh_token_expires_at')
                       ->where('refresh_token_expires_at', '<', $now);
                })->orWhereIn('status', ['needs_reauth', 'expired', 'disconnected'])
                  ->orWhere('needs_reauth', 1);
            })
            ->whereIn('platform', ['mercadolivre', 'shopee'])
            ->get();

        Log::info('[NotifyExpiredIntegrations] contas expiradas encontradas', [
            'total' => $expiredAccounts->count(),
        ]);

        // Agrupar por client_id+platform para evitar multiplos emails para o mesmo cliente
        $grouped = $expiredAccounts->groupBy(function ($account) {
            return $account->client_id . ':' . $account->platform;
        });

        $emailsSent = 0;
        $emailsSkipped = 0;

        foreach ($grouped as $key => $accounts) {
            [$clientId, $platform] = explode(':', $key);
            $firstAccount = $accounts->first();
            $client = $firstAccount->client;
            $user = $client?->user;

            if (! $user?->email) {
                Log::warning('[NotifyExpiredIntegrations] cliente sem email', [
                    'client_id' => $clientId,
                    'platform'  => $platform,
                ]);
                continue;
            }

            // Dedup via cache: nao enviar mais de 1 vez por dia por client+platform
            $cacheKey = "expiry_notified:{$clientId}:{$platform}:" . now()->format('Y-m-d');
            if (cache()->has($cacheKey)) {
                $emailsSkipped++;
                Log::debug('[NotifyExpiredIntegrations] dedup -- email ja enviado hoje', [
                    'client_id' => $clientId,
                    'platform'  => $platform,
                ]);
                continue;
            }

            $platformName = $platform === 'mercadolivre' ? 'Mercado Livre' : 'Shopee';
            $frontendUrl  = config('app.frontend_url', 'https://fornecefy.io');
            $reAuthUrl    = $frontendUrl . '/integracoes?reauth=' . ($platform === 'mercadolivre' ? 'ml' : 'shopee');

            try {
                Mail::raw(
                    "Ola, {$user->name}!\n\n"
                    . "Sua integracao com {$platformName} expirou e seus produtos podem parar de sincronizar.\n\n"
                    . "Para reconectar, acesse:\n{$reAuthUrl}\n\n"
                    . "Se precisar de ajuda, entre em contato com nosso suporte.\n\n"
                    . "Equipe Fornecefy",
                    function ($message) use ($user, $platformName) {
                        $message->to($user->email, $user->name)
                                ->from(config('mail.from.address'), config('mail.from.name'))
                                ->subject("Sua integracao com {$platformName} expirou — Reconecte agora");
                    }
                );

                // Marcar dedup no cache por 23h (evita re-envio no mesmo dia)
                cache()->put($cacheKey, true, now()->addHours(23));
                $emailsSent++;

                Log::info('[NotifyExpiredIntegrations] email enviado', [
                    'client_id' => $clientId,
                    'platform'  => $platform,
                    'email'     => $user->email,
                ]);
            } catch (\Throwable $e) {
                Log::error('[NotifyExpiredIntegrations] falha ao enviar email', [
                    'client_id' => $clientId,
                    'platform'  => $platform,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        Log::info('[NotifyExpiredIntegrations] FOR-081 concluido', [
            'emails_sent'    => $emailsSent,
            'emails_skipped' => $emailsSkipped,
            'total_grupos'   => $grouped->count(),
        ]);
    }
}
