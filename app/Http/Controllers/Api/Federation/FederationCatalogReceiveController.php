<?php

namespace App\Http\Controllers\Api\Federation;

use App\Http\Controllers\Controller;
use App\Jobs\FederationReceiveCatalogJob;
use App\Observers\ProductObserver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * NOV-171-C -- Endpoints de RECEPCAO nos WLs (multdrop, fornecefy, mestoredrop).
 *
 * Chamado pelo hub (api.hubai.io) via DispatchWebhookJob quando um produto
 * deve ser propagado ao WL. Autenticacao: X-HubAI-Signature HMAC.
 *
 * Regra anti-loop DUPLA:
 * 1. Se source_backend == federation.tenant local: ignorar (produto que saiu daqui nao volta)
 * 2. ProductObserver::$disableSync = true antes do upsert (dentro do job)
 *
 * Regra 8 do 00-INDEX: retornar 200 IMEDIATO, processamento via Job.
 */
class FederationCatalogReceiveController extends Controller
{
    /**
     * POST /api/federation/catalog/receive
     *
     * Recebe atualizacao de produto do hub e dispara FederationReceiveCatalogJob.
     * Gate anti-loop: se source_backend == federation.tenant do WL, ignora silenciosamente.
     *
     * NOTA: usa config('federation.tenant') -- NAO config('app.tenant') que nao existe em app.php.
     */
    public function receive(Request $request): JsonResponse
    {
        // federation.tenant = env('APP_TENANT', 'hubai') -- existe em config/federation.php
        $localTenant = config('federation.tenant', '');

        $payload = $request->json()->all();

        // Anti-loop: produto que saiu deste WL nao retorna para este WL
        $sourceBackend = $payload['source_backend'] ?? $payload['federation_source'] ?? null;
        if ($sourceBackend && $sourceBackend === $localTenant) {
            Log::debug('[FederationCatalogReceive] eco ignorado', [
                'source_backend' => $sourceBackend,
                'local_tenant'   => $localTenant,
                'sku'            => $payload['sku'] ?? null,
            ]);
            return response()->json(['message' => 'Eco ignorado.'], 200);
        }

        // Validacao minima
        if (empty($payload['sku'])) {
            return response()->json(['message' => 'Payload invalido: sku obrigatorio.'], 422);
        }

        // Despacha processamento async (regra 8 do 00-INDEX: retornar 200 imediato)
        FederationReceiveCatalogJob::dispatch($payload)->onQueue('webhooks');

        Log::info('[FederationCatalogReceive] recebido do hub, job despachado', [
            'sku'            => $payload['sku'] ?? null,
            'hub_product_id' => $payload['hub_product_id'] ?? null,
            'source_backend' => $sourceBackend,
            'local_tenant'   => $localTenant,
        ]);

        return response()->json(['message' => 'Recebido.'], 200);
    }
}