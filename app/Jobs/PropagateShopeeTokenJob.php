<?php

namespace App\Jobs;

use App\Models\MarketplaceAccount;
use App\Services\InstallationConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * NOV-181: apos o HUB renovar um token Shopee, propaga a cadeia nova pra
 * todas as WLs espelho via POST /api/oauth/shopee/relay-token-refresh
 * (receiver HMAC ja existente em todos os backends — atualiza por shop_id).
 *
 * WL que nao tem a loja responde updated=0 (inofensivo).
 */
class PropagateShopeeTokenJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public int $accountId)
    {
        // Propagacao de token e sensivel a latencia (WL espelha em < 1 min) —
        // a queue default acumula backlog; high-priority tem worker dedicado.
        $this->onQueue('high-priority');
    }

    public function handle(): void
    {
        $config = app(InstallationConfig::class);

        if (! $config->isHub()) {
            return;
        }

        $account = MarketplaceAccount::find($this->accountId);

        if (! $account || $account->platform !== 'shopee' || ! $account->shop_id) {
            return;
        }

        // SEL-357: nao propagar token de conta espelho (ela recebe via relay, nao gera)
        if (($account->mirror_mode ?? 'active') === 'readonly') {
            Log::info('[PropagateShopeeToken] Conta espelho readonly (SEL-357) — skip propagacao', [
                'account_id' => $account->id,
                'shop_id'    => $account->shop_id,
            ]);
            return;
        }

        $accessToken  = $this->decryptToken($account->access_token);
        $refreshToken = $this->decryptToken($account->refresh_token);

        if (! $accessToken || ! $refreshToken || ! $account->token_expires_at || ! $account->refresh_token_expires_at) {
            Log::warning('[PropagateShopeeToken] Conta sem cadeia completa — nada a propagar', [
                'account_id' => $account->id,
                'shop_id'    => $account->shop_id,
            ]);

            return;
        }

        // HUB-183: loja tambem vive no LEGADO como bridge_managed — espelhar o token
        // la, porque o refresher do legado pula essas rows (GOL-110 desligado).
        $this->pushToLegadoBridge($account, $accessToken, $refreshToken);

        $urls = $config->mirrorUrls();

        if (empty($urls)) {
            Log::info('[PropagateShopeeToken] Nenhuma WL espelho configurada (bridge.wl_urls)', [
                'account_id' => $account->id,
            ]);

            return;
        }

        $secret = (string) config('services.shopee.bridge_secret', '');
        if ($secret === '') {
            Log::error('[PropagateShopeeToken] SHOPEE_BRIDGE_SECRET ausente — propagacao abortada');

            return;
        }

        $body = json_encode([
            'shop_id'                  => (string) $account->shop_id,
            'access_token'             => $accessToken,
            'refresh_token'            => $refreshToken,
            'token_expires_at'         => $account->token_expires_at->toDateTimeString(),
            'refresh_token_expires_at' => $account->refresh_token_expires_at->toDateTimeString(),
        ]);
        $sig = hash_hmac('sha256', $body, $secret);

        $failures = 0;

        foreach ($urls as $baseUrl) {
            try {
                $response = Http::timeout(15)
                    ->withHeaders([
                        'X-HubAI-Bridge-Sig' => $sig,
                        'Content-Type'       => 'application/json',
                    ])
                    ->withBody($body, 'application/json')
                    ->post("{$baseUrl}/api/oauth/shopee/relay-token-refresh");

                if ($response->failed()) {
                    $failures++;
                    Log::warning('[PropagateShopeeToken] WL respondeu erro', [
                        'wl'      => $baseUrl,
                        'shop_id' => $account->shop_id,
                        'status'  => $response->status(),
                        'body'    => substr($response->body(), 0, 300),
                    ]);

                    continue;
                }

                Log::info('[PropagateShopeeToken] Token propagado', [
                    'wl'      => $baseUrl,
                    'shop_id' => $account->shop_id,
                    'updated' => $response->json('updated'),
                ]);
            } catch (\Throwable $e) {
                $failures++;
                Log::warning('[PropagateShopeeToken] WL inacessivel', [
                    'wl'      => $baseUrl,
                    'shop_id' => $account->shop_id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        // Falha parcial: re-tenta o job inteiro (receiver e idempotente por shop_id)
        if ($failures > 0 && $this->attempts() < $this->tries) {
            $this->release($this->backoff);
        }
    }

    /**
     * HUB-183: quando a loja existe no legado goolhub como bridge_managed, o
     * refresher de la nao renova (GOL-110) — o HUB e a unica fonte do token.
     * Mesmo formato/HMAC do bridge usado pelo ShopeeOAuthController.
     */
    private function pushToLegadoBridge(MarketplaceAccount $account, string $accessToken, string $refreshToken): void
    {
        try {
            $row = \Illuminate\Support\Facades\DB::connection('legacy')
                ->table('integracao')
                ->where('usuario', (string) $account->shop_id)
                ->whereIn('id_canal', [3, 5])
                ->where('id_app_shopee', 2)
                ->where('removida', 0)
                ->where('bridge_managed', 1)
                ->orderByDesc('id')
                ->first(['id', 'id_login']);

            if (! $row) {
                return;
            }

            $expireIn = max(60, now()->diffInSeconds($account->token_expires_at, false));
            $key      = (string) config('services.goolhub.bridge_key', 'hb-bridge-2026-xK9mP3qR7vL2nW8');
            $sig      = hash_hmac('sha256', "shopee:{$row->id_login}:{$account->shop_id}:{$accessToken}", $key);

            $resp = Http::timeout(10)->asForm()->post('https://goolhub.io/api/bridge/shopee_save_tokens.php', [
                'user_id'       => (int) $row->id_login,
                'shop_id'       => (int) $account->shop_id,
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
                'expire_in'     => (int) $expireIn,
                'sig'           => $sig,
            ]);

            Log::info('[PropagateShopeeToken] Token espelhado no legado (HUB-183)', [
                'account_id'    => $account->id,
                'shop_id'       => $account->shop_id,
                'legado_user'   => (int) $row->id_login,
                'integracao_id' => (int) $row->id,
                'status'        => $resp->status(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[PropagateShopeeToken] Bridge legado falhou (HUB-183) — nao bloqueia WLs', [
                'account_id' => $account->id,
                'shop_id'    => $account->shop_id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    private function decryptToken(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return decrypt($value);
        } catch (\Throwable $e) {
            return $value;
        }
    }
}
