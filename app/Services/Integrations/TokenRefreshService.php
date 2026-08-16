<?php

namespace App\Services\Integrations;

use App\Models\MarketplaceAccount;
use App\Services\GoolhubBridgeService;
use App\Models\ErpAccount;
use App\Services\Integrations\Factories\MarketplaceFactory;
use App\Services\Integrations\Factories\ErpFactory;
use Illuminate\Support\Facades\Log;

class TokenRefreshService
{
    private const SUPPORTED_MARKETPLACE_PLATFORMS = [
        'mercadolivre',
        'mercado_livre',
        'shopee',
        'magalu',
        'tiktok',
    ];

    /** ML token dura 6h -- renovar quando faltam <= 90 min */
    private const ML_REFRESH_THRESHOLD_MINUTES = 90;

    /** Shopee access token dura 4h -- renovar quando faltam <= 2h */
    private const SHOPEE_ACCESS_REFRESH_THRESHOLD_MINUTES = 120;

    /** Shopee refresh token dura 30 dias -- alertar quando faltam <= 7 dias */
    private const SHOPEE_REFRESH_ALERT_DAYS = 7;

    private const CIRCUIT_BREAKER_THRESHOLD = 3;

    private TokenRefreshAlertService $alertService;

    public function __construct(TokenRefreshAlertService $alertService)
    {
        $this->alertService = $alertService;
    }

    /**
     * Varre contas com tokens proximos de vencer e renova.
     * Novidade vs versao anterior:
     *   - Thresholds: ML 90min (era 60), Shopee 2h (era 60min)
     *   - Auto-unblock: contas bloqueadas mas com token valido sao desbloqueadas automaticamente
     *   - Shopee: verifica refresh_token expirando em < 7 dias e alerta Telegram
     *   - Circuit breaker: dispara alerta Telegram imediato ao bloquear
     *   - Ciclo: acumula falhas e alerta se >= 5 contas falham no mesmo ciclo
     */
    public function refreshExpiringTokens(): void
    {
        // Passo 0: auto-unblock contas bloqueadas mas com token ainda valido
        $this->autoUnblockValidTokenAccounts();

        $mlThreshold     = now()->addMinutes(self::ML_REFRESH_THRESHOLD_MINUTES);
        $shopeeThreshold = now()->addMinutes(self::SHOPEE_ACCESS_REFRESH_THRESHOLD_MINUTES);

        // Passo 1: Marketplaces
        $marketplaces = MarketplaceAccount::where('status', 'active')
            ->whereIn('platform', self::SUPPORTED_MARKETPLACE_PLATFORMS)
            ->whereNull('sync_blocked_at')
            ->where(function ($query) use ($mlThreshold, $shopeeThreshold) {
                $query
                    ->where(function ($q) use ($mlThreshold) {
                        $q->whereIn('platform', ['mercadolivre', 'mercado_livre'])
                          ->where('ml_token_expires_at', '<=', $mlThreshold);
                    })
                    ->orWhere(function ($q) use ($shopeeThreshold) {
                        $q->where('platform', 'shopee')
                          ->where('token_expires_at', '<=', $shopeeThreshold);
                    })
                    ->orWhere(function ($sub) {
                        $sub->where('platform', 'shopee')
                            ->whereNull('token_expires_at')
                            ->whereNotNull('refresh_token');
                    })
                    ->orWhere(function ($q) use ($mlThreshold) {
                        $q->whereNotIn('platform', ['mercadolivre', 'mercado_livre', 'shopee'])
                          ->where('token_expires_at', '<=', $mlThreshold);
                    });
            })
            ->get();

        $instalacao = app(\App\Services\InstallationConfig::class);

        foreach ($marketplaces as $account) {
            // NOV-181: conta gerida pelo hub tem UM dono de token, e e' o hub. Quando a WL esta
            // em bridge, renovar aqui e' brigar pelo mesmo refresh_token: a Shopee rotaciona a
            // cadeia e invalida o access_token anterior, entao os dois lados se derrubam.
            // A trava foi posta na ShopeeRefreshTokensCommand:105, na ShopeeRecoverTokensCommand:77
            // e no ShopeeService:811, e faltou aqui — que e' o renovador generico, chamado de
            // hora em hora por tokens:proactive-refresh. Medido em 06/08: 8 contas levando 403 no
            // get_order_list, uma vez por hora, 6 delas lojas ativas do MultDrop.
            if ($instalacao->usesBridge($account->platform) && $account->centrally_managed) {
                continue;
            }

            if ($account->platform === 'shopee') {
                $this->checkShopeeRefreshTokenExpiry($account);
            }

            try {
                $client   = MarketplaceFactory::make($account);
                $newToken = $client->refreshToken($account);

                if ($newToken) {
                    $account->update([
                        'sync_errors_count'    => 0,
                        'sync_blocked_at'      => null,
                        'refresh_errors_count' => 0,
                        'last_error_message'   => null,
                        'last_token_refresh_at' => now(),
                    ]);
                    Log::info("[TokenRefresh] {$account->platform} Account {$account->id} renovado");
                } else {
                    $this->handleRefreshFailure($account, 'reauth necessario');
                }
            } catch (\Exception $e) {
                $this->handleRefreshFailure($account, $e->getMessage());
            }
        }

        // Passo 2: ERPs (Bling via erp_accounts)
        $erps = ErpAccount::where('status', 'active')->get();
        foreach ($erps as $erp) {
            try {
                $creds = $erp->credentials;
                if (!$creds || !isset($creds['expires_at'])) {
                    continue;
                }
                if ($creds['expires_at'] <= time() + (self::ML_REFRESH_THRESHOLD_MINUTES * 60)) {
                    $erpService = ErpFactory::make($erp->platform ?? 'bling');
                    $newToken   = $erpService->refreshToken($erp);
                    if ($newToken) {
                        Log::info("[TokenRefresh] ERP Account {$erp->id} renovado");
                    }
                }
            } catch (\Exception $e) {
                Log::error("[TokenRefresh] ERP Account {$erp->id}: {$e->getMessage()}");
            }
        }

        // Passo 3: Bling via marketplace_accounts
        $blingThreshold = now()->addMinutes(self::ML_REFRESH_THRESHOLD_MINUTES);
        $blingAccounts  = MarketplaceAccount::where('status', 'active')
            ->where('platform', 'bling')
            ->where('centrally_managed', 0) // MUL-188: WL nao renova conta gerenciada pelo hub central
            ->whereNotNull('bling_refresh_token')
            ->whereNull('sync_blocked_at')
            ->where(function ($query) use ($blingThreshold) {
                $query->whereNull('bling_token_expires_at')
                      ->orWhere('bling_token_expires_at', '<=', $blingThreshold);
            })
            ->get();

        foreach ($blingAccounts as $account) {
            try {
                $blingAuth = app(\App\Services\Integrations\Erps\Bling\BlingAuthService::class);
                $token     = $blingAuth->getValidToken($account);

                if ($token) {
                    $account->update([
                        'sync_errors_count'    => 0,
                        'sync_blocked_at'      => null,
                        'refresh_errors_count' => 0,
                        'last_error_message'   => null,
                        'last_token_refresh_at' => now(),
                    ]);
                    Log::info("[TokenRefresh] bling Account {$account->id} renovado");

                    // Relay para legadogool: atualiza integracao.bling_access_token (id_canal=20)
                    $this->relayBlingTokensToLegacy($account, $token);
                    // MUL-188: push pra WL de origem acontece dentro de
                    // BlingAuthService::saveTokens (BlingWlRelayPusher) — cobre lazy refresh tambem
                } else {
                    $this->handleRefreshFailure($account, 'reauth necessario');
                }
            } catch (\Exception $e) {
                $this->handleRefreshFailure($account, $e->getMessage());
            }
        }

        // Flush alertas acumulados no ciclo (< 5 falhas que ainda nao foram enviadas)
        $this->alertService->flushCycleAlerts();
    }

    // -------------------------------------------------------------------------

    /**
     * Relay de tokens Bling renovados para o legado goolhub.io.
     *
     * Identifica o usuario via client.legacy_id_login.
     * O bridge atualiza integracao.bling_access_token / bling_refresh_token (id_canal=20).
     * Fire-and-forget: falhas sao apenas logadas, nao propagadas.
     */
    private function relayBlingTokensToLegacy(MarketplaceAccount $account, string $accessToken): void
    {
        try {
            $client = $account->client;
            if (!$client || !$client->legacy_id_login) {
                Log::debug("[TokenRefresh] Bling relay ignorado: conta {$account->id} sem legacy_id_login");
                return;
            }

            $refreshToken = decrypt($account->bling_refresh_token);

            $bridge = app(GoolhubBridgeService::class);
            $result = $bridge->relayBlingTokens(
                $client->legacy_id_login,
                $accessToken,
                $refreshToken
            );

            if ($result['success']) {
                Log::info("[TokenRefresh] Bling relay OK: conta {$account->id} -> legacy_id={$client->legacy_id_login}", [
                    'rows_updated' => $result['data']['rows_updated'] ?? 0,
                ]);
            } else {
                Log::warning("[TokenRefresh] Bling relay falhou: conta {$account->id}", [
                    'error' => $result['error'],
                ]);
            }
        } catch (\Throwable $e) {
            Log::error("[TokenRefresh] Bling relay excecao: conta {$account->id}: {$e->getMessage()}");
        }
    }

    // -------------------------------------------------------------------------

    /**
     * Desbloqueio automatico de contas que estao bloqueadas mas tem token valido.
     * Criterio: sync_blocked_at IS NOT NULL, status=active, token expira em > 30min.
     * Isso cobre o caso de contas bloqueadas por timeout de DNS em 31/05,
     * mas cujos tokens foram renovados via request do usuario depois.
     */
    private function autoUnblockValidTokenAccounts(): void
    {
        $safeWindow = now()->addMinutes(30);

        $blocked = MarketplaceAccount::where('status', 'active')
            ->whereNotNull('sync_blocked_at')
            ->where('refresh_errors_count', '<', self::CIRCUIT_BREAKER_THRESHOLD)
            ->where(function ($q) use ($safeWindow) {
                $q->where('ml_token_expires_at', '>', $safeWindow)
                  ->orWhere('token_expires_at', '>', $safeWindow);
            })
            ->get();

        foreach ($blocked as $account) {
            $account->update([
                'sync_blocked_at'   => null,
                'sync_errors_count' => 0,
                'last_error_message' => 'auto-unblocked: token valido detectado',
            ]);
            Log::info(
                "[TokenRefresh] Auto-unblock: {$account->platform} Account {$account->id} desbloqueado"
            );
        }
    }

    /**
     * Verifica se o refresh_token Shopee expira em < 7 dias.
     * Se sim, dispara alerta Telegram.
     */
    private function checkShopeeRefreshTokenExpiry(MarketplaceAccount $account): void
    {
        if (!$account->refresh_token_expires_at) {
            return;
        }

        $daysLeft = (int) now()->diffInDays($account->refresh_token_expires_at, false);

        if ($daysLeft <= self::SHOPEE_REFRESH_ALERT_DAYS && $daysLeft >= 0) {
            $this->alertService->alertShopeeRefreshTokenExpiring(
                $account->id,
                $account->account_name ?? "Account #{$account->id}",
                $daysLeft
            );
        }
    }

    /**
     * Incrementa contadores e aplica circuit breaker.
     * Dispara alertas Telegram via TokenRefreshAlertService.
     */
    private function handleRefreshFailure(MarketplaceAccount $account, string $reason): void
    {
        $shortReason = mb_substr($reason, 0, 255);

        $account->increment('sync_errors_count');
        $account->increment('refresh_errors_count');
        $account->update(['last_error_message' => $shortReason]);
        $account->refresh();

        $this->alertService->recordFailure(
            $account->platform,
            $account->id,
            $account->account_name ?? "Account #{$account->id}",
            $reason
        );

        if ($account->sync_errors_count >= self::CIRCUIT_BREAKER_THRESHOLD) {
            $account->update([
                'sync_blocked_at' => now(),
                'status'          => 'needs_reauth',
            ]);

            Log::critical(
                "[ML-REAUTH-NEEDED] {$account->platform} Account id={$account->id} " .
                "bloqueada apos {$account->sync_errors_count} falhas. Motivo: {$reason}."
            );

            $this->alertService->alertCircuitBreaker(
                $account->platform,
                $account->id,
                $account->account_name ?? "Account #{$account->id}",
                $reason
            );
        } else {
            Log::warning(
                "[TokenRefresh] {$account->platform} Account {$account->id} " .
                "falhou ({$account->sync_errors_count}/" . self::CIRCUIT_BREAKER_THRESHOLD . "): {$reason}"
            );
        }
    }
}