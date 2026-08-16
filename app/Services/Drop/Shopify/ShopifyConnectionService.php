<?php

namespace App\Services\Drop\Shopify;

use App\Models\Drop\DropStore;
use Illuminate\Support\Facades\Log;

/**
 * Gerencia conexão de lojas Shopify — criação, health check, webhooks e desconexão.
 */
class ShopifyConnectionService
{
    /**
     * Conecta (ou reconecta) uma loja Shopify para o cliente informado.
     * Cria ou atualiza o registro DropStore e registra os webhooks necessários.
     */
    public function connect(int $clientId, string $shopDomain, string $accessToken): DropStore
    {
        $encrypted = encrypt($accessToken);

        /** @var DropStore $store */
        $store = DropStore::updateOrCreate(
            [
                'client_id'   => $clientId,
                'shop_domain' => $shopDomain,
            ],
            [
                'access_token'           => $encrypted,
                'status'                 => 'active',
                'webhook_registered_at'  => null, // será preenchido por registerWebhooks
            ]
        );

        $this->registerWebhooks($store);

        Log::info('[ShopifyConnectionService] Loja conectada', [
            'store_id'    => $store->id,
            'client_id'   => $clientId,
            'shop_domain' => $shopDomain,
        ]);

        return $store->fresh();
    }

    /**
     * Busca informações gerais da loja via GET /shop.json.
     */
    public function getShopInfo(DropStore $store): array
    {
        $client = $this->makeClient($store);

        $response = $client->get('shop.json');

        return $response['shop'] ?? $response;
    }

    /**
     * Registra os webhooks necessários no Shopify.
     * Tópicos: orders/create, orders/updated, fulfillments/create, app/uninstalled.
     * Atualiza drop_stores.webhook_registered_at após sucesso.
     */
    public function registerWebhooks(DropStore $store): void
    {
        $client   = $this->makeClient($store);
        $baseUrl  = config('app.url');

        $topics = [
            'orders/create'        => '/webhooks/drop/shopify/orders-create',
            'orders/updated'       => '/webhooks/drop/shopify/orders-updated',
            'fulfillments/create'  => '/webhooks/drop/shopify/fulfillments-create',
            'app/uninstalled'      => '/webhooks/drop/shopify/app-uninstalled',
        ];

        foreach ($topics as $topic => $path) {
            try {
                $payload = [
                    'webhook' => [
                        'topic'   => $topic,
                        'address' => $baseUrl . $path,
                        'format'  => 'json',
                    ],
                ];

                $client->post('webhooks.json', $payload);

                Log::info('[ShopifyConnectionService] Webhook registrado', [
                    'store_id' => $store->id,
                    'topic'    => $topic,
                    'address'  => $baseUrl . $path,
                ]);
            } catch (\Throwable $e) {
                // Logar mas não abortar — webhook pode já estar registrado (422 duplicado)
                Log::warning('[ShopifyConnectionService] Falha ao registrar webhook', [
                    'store_id' => $store->id,
                    'topic'    => $topic,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        $store->update(['webhook_registered_at' => now()]);
    }

    /**
     * Verifica a saúde da conexão com a loja.
     * Retorna status, nome da loja e plano do Shopify.
     */
    public function healthCheck(DropStore $store): array
    {
        try {
            $info = $this->getShopInfo($store);

            return [
                'status'    => 'active',
                'shop_name' => $info['name'] ?? $store->shop_domain,
                'plan'      => $info['plan_name'] ?? 'unknown',
                'message'   => 'Conexão ativa',
            ];
        } catch (\Throwable $e) {
            Log::warning('[ShopifyConnectionService] healthCheck falhou', [
                'store_id' => $store->id,
                'error'    => $e->getMessage(),
            ]);

            return [
                'status'    => 'error',
                'shop_name' => $store->shop_domain,
                'plan'      => null,
                'message'   => $e->getMessage(),
            ];
        }
    }

    /**
     * Desconecta a loja — marca como inativa e limpa webhook_registered_at.
     */
    public function disconnect(DropStore $store): void
    {
        $store->update([
            'status'                => 'inactive',
            'webhook_registered_at' => null,
        ]);

        Log::info('[ShopifyConnectionService] Loja desconectada', [
            'store_id'    => $store->id,
            'shop_domain' => $store->shop_domain,
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeClient(DropStore $store): ShopifyApiClient
    {
        return new ShopifyApiClient($store->shop_domain, $store->access_token);
    }
}
