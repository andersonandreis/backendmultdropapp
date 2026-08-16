<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class HubAIWebhookController extends Controller
{
    /**
     * Recebe eventos de pedido disparados pelo api.hubai.io.
     *
     * Eventos suportados: order.created, order.updated, order.status_changed
     * Idempotencia garantida pela coluna orders.hubai_order_id (UNIQUE).
     *
     * Resolucao de client_id (por prioridade):
     *   1. order.client_id  — ID direto do Fornecefy (se hub ja souber)
     *   2. order.goolhub_user_id — user_id do legado → clients.legacy_id_login
     *   3. Rejeita com 422 explicativo
     */
    public function handleOrder(Request $request): JsonResponse
    {
        $request->validate([
            'event'    => 'required|string',
            'tenant'   => 'required|string',
            'order'    => 'required|array',
            'order.id' => 'required|integer',
        ]);

        $orderData = $request->input('order');
        $event     = $request->input('event');
        $tenant    = $request->input('tenant');

        // Ignora eventos nao tratados
        $handled = ['order.created', 'order.updated', 'order.status_changed'];
        if (!in_array($event, $handled)) {
            return response()->json(['ignored' => true, 'reason' => 'event_not_handled']);
        }

        // Resolve supplier_id: usa o do payload se informado, senao config local.
        // Nao filtra mais por supplier fixo — o webhook e da matriz hubai.io e
        // recebe pedidos de qualquer supplier (MStore, Drop Auto Pecas, Multdrop etc.).
        if (isset($orderData['supplier_id'])) {
            $localSupplierId = (int) $orderData['supplier_id'];
        } else {
            $localSupplierId = (int) config('multdrop.supplier_id', 30);
        }

        // Resolve client_id local
        $clientId = $this->resolveClientId($orderData);
        if ($clientId === null) {
            Log::warning('HubAI webhook: client nao resolvido', [
                'hubai_id'        => $orderData['id'],
                'client_id'       => $orderData['client_id'] ?? null,
                'goolhub_user_id' => $orderData['goolhub_user_id'] ?? null,
            ]);
            return response()->json([
                'error'  => 'client_not_resolved',
                'detail' => 'Envie client_id (ID Fornecefy) ou goolhub_user_id (legacy_id_login) no payload',
            ], 422);
        }

        $rawStatus       = $orderData['status'] ?? 'pending';
        $canonicalStatus = $this->mapCanonicalStatus($rawStatus);

        try {
            $order = Order::updateOrCreate(
                ['hubai_order_id' => (int)$orderData['id']],
                [
                    'supplier_id'      => $localSupplierId,
                    'client_id'        => $clientId,
                    'source'           => $orderData['source'] ?? 'hubai_webhook',
                    'status'           => $rawStatus,
                    'canonical_status' => $canonicalStatus,
                    'customer_name'    => $orderData['customer_name'] ?? null,
                    'customer_phone'   => $orderData['customer_phone'] ?? null,
                    'customer_email'   => $orderData['customer_email'] ?? null,
                    'total'            => $orderData['total'] ?? 0,
                    'subtotal'         => $orderData['subtotal'] ?? $orderData['total'] ?? 0,
                    'shipping_cost'    => $orderData['shipping_cost'] ?? 0,
                    'marketplace_fee'  => $orderData['marketplace_fee'] ?? 0,
                    'tenant_slug'      => $orderData['tenant_slug'] ?? $tenant,
                    'external_order_id' => $orderData['marketplace_order_id'] ?? $orderData['external_order_id'] ?? null,
                    'channel_name'     => $orderData['channel_name'] ?? $orderData['source'] ?? null,
                    'tracking_number'  => $orderData['tracking_number'] ?? null,
                ]
            );

            Log::info('HubAI webhook order processado', [
                'event'    => $event,
                'order_id' => $order->id,
                'hubai_id' => $orderData['id'],
                'created'  => $order->wasRecentlyCreated,
            ]);

            return response()->json([
                'ok'      => true,
                'order_id' => $order->id,
                'created'  => $order->wasRecentlyCreated,
            ]);

        } catch (\Throwable $e) {
            Log::error('HubAI webhook order falhou', [
                'error'    => $e->getMessage(),
                'hubai_id' => $orderData['id'] ?? null,
            ]);
            return response()->json(['error' => 'processing_failed'], 500);
        }
    }

    /**
     * Resolve o client_id local a partir do payload.
     *
     * Prioridade:
     *   1. order.client_id — ID direto do Fornecefy
     *   2. order.goolhub_user_id — user_id legado → clients.legacy_id_login
     */
    private function resolveClientId(array $orderData): ?int
    {
        // Prioridade 1: ID direto
        if (!empty($orderData['client_id'])) {
            return (int)$orderData['client_id'];
        }

        // Prioridade 2: lookup pelo ID do legado
        if (!empty($orderData['goolhub_user_id'])) {
            $client = Client::where('legacy_id_login', (int)$orderData['goolhub_user_id'])->first();
            return $client ? $client->id : null;
        }

        return null;
    }

    /**
     * Mapeia status raw do legado para canonical_status do Fornecefy.
     */
    private function mapCanonicalStatus(string $raw): string
    {
        return match (true) {
            in_array($raw, ['cancelled', 'canceled', 'returned'])  => 'cancelled',
            in_array($raw, ['shipped', 'dispatched'])               => 'shipped',
            in_array($raw, ['delivered'])                           => 'delivered',
            in_array($raw, ['paid', 'confirmed', 'processing'])     => 'paid',
            in_array($raw, ['pending', 'pending_payment'])          => 'created',
            default                                                   => 'created',
        };
    }
}
