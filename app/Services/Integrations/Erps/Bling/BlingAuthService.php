<?php

namespace App\Services\Integrations\Erps\Bling;

use App\Models\ErpAccount;
use App\Models\MarketplaceAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BlingAuthService
{
    protected string $clientId;
    protected string $clientSecret;
    protected string $redirectUri;
    protected string $authUrl;
    protected string $tokenUrl;

    protected const RATE_LIMIT_CACHE_KEY = 'bling_oauth_rate_limited';
    protected const RATE_LIMIT_TTL_MINUTES = 60;

    public function __construct()
    {
        $this->clientId = config("bling.client_id");
        $this->clientSecret = config("bling.client_secret");
        $this->redirectUri = config("bling.redirect_uri");
        $this->authUrl = config("bling.auth_url");
        $this->tokenUrl = config("bling.token_url");
    }

    public function getAuthUrl(MarketplaceAccount $account): string
    {
        $state = $account->id . "|" . Str::random(32);
        session(["bling_oauth_state" => $state]);

        return $this->authUrl . "?" . http_build_query([
            "response_type" => "code",
            "client_id" => $this->clientId,
            "state" => $state,
        ]);
    }

    public function exchangeCode(string $code): array
    {
        if (Cache::has(self::RATE_LIMIT_CACHE_KEY)) {
            $expiresAt = Cache::get(self::RATE_LIMIT_CACHE_KEY . '_ttl', 'em breve');
            throw new \RuntimeException(
                "Bling rate limit ativo — servidor temporariamente bloqueado. Tente novamente apos {$expiresAt}."
            );
        }

        $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
            ->post($this->tokenUrl, [
                "grant_type" => "authorization_code",
                "code" => $code,
            ]);

        if ($response->failed()) {
            $this->handleRateLimitIfNeeded($response->status(), $response->body());
            throw new \RuntimeException("Bling OAuth token exchange failed: " . $response->body());
        }

        Cache::forget(self::RATE_LIMIT_CACHE_KEY);

        return $response->json();
    }

    public function refreshToken(string $refreshToken): array
    {
        if (Cache::has(self::RATE_LIMIT_CACHE_KEY)) {
            $expiresAt = Cache::get(self::RATE_LIMIT_CACHE_KEY . '_ttl', 'em breve');
            throw new \RuntimeException(
                "Bling rate limit ativo — aguardando expiracao (ate {$expiresAt}). Reconecte via /admin/contas-erp apos esse horario."
            );
        }

        $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
            ->post($this->tokenUrl, [
                "grant_type" => "refresh_token",
                "refresh_token" => $refreshToken,
            ]);

        if ($response->failed()) {
            $this->handleRateLimitIfNeeded($response->status(), $response->body());
            throw new \RuntimeException("Bling token refresh failed: " . $response->body());
        }

        return $response->json();
    }

    protected function handleRateLimitIfNeeded(int $status, string $body): void
    {
        $isRateLimited = $status === 429
            || str_contains($body, '1015')
            || str_contains($body, 'error code: 1015');

        if ($isRateLimited) {
            $expiresAt = now()->addMinutes(self::RATE_LIMIT_TTL_MINUTES)->format('H:i:s');
            Cache::put(self::RATE_LIMIT_CACHE_KEY, true, now()->addMinutes(self::RATE_LIMIT_TTL_MINUTES));
            Cache::put(self::RATE_LIMIT_CACHE_KEY . '_ttl', $expiresAt, now()->addMinutes(self::RATE_LIMIT_TTL_MINUTES));
            Log::warning('[BlingAuthService] Rate limit detectado — circuit breaker ativado por ' . self::RATE_LIMIT_TTL_MINUTES . 'min', [
                'status' => $status,
                'expires_at' => $expiresAt,
            ]);
        }
    }

    public function saveTokens(MarketplaceAccount $account, array $tokenData): void
    {
        $account->update([
            "bling_access_token" => encrypt($tokenData["access_token"]),
            "bling_refresh_token" => encrypt($tokenData["refresh_token"] ?? ""),
            "bling_token_expires_at" => now()->addSeconds($tokenData["expires_in"] ?? 21600),
            "status" => "active",
            "last_token_refresh_at" => now(),
        ]);

        // MUL-227 item 21: reconexao/renovacao invalida o cache do plan (senao fica 6h com dado velho)
        \Illuminate\Support\Facades\Cache::forget('bling_account_plan_ma_' . $account->id);

        // MUL-188: empurra tokens renovados pra WL de origem (Bling rotaciona refresh_token)
        BlingWlRelayPusher::push($account, $tokenData);
    }

    public function getValidToken(MarketplaceAccount $account): string
    {
        if ($account->bling_token_expires_at && $account->bling_token_expires_at->copy()->subMinutes(5)->isFuture()) {
            return decrypt($account->bling_access_token);
        }

        // MUL-188: conta gerenciada centralmente (bridge api.hubai.io) NUNCA renova local.
        // O refresh_token local pode ja ter sido rotacionado pelo hub -> invalid_grant
        // marcaria needs_reauth sem necessidade (foi o que derrubou a conta 71 do multdrop).
        if ($account->centrally_managed) {
            $account->refresh();
            if ($account->bling_token_expires_at && $account->bling_token_expires_at->isFuture()) {
                return decrypt($account->bling_access_token);
            }
            throw new \RuntimeException(
                "[BlingAuthService] Token expirado em conta centrally_managed #{$account->id} — aguardando push do hub central"
            );
        }

        // MUL-083: lock exclusivo para evitar race condition em refresh paralelo
        $lock = Cache::lock("bling_token_refresh_marketplace_{$account->id}", 30);
        try {
            $lock->block(15);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            // Outro worker esta renovando — aguardou mas nao conseguiu o lock.
            // Re-ler do banco: pode ja estar renovado.
            $account->refresh();
            if ($account->bling_token_expires_at && $account->bling_token_expires_at->copy()->subMinutes(5)->isFuture()) {
                return decrypt($account->bling_access_token);
            }
            throw new \RuntimeException("[BlingAuthService] Lock timeout ao renovar token marketplace #{$account->id}");
        }

        try {
            // Re-verificar apos obter o lock — outro worker pode ja ter renovado
            $account->refresh();
            if ($account->bling_token_expires_at && $account->bling_token_expires_at->copy()->subMinutes(5)->isFuture()) {
                return decrypt($account->bling_access_token);
            }

            $refreshToken = decrypt($account->bling_refresh_token);
            try {
                $tokenData = $this->refreshToken($refreshToken);
            } catch (\RuntimeException $e) {
                if (str_contains($e->getMessage(), 'invalid_grant')) {
                    $account->update([
                        'status'          => 'needs_reauth',
                        'sync_blocked_at' => now(),
                    ]);
                    Log::warning('[BlingAuthService] invalid_grant - marcando needs_reauth', [
                        'account_id' => $account->id,
                    ]);
                }
                throw $e;
            }
            $this->saveTokens($account, $tokenData);

            return $tokenData["access_token"];
        } finally {
            $lock->release();
        }
    }


    /**
     * Busca o perfil do usuario Bling autenticado via access_token recém-obtido no OAuth.
     * Usado em fetchPlatformProfile para salvar seller_id na MarketplaceAccount.
     */
    public static function getUserProfile(string $accessToken): array
    {
        $response = \Illuminate\Support\Facades\Http::withToken($accessToken)
            ->timeout(10)
            ->get('https://api.bling.com.br/Api/v3/usuarios/me'); // MUL-091: corrigido www->api

        if ($response->failed()) {
            throw new \RuntimeException('Bling getUserProfile failed [' . $response->status() . ']: ' . $response->body());
        }

        return $response->json() ?? [];
    }

    public function configureWebhooks(MarketplaceAccount $account, string $callbackUrl): void
    {
        $accessToken = decrypt($account->bling_access_token);

        $events = [
            'pedidos.vendas',
            'nfe',
            'estoques',
        ];

        foreach ($events as $event) {
            $response = Http::withToken($accessToken)
                ->post('https://api.bling.com.br/Api/v3/webhooks', [
                    'url' => $callbackUrl . '?seller_id=' . $account->id, // MUL-091: routing por seller_id
                    'evento' => $event,
                    'situacao' => 'ativo',
                ]);

            if ($response->failed()) {
                Log::warning("Bling webhook config failed [{$event}] for account {$account->id}: " . $response->body());
            } else {
                Log::info("Bling webhook configured: {$event} -> {$callbackUrl} for account {$account->id}");
            }
        }
    }

    public function saveTokensForErp(ErpAccount $account, array $tokenData): void
    {
        $account->update([
            "access_token"     => $tokenData["access_token"] ?? "",
            "refresh_token"    => $tokenData["refresh_token"] ?? "",
            "token_expires_at" => now()->addSeconds($tokenData["expires_in"] ?? 21600),
            "status"           => "active",
        ]);

        // MUL-227 item 21 erp: mesmo fix pro cache do plan em ErpAccount
        \Illuminate\Support\Facades\Cache::forget('bling_account_plan_erp_' . $account->id);
    }

    public function getValidTokenForErp(ErpAccount $account): string
    {
        if ($account->token_expires_at && $account->token_expires_at->copy()->subMinutes(5)->isFuture()) {
            return (string) $account->access_token;
        }

        // Se rate limit ativo para este ERP account, parar imediatamente (nao hammerar /oauth/token)
        $erpRateLimitKey = "bling_erp_rate_limited_{$account->id}";
        if (Cache::has($erpRateLimitKey)) {
            $until = Cache::get($erpRateLimitKey . '_until', 'em breve');
            throw new \RuntimeException("[BlingAuthService] ErpAccount #{$account->id} com rate limit ativo ate {$until} — aguardar antes de tentar novamente");
        }

        // MUL-083: lock exclusivo para evitar race condition em refresh paralelo
        $lock = Cache::lock("bling_token_refresh_erp_{$account->id}", 30);
        try {
            $lock->block(15);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            // Outro worker esta renovando — re-ler do banco
            $account->refresh();
            if ($account->token_expires_at && $account->token_expires_at->copy()->subMinutes(5)->isFuture()) {
                return (string) $account->access_token;
            }
            throw new \RuntimeException("[BlingAuthService] Lock timeout ao renovar token ERP #{$account->id}");
        }

        try {
            // Re-verificar apos obter o lock — outro worker pode ja ter renovado
            $account->refresh();
            if ($account->token_expires_at && $account->token_expires_at->copy()->subMinutes(5)->isFuture()) {
                return (string) $account->access_token;
            }

            $refreshToken = (string) $account->refresh_token;
            if (! $refreshToken) {
                throw new \RuntimeException("[BlingAuthService] ErpAccount #{$account->id} sem refresh_token");
            }

            try {
                $tokenData = $this->refreshToken($refreshToken);
            } catch (\RuntimeException $e) {
                if (str_contains($e->getMessage(), 'invalid_grant')) {
                    $account->update(['status' => 'needs_reauth']);
                    Log::warning('[BlingAuthService] invalid_grant ERP - marcando needs_reauth', [
                        'erp_account_id' => $account->id,
                    ]);
                } elseif (str_contains($e->getMessage(), '1015')
                    || str_contains($e->getMessage(), 'rate limit')
                    || str_contains($e->getMessage(), 'rate_limit')) {
                    // Bling bloqueou o IP por 60min — nao tentar novamente ate o bloqueio expirar
                    $until = now()->addMinutes(self::RATE_LIMIT_TTL_MINUTES)->format('H:i:s');
                    Cache::put($erpRateLimitKey, true, now()->addMinutes(self::RATE_LIMIT_TTL_MINUTES));
                    Cache::put($erpRateLimitKey . '_until', $until, now()->addMinutes(self::RATE_LIMIT_TTL_MINUTES));
                    Log::warning('[BlingAuthService] Rate limit ERP - parando tentativas por ' . self::RATE_LIMIT_TTL_MINUTES . 'min', [
                        'erp_account_id' => $account->id,
                        'until'          => $until,
                    ]);
                }
                throw $e;
            }

            $this->saveTokensForErp($account, $tokenData);

            return $tokenData["access_token"];
        } finally {
            $lock->release();
        }
    }
}
