<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PR5 — INF-audit: notifica o lojista via push web quando um token de
 * marketplace (Shopee ou ML) e marcado como quebrado (is_token_broken=1).
 *
 * Dispachado por ShopeeService::refreshToken() e MercadoLivreService::refreshToken()
 * na primeira deteccao de erro permanente.
 *
 * A notificacao e enviada pra todos os devices do client.user que tem a conta.
 * Se nao houver push_subscriptions cadastrados, apenas loga (sem erro).
 *
 * Reset: quando o lojista reconecta via OAuth, OAuthController::callback()
 * zera is_token_broken=0 e o ciclo normal retoma.
 */
class NotifyTokenBrokenJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 30;

    public function __construct(
        public readonly int $accountId,
        public readonly string $platform,
    ) {}

    public function handle(): void
    {
        if (!config('services.vapid.private')) {
            Log::info('[NotifyTokenBrokenJob] VAPID nao configurado -- push ignorado', [
                'account_id' => $this->accountId,
                'platform'   => $this->platform,
            ]);
            return;
        }

        $account = DB::table('marketplace_accounts as ma')
            ->leftJoin('clients as c', 'c.id', '=', 'ma.client_id')
            ->where('ma.id', $this->accountId)
            ->select('ma.id', 'ma.platform', 'ma.account_name', 'ma.token_broken_reason', 'c.user_id')
            ->first();

        if (!$account || !$account->user_id) {
            Log::warning('[NotifyTokenBrokenJob] Conta ou user_id nao encontrado', [
                'account_id' => $this->accountId,
            ]);
            return;
        }

        $devices = DB::table('push_subscriptions')
            ->where('user_id', $account->user_id)
            ->get();

        if ($devices->isEmpty()) {
            Log::info('[NotifyTokenBrokenJob] Nenhum device registrado para user_id=' . $account->user_id);
            return;
        }

        $platformLabel = match($this->platform) {
            'shopee'        => 'Shopee',
            'mercadolivre'  => 'Mercado Livre',
            default         => ucfirst($this->platform),
        };

        $accountLabel = $account->account_name ?? 'Sua conta';
        $payload = json_encode([
            'title' => 'Reconexao necessaria — ' . $platformLabel,
            'body'  => $accountLabel . ' precisa ser reconectada.\nAcesse Integracoes para reautorizar.',
            'url'   => '/integrations',
            'type'  => 'token_broken',
            'tag'   => 'token-broken-' . $this->accountId,
            'icon'  => '/images/icon-192x192.png',
        ], JSON_UNESCAPED_UNICODE);

        try {
            $wp = new \Minishlink\WebPush\WebPush(['VAPID' => [
                'subject'    => config('services.vapid.subject'),
                'publicKey'  => config('services.vapid.public'),
                'privateKey' => config('services.vapid.private'),
            ]]);

            foreach ($devices as $d) {
                $wp->queueNotification(
                    \Minishlink\WebPush\Subscription::create([
                        'endpoint' => $d->endpoint,
                        'keys'     => ['p256dh' => $d->p256dh, 'auth' => $d->auth],
                    ]),
                    $payload
                );
            }

            $successCount = 0;
            foreach ($wp->flush() as $report) {
                if ($report->isSuccess()) {
                    $successCount++;
                } else {
                    $code = $report->getResponse()?->getStatusCode();
                    if (in_array($code, [404, 410], true)) {
                        DB::table('push_subscriptions')
                            ->where('endpoint', (string) $report->getRequest()->getUri())
                            ->delete();
                    }
                }
            }

            Log::info('[NotifyTokenBrokenJob] Push enviado', [
                'account_id'    => $this->accountId,
                'platform'      => $this->platform,
                'devices_total' => $devices->count(),
                'success'       => $successCount,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[NotifyTokenBrokenJob] Erro ao enviar push: ' . $e->getMessage(), [
                'account_id' => $this->accountId,
            ]);
        }
    }
}
