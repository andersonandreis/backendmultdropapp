<?php

namespace App\Services\Integrations\Erps\Bling;

use App\Models\MarketplaceAccount;
use Illuminate\Support\Facades\Log;

/**
 * MUL-188: apos renovacao central do Bling, empurra os tokens novos pra WL de origem.
 * Bling rotaciona o refresh_token a cada renovacao — sem o push, o refresh_token
 * da WL morre e o refresh local marca a conta needs_reauth (invalid_grant).
 * Espelha o padrao Shopee NOV-181 (PropagateShopeeTokenJob).
 *
 * Chamado por BlingAuthService::saveTokens — cobre TODOS os caminhos de renovacao
 * (lazy via getValidToken e proativo via TokenRefreshService).
 */
class BlingWlRelayPusher
{
    public static function push(MarketplaceAccount $account, array $tokenData): void
    {
        if (! config('bling.use_relay', false)) {
            return;
        }

        $tenant = (string) ($account->service ?? '');
        if ($tenant === '' || $tenant === (string) config('bling.app_tenant', 'hubai') || ! $account->wl_client_id) {
            return;
        }

        $endpoint = (string) config('bling.relay_endpoints.' . $tenant, '');
        $secret   = (string) config('bling.relay_secret', '');
        if ($endpoint === '' || $secret === '') {
            Log::warning('[BlingWlRelayPusher] relay WL sem endpoint/secret configurado', [
                'account_id' => $account->id,
                'tenant'     => $tenant,
            ]);
            return;
        }

        try {
            \App\Jobs\RelayBlingTokenRetryJob::dispatch(
                tenant: $tenant,
                clientId: (int) $account->wl_client_id,
                supplierId: (int) $account->supplier_id,
                tokenData: [
                    'access_token'  => (string) ($tokenData['access_token'] ?? ''),
                    'refresh_token' => (string) ($tokenData['refresh_token'] ?? ''),
                    'expires_in'    => (int) ($tokenData['expires_in'] ?? 21600),
                    'scope'         => (string) ($tokenData['scope'] ?? ''),
                ],
                accountName: $account->account_name ?? 'Bling',
                secret: $secret,
                endpoint: $endpoint,
                accountType: null,
            );

            Log::info('[BlingWlRelayPusher] push pos-renovacao enfileirado pra WL', [
                'account_id'   => $account->id,
                'tenant'       => $tenant,
                'wl_client_id' => $account->wl_client_id,
            ]);
        } catch (\Throwable $e) {
            Log::error('[BlingWlRelayPusher] falha ao enfileirar push pra WL', [
                'account_id' => $account->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
