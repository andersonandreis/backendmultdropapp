<?php

namespace App\Services\Webhooks;

use App\Models\BridgeRelayQueue;
use App\Models\MarketplaceAccount;
use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Relay de eventos de webhook do Mercado Livre para o sistema legado (goolhub.io).
 *
 * Contexto (HUB-077, 2026-06-20):
 * Quando o ML envia um webhook para api.hubai.io, a conta pode ser de um usuario
 * que foi migrado do legado (lazy migration). Nesse caso, client.legacy_id_login
 * esta preenchido e o legado ainda precisa receber o evento para processar o pedido.
 *
 * Fix 2026-06-23 (HUB-ML-RELAY):
 * O bridge retorna {"status":"ok","legacy_order_id":<int>} quando cria o pedido
 * no legado. Passamos a capturar esse legacy_order_id e atualizar o Order local
 * com legacy_id, fechando o ciclo de rastreabilidade e permitindo que
 * SyncLegacyOrdersJob sincronize status/etiqueta desses pedidos.
 *
 * Hardening 2026-06-23 (NOV-041):
 * Quando o bridge falha (offline, timeout, 5xx), o evento e enfileirado na
 * bridge_relay_queue com backoff exponencial (30s -> 5min -> 30min -> 2h).
 * O RetryBridgeRelayJob processa essa fila a cada 5 minutos.
 * NUNCA perde pedido silenciosamente — sempre loga + enfileira para retry.
 */
class LegacyMLRelayService
{
    private const BRIDGE_URL = 'https://goolhub.io/api/bridge/ml_event_relay.php';

    /**
     * Tenta fazer relay do evento ML para o legado, se a conta pertencer a um usuario migrado.
     * Em caso de falha, enfileira na bridge_relay_queue para retry automatico.
     *
     * @param string      $topic    Topico ML (orders_v2, items, shipments, etc.)
     * @param string      $resource Resource ML (/orders/123, /items/MLB123, etc.)
     * @param string|null $userId   ML user_id como string
     * @param array       $payload  Payload completo recebido do ML
     * @return int|null             legacy_order_id retornado pelo bridge, ou null se nao aplicavel/falhou
     */
    public function relayIfLegacy(string $topic, string $resource, ?string $userId, array $payload): ?int
    {
        if (!$userId) {
            return null;
        }

        try {
            $account = MarketplaceAccount::where('ml_user_id', $userId)
                ->with('client')
                ->first();

            if (!$account) {
                return null; // Conta nao registrada no NovoHubAI
            }

            $legacyId = $account->client?->legacy_id_login ?? null;

            if (!$legacyId) {
                return null; // Conta nativa NovoHubAI, sem relay necessario
            }

            $bridgeKey = config('services.goolhub.events_bridge_key', 'hb-bridge-2026-8ANH8TF5');
            $hmac      = hash_hmac('sha256', "mlwebhook:{$legacyId}:{$topic}:{$resource}", $bridgeKey);

            $body = array_merge($payload, [
                'legacy_id' => $legacyId,
                'topic'     => $topic,
                'resource'  => $resource,
            ]);

            $response = Http::timeout(5)
                ->asJson()
                ->withHeaders(['X-HubAI-Bridge-Sig' => $hmac])
                ->post(self::BRIDGE_URL, $body);

            $responseJson = $response->json() ?? [];

            // Fix HUB-ML-RELAY: capturar legacy_order_id da resposta do bridge
            $legacyOrderId = isset($responseJson['legacy_order_id'])
                ? (int) $responseJson['legacy_order_id']
                : null;

            Log::info('[ML-Relay-Legado] Webhook relayed ao bridge', [
                'ml_user_id'      => $userId,
                'legacy_id'       => $legacyId,
                'topic'           => $topic,
                'resource'        => $resource,
                'http_status'     => $response->status(),
                'legacy_order_id' => $legacyOrderId,
                'bridge_body'     => mb_substr($response->body(), 0, 300),
            ]);

            // Se bridge retornou legacy_order_id e topic e de pedido, atualiza o Order local
            if ($legacyOrderId && in_array($topic, ['orders', 'orders_v2', 'merchant_orders'])) {
                $this->updateOrderLegacyId($resource, $legacyOrderId, $account->client_id);
            }

            // NOV-041: Se bridge respondeu com erro HTTP, enfileirar para retry
            if (! $response->successful()) {
                $errorMsg = "HTTP {$response->status()}: " . mb_substr($response->body(), 0, 300);
                Log::warning('[ML-Relay-Legado] Bridge retornou erro HTTP — enfileirando para retry', [
                    'ml_user_id'  => $userId,
                    'topic'       => $topic,
                    'resource'    => $resource,
                    'http_status' => $response->status(),
                ]);
                $this->enqueueForRetry($topic, $resource, $userId, $legacyId, $payload, $account->client_id, $errorMsg);
            }

            return $legacyOrderId;

        } catch (\Throwable $e) {
            Log::error('[ML-Relay-Legado] Falha no relay do webhook ML — enfileirando para retry', [
                'ml_user_id' => $userId,
                'topic'      => $topic,
                'resource'   => $resource,
                'error'      => $e->getMessage(),
            ]);

            // NOV-041: Enfileirar para retry — NUNCA perder pedido silenciosamente
            try {
                $account = MarketplaceAccount::where('ml_user_id', $userId)->with('client')->first();
                $legacyId = $account?->client?->legacy_id_login;

                if ($legacyId) {
                    $this->enqueueForRetry(
                        $topic,
                        $resource,
                        $userId,
                        $legacyId,
                        $payload,
                        $account->client_id,
                        substr($e->getMessage(), 0, 500)
                    );
                }
            } catch (\Throwable $e2) {
                Log::critical('[ML-Relay-Legado] FALHA ao enfileirar para retry — pedido pode ser perdido', [
                    'ml_user_id' => $userId,
                    'resource'   => $resource,
                    'error'      => $e2->getMessage(),
                ]);
            }

            return null;
        }
    }

    /**
     * NOV-195: enfileira PEDIDO de seller ORFAO (sem conta no NovoHubAI) para
     * relay ao legado. Chamado pelo guard FOR-053-G no caminho do webhook —
     * por isso e so um INSERT (sem HTTP sincrono). O RetryBridgeRelayJob
     * entrega ao bridge, que resolve o login pelo ml user_id (integracao).
     */
    public function enqueueOrphanOrder(string $topic, string $resource, string $userId, array $payload): void
    {
        try {
            $parts     = explode('/', trim($resource, '/'));
            $mlOrderId = end($parts) ?: null;

            if (! $mlOrderId) {
                return;
            }

            $existing = BridgeRelayQueue::where('platform', 'mercadolivre')
                ->where('ml_order_id', $mlOrderId)
                ->whereIn('status', ['pending', 'processing'])
                ->exists();

            if ($existing) {
                return;
            }

            BridgeRelayQueue::enqueueMLRelay(
                $topic,
                $resource,
                $userId,
                0,
                array_merge($payload, ['_orphan' => true]),
                null,
                'NOV-195: pedido de seller orfao aguardando relay ao legado'
            );

            Log::info('[ML-Relay-Legado] Pedido de seller orfao enfileirado para o legado (NOV-195)', [
                'ml_user_id'  => $userId,
                'ml_order_id' => $mlOrderId,
                'topic'       => $topic,
            ]);
        } catch (\Throwable $e) {
            Log::error('[ML-Relay-Legado] Falha ao enfileirar pedido de orfao', [
                'ml_user_id' => $userId,
                'resource'   => $resource,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * NOV-195: relay de pedido de seller ORFAO ao bridge do legado.
     * Envia legacy_id=0 + payload original do ML; o bridge resolve o login
     * pelo ml user_id via tabela integracao (id_canal=6).
     *
     * @return string 'relayed'  - bridge aceitou (pedido na fila do legado)
     *                'discard'  - seller nao existe no legado (404) - descartar
     *                'retry'    - erro transitorio - manter na fila
     */
    public function relayOrphanOrder(string $topic, string $resource, string $userId, array $payload): string
    {
        $cleanPayload = array_filter(
            $payload,
            fn ($k) => ! str_starts_with((string) $k, '_'),
            ARRAY_FILTER_USE_KEY
        );

        $bridgeKey = config('services.goolhub.events_bridge_key', 'hb-bridge-2026-8ANH8TF5');
        $hmac      = hash_hmac('sha256', "mlwebhook:0:{$topic}:{$resource}", $bridgeKey);

        $body = array_merge($cleanPayload, [
            'legacy_id' => 0,
            'topic'     => $topic,
            'resource'  => $resource,
        ]);

        try {
            $response = Http::timeout(5)
                ->asJson()
                ->withHeaders(['X-HubAI-Bridge-Sig' => $hmac])
                ->post(self::BRIDGE_URL, $body);

            if ($response->successful()) {
                Log::info('[ML-Relay-Legado] Pedido de seller orfao relayed ao bridge (NOV-195)', [
                    'ml_user_id'          => $userId,
                    'topic'               => $topic,
                    'resource'            => $resource,
                    'legacy_id_resolvido' => $response->json('legacy_id'),
                    'legacy_order_id'     => $response->json('legacy_order_id'),
                ]);
                return 'relayed';
            }

            if ($response->status() === 404) {
                Log::info('[ML-Relay-Legado] Seller orfao nao existe no legado — descartando (NOV-195)', [
                    'ml_user_id'   => $userId,
                    'resource'     => $resource,
                    'bridge_error' => $response->json('error'),
                ]);
                return 'discard';
            }

            Log::warning('[ML-Relay-Legado] Bridge erro no relay de pedido orfao — retry (NOV-195)', [
                'ml_user_id'  => $userId,
                'resource'    => $resource,
                'http_status' => $response->status(),
                'body'        => mb_substr($response->body(), 0, 300),
            ]);
            return 'retry';

        } catch (\Throwable $e) {
            Log::warning('[ML-Relay-Legado] Excecao no relay de pedido orfao — retry (NOV-195)', [
                'ml_user_id' => $userId,
                'resource'   => $resource,
                'error'      => $e->getMessage(),
            ]);
            return 'retry';
        }
    }

    /**
     * Enfileira evento ML na bridge_relay_queue para retry automatico.
     * Nao duplica entradas para o mesmo pedido que ja estejam pendentes.
     */
    private function enqueueForRetry(
        string $topic,
        string $resource,
        string $mlUserId,
        int    $legacyId,
        array  $payload,
        int    $clientId,
        string $error
    ): void {
        $parts     = explode('/', trim($resource, '/'));
        $mlOrderId = end($parts) ?: null;

        if (! $mlOrderId) {
            return;
        }

        // Nao duplicar entradas para o mesmo pedido
        $existing = BridgeRelayQueue::where('platform', 'mercadolivre')
            ->where('ml_order_id', $mlOrderId)
            ->whereIn('status', ['pending', 'processing'])
            ->exists();

        if ($existing) {
            Log::info('[ML-Relay-Legado] Pedido ja esta na fila de retry', [
                'ml_order_id' => $mlOrderId,
            ]);
            return;
        }

        // Busca order_id local se existir
        $orderId = Order::where('client_id', $clientId)
            ->where('source', 'mercadolivre')
            ->where(function ($q) use ($mlOrderId) {
                $q->where('external_order_id', $mlOrderId)
                  ->orWhere('marketplace_order_id', $mlOrderId);
            })
            ->value('id');

        BridgeRelayQueue::enqueueMLRelay(
            $topic,
            $resource,
            $mlUserId,
            $legacyId,
            $payload,
            $orderId,
            $error
        );

        Log::info('[ML-Relay-Legado] Pedido enfileirado para retry', [
            'ml_order_id' => $mlOrderId,
            'legacy_id'   => $legacyId,
        ]);
    }

    /**
     * Atualiza o legacy_id do pedido local com base no resource do ML (/orders/<id>).
     * Tenta localizar pelo external_order_id ou marketplace_order_id.
     */
    public function updateOrderLegacyId(string $resource, int $legacyOrderId, int $clientId): void
    {
        $parts     = explode('/', trim($resource, '/'));
        $mlOrderId = end($parts) ?: null;

        if (!$mlOrderId) {
            Log::warning('[ML-Relay-Legado] Nao foi possivel extrair ml_order_id do resource', [
                'resource' => $resource,
            ]);
            return;
        }

        $order = Order::where('client_id', $clientId)
            ->where('source', 'mercadolivre')
            ->where(function ($q) use ($mlOrderId) {
                $q->where('external_order_id', $mlOrderId)
                  ->orWhere('marketplace_order_id', $mlOrderId);
            })
            ->whereNull('legacy_id')
            ->first();

        if (!$order) {
            Log::info('[ML-Relay-Legado] Pedido nao encontrado ou ja tem legacy_id', [
                'ml_order_id'     => $mlOrderId,
                'client_id'       => $clientId,
                'legacy_order_id' => $legacyOrderId,
            ]);
            return;
        }

        $order->updateQuietly(['legacy_id' => $legacyOrderId]);

        Log::info('[ML-Relay-Legado] legacy_id atualizado no pedido', [
            'order_id'        => $order->id,
            'ml_order_id'     => $mlOrderId,
            'legacy_order_id' => $legacyOrderId,
        ]);
    }
}
