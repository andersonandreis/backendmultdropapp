<?php

namespace App\Http\Controllers\Api\Federation;

use App\Http\Controllers\Controller;
use App\Jobs\FederationReceiveOrderJob;
use App\Models\FederationOrderNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * NOV-171-C -- Recepcao de notificacoes de pedido do hub nos WLs.
 *
 * O hub dispara webhook quando pedido novo ou status alterado.
 * O WL persiste notificacao local (federation_order_notifications) para exibicao
 * no painel e controle de status. NAO e uma copia da tabela orders.
 *
 * Dedup: hub_delivery_id (UUID) garante idempotencia.
 * Anti-eco: se source_wl == federation.tenant local, ignorar.
 * Regra 8 do 00-INDEX: retornar 200 IMEDIATO, processamento via Job async.
 */
class FederationOrderReceiveController extends Controller
{
    /**
     * POST /api/federation/orders/receive
     *
     * Recebe notificacao de pedido do hub. Dedup por hub_delivery_id.
     *
     * NOTA: usa config('federation.tenant') -- NAO config('app.tenant') que nao existe em app.php.
     */
    public function receiveWebhook(Request $request): JsonResponse
    {
        // federation.tenant = env('APP_TENANT', 'hubai') -- existe em config/federation.php
        $localTenant = config('federation.tenant', '');

        $payload = $request->json()->all();

        // Anti-eco: mudanca de status que saiu deste WL nao volta para este WL
        $sourceWl = $payload['source_wl'] ?? null;
        if ($sourceWl && $sourceWl === $localTenant) {
            Log::debug('[FederationOrderReceive] eco ignorado', [
                'source_wl'    => $sourceWl,
                'local_tenant' => $localTenant,
                'hub_order_id' => $payload['hub_order_id'] ?? null,
            ]);
            return response()->json(['message' => 'Eco ignorado.'], 200);
        }

        $hubDeliveryId = $payload['hub_delivery_id'] ?? null;
        $hubOrderId    = $payload['hub_order_id']    ?? null;
        $originTenant  = $payload['origin_tenant']   ?? $payload['origin_tenant_slug'] ?? null;

        // Letra B (INF-053): payload do FanoutOrderWebhookJob usa formato {event, data: {order: {id}}}
        // sem hub_delivery_id/hub_order_id na raiz. Derivar do envelope pra manter compat.
        if (! $hubDeliveryId) {
            $hubDeliveryId = $payload['id'] ?? (\Illuminate\Support\Str::ulid()->toString());
        }
        if (! $hubOrderId) {
            $hubOrderId = $payload['data']['order']['id'] ?? $payload['order']['id'] ?? null;
        }
        if (! $originTenant) {
            $originTenant = $payload['data']['order']['tenant_slug'] ?? $payload['tenant_id'] ?? null;
        }

        if (! $hubOrderId) {
            return response()->json(['message' => 'Payload invalido: hub_order_id ou data.order.id obrigatorio.'], 422);
        }

        // Dedup: se ja existia, retorna 200 silenciosamente
        if (FederationOrderNotification::where('hub_delivery_id', $hubDeliveryId)->exists()) {
            Log::debug('[FederationOrderReceive] delivery duplicado ignorado', [
                'hub_delivery_id' => $hubDeliveryId,
                'hub_order_id'    => $hubOrderId,
            ]);
            return response()->json(['message' => 'Ja processado.'], 200);
        }

        // Persiste notificacao para exibicao no painel WL
        FederationOrderNotification::create([
            'hub_order_id'    => $hubOrderId,
            'hub_delivery_id' => $hubDeliveryId,
            'origin_tenant'   => $originTenant ?? $localTenant,
            'payload'         => $payload,
            'status'          => 'pending',
        ]);

        // Despacha processamento async
        FederationReceiveOrderJob::dispatch($hubDeliveryId)->onQueue('webhooks');

        Log::info('[FederationOrderReceive] notificacao de pedido recebida', [
            'hub_order_id'    => $hubOrderId,
            'hub_delivery_id' => $hubDeliveryId,
            'origin_tenant'   => $originTenant,
            'local_tenant'    => $localTenant,
            'event'           => $payload['event'] ?? null,
        ]);

        return response()->json(['message' => 'Recebido.'], 200);
    }
}