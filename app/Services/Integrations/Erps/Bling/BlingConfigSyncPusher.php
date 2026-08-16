<?php

namespace App\Services\Integrations\Erps\Bling;

use App\Jobs\PushBlingConfigSyncJob;
use App\Models\MarketplaceAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * MUL-190: sync bidirecional das configs de importacao Bling (allowed_integrations,
 * data_inicial_import) entre WL e hub quando a conta esta em
 * modo relay — o que altera em um altera no outro.
 *
 * Direcao decidida pelo APP_TENANT:
 *  - hub -> WL : conta com service + wl_client_id (gemea da WL)
 *  - WL  -> hub: conta centrally_managed (tokens geridos pelo hub)
 *
 * Transporte: mesmo canal HMAC do relay de tokens MUL-188 (bling.relay_secret),
 * rota /api/oauth/bling/config-sync derivada de bling.relay_endpoints.
 */
class BlingConfigSyncPusher
{
    /** Guard anti-loop: setado pelo BlingConfigSyncController ao aplicar config recebida. */
    public static bool $paused = false;

    // MUL-311: only_ready_to_ship saiu — nunca foi lido por importador nenhum.
    public const FIELDS = ['allowed_integrations', 'data_inicial_import'];

    public static function push(MarketplaceAccount $account): void
    {
        if (self::$paused) {
            return;
        }
        if ($account->platform !== 'bling' || ! config('bling.use_relay', false)) {
            return;
        }

        $appTenant = (string) config('bling.app_tenant', 'hubai');

        if ($appTenant === 'hubai') {
            $targetTenant = (string) ($account->service ?? '');
            if ($targetTenant === '' || $targetTenant === 'hubai' || ! $account->wl_client_id) {
                return;
            }
            $targetClientId = (int) $account->wl_client_id;
        } else {
            if (! $account->centrally_managed) {
                return;
            }
            $targetTenant   = 'hubai';
            $targetClientId = (int) $account->client_id;
        }

        $endpoint = (string) config('bling.relay_endpoints.' . $targetTenant, '');
        $secret   = (string) config('bling.relay_secret', '');
        if ($endpoint === '' || $secret === '') {
            Log::warning('[BlingConfigSyncPusher] relay sem endpoint/secret configurado', [
                'account_id' => $account->id,
                'target'     => $targetTenant,
            ]);
            return;
        }

        // rota config-sync mora ao lado do wl-relay em todos os backends
        $endpoint = str_replace('/wl-relay', '/config-sync', $endpoint);

        PushBlingConfigSyncJob::dispatch(
            sourceTenant: $appTenant,
            targetTenant: $targetTenant,
            clientId: $targetClientId,
            supplierId: $account->supplier_id !== null ? (int) $account->supplier_id : null,
            config: [
                'allowed_integrations' => $account->allowed_integrations,
                'data_inicial_import'  => $account->data_inicial_import
                    ? Carbon::parse($account->data_inicial_import)->format('Y-m-d')
                    : null,
            ],
            endpoint: $endpoint,
            secret: $secret,
        );

        Log::channel('marketplace')->info('[BlingConfigSyncPusher] push de config enfileirado', [
            'account_id' => $account->id,
            'source'     => $appTenant,
            'target'     => $targetTenant,
            'client_id'  => $targetClientId,
        ]);
    }
}
