<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Services\Integrations\Erps\Bling\BlingConfigSyncPusher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * MUL-190: receiver do sync de config de importacao Bling (hub<->WL).
 *
 * Mesmo transporte HMAC do BlingRelayController (X-HubAI-Bridge-Sig sobre o
 * raw body com BLING_RELAY_HMAC_SECRET). Roda em todos os backends; o lado
 * local eh identificado por APP_TENANT:
 *  - hub: resolve a conta gemea por (service=source, wl_client_id=client_id)
 *  - WL : resolve por (client_id local, centrally_managed=1)
 */
class BlingConfigSyncController extends Controller
{
    public function receive(Request $request): JsonResponse
    {
        $secret    = (string) config('bling.relay_secret', '');
        $appTenant = (string) config('bling.app_tenant', 'hubai');

        if ($secret === '') {
            Log::error('[BlingConfigSync] BLING_RELAY_HMAC_SECRET nao configurada');
            return response()->json(['error' => 'relay_misconfigured'], 500);
        }

        $sig      = (string) $request->header('X-HubAI-Bridge-Sig', '');
        $rawBody  = $request->getContent();
        $expected = hash_hmac('sha256', $rawBody, $secret);

        if ($sig === '' || ! hash_equals($expected, $sig)) {
            Log::warning('[BlingConfigSync] assinatura invalida', ['ip' => $request->ip()]);
            return response()->json(['error' => 'invalid_signature'], 401);
        }

        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            return response()->json(['error' => 'invalid_payload'], 422);
        }

        $tenant   = (string) ($payload['tenant'] ?? '');
        $source   = (string) ($payload['source'] ?? '');
        $clientId = (int) ($payload['client_id'] ?? 0);

        if ($tenant !== $appTenant) {
            Log::warning('[BlingConfigSync] tenant mismatch', [
                'payload_tenant' => $tenant,
                'app_tenant'     => $appTenant,
            ]);
            return response()->json(['error' => 'tenant_mismatch'], 403);
        }

        if (! $clientId || $source === '') {
            return response()->json(['error' => 'missing_fields'], 422);
        }

        $query = MarketplaceAccount::query()->where('platform', 'bling');

        if ($appTenant === 'hubai') {
            $query->where('service', $source)->where('wl_client_id', $clientId);
        } else {
            $query->where('client_id', $clientId)->where('centrally_managed', true);
        }

        if (! empty($payload['supplier_id'])) {
            $query->where('supplier_id', (int) $payload['supplier_id']);
        }

        $account = $query->first();

        if (! $account) {
            Log::warning('[BlingConfigSync] conta gemea nao encontrada', [
                'source'     => $source,
                'client_id'  => $clientId,
                'app_tenant' => $appTenant,
            ]);
            return response()->json(['error' => 'account_not_found'], 404);
        }

        $config = [
            'allowed_integrations' => $payload['allowed_integrations'] ?? null,
            'data_inicial_import'  => $payload['data_inicial_import'] ?? null,
            // MUL-311: only_ready_to_ship removido do contrato. Payload antigo com o campo
            // continua sendo aceito — simplesmente ignorado.
        ];

        BlingConfigSyncPusher::$paused = true;
        try {
            $account->update($config);
        } finally {
            BlingConfigSyncPusher::$paused = false;
        }

        Log::channel('marketplace')->info('[BlingConfigSync] config aplicada', array_merge($config, [
            'account_id' => $account->id,
            'source'     => $source,
        ]));

        return response()->json(['synced' => true, 'account_id' => $account->id]);
    }
}
