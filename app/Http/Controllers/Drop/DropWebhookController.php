<?php

namespace App\Http\Controllers\Drop;

use App\Http\Controllers\Controller;
use App\Models\Drop\DropStore;
use App\Services\Drop\Shopify\ShopifyWebhookHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Recebe webhooks do Shopify para o modulo Drop Internacional.
 * HMAC validado em cada requisicao; nunca retorna 4xx/5xx para nao desativar o webhook.
 */
class DropWebhookController extends Controller
{
    public function __construct(
        private readonly ShopifyWebhookHandler $handler
    ) {}

    /**
     * POST /webhooks/drop/shopify/orders-create
     */
    public function ordersCreate(Request $request): JsonResponse
    {
        return $this->process($request, function (array $payload, int $clientId) {
            $this->handler->handleOrderCreated($payload, $clientId);
        }, 'orders/create');
    }

    /**
     * POST /webhooks/drop/shopify/orders-updated
     */
    public function ordersUpdated(Request $request): JsonResponse
    {
        return $this->process($request, function (array $payload, int $clientId) {
            $this->handler->handleOrderUpdated($payload, $clientId);
        }, 'orders/updated');
    }

    /**
     * POST /webhooks/drop/shopify/fulfillments-create
     */
    public function fulfillmentsCreate(Request $request): JsonResponse
    {
        return $this->process($request, function (array $payload, int $clientId) {
            $this->handler->handleFulfillmentCreated($payload, $clientId);
        }, 'fulfillments/create');
    }

    /**
     * POST /webhooks/drop/shopify/app-uninstalled
     */
    public function appUninstalled(Request $request): JsonResponse
    {
        return $this->process($request, function (array $payload, int $clientId) {
            $this->handler->handleAppUninstalled($payload, $clientId);
        }, 'app/uninstalled');
    }

    // -------------------------------------------------------------------------
    // Helper central
    // -------------------------------------------------------------------------

    /**
     * Pipeline comum para todos os endpoints de webhook.
     * Sempre retorna HTTP 200 — o Shopify desativa webhooks que retornem 4xx/5xx.
     */
    private function process(Request $request, callable $handler, string $topic): JsonResponse
    {
        try {
            // 1. Ler raw payload
            $rawPayload = $request->getContent();

            // 2. Validar HMAC
            $hmacHeader = $request->header('X-Shopify-Hmac-Sha256', '');
            $secret     = config('services.shopify.api_secret', '');

            $this->handler->validateHmac($rawPayload, $hmacHeader, $secret);

            // 3. Extrair shop_domain do header
            $shopDomain = $request->header('X-Shopify-Shop-Domain', '');

            // 4. Encontrar DropStore pelo shop_domain
            $store = DropStore::where('shop_domain', $shopDomain)->first();

            if (!$store) {
                Log::warning('[DropWebhookController] DropStore nao encontrada', [
                    'topic'       => $topic,
                    'shop_domain' => $shopDomain,
                ]);
                return response()->json(['ok' => true], 200);
            }

            // 5. Decodificar payload e chamar handler especifico
            $payload = json_decode($rawPayload, true) ?? [];
            $handler($payload, $store->client_id);

        } catch (\Throwable $e) {
            // NUNCA retornar 4xx/5xx para o Shopify
            Log::error('[DropWebhookController] Erro ao processar webhook', [
                'topic'       => $topic,
                'shop_domain' => $request->header('X-Shopify-Shop-Domain', ''),
                'error'       => $e->getMessage(),
                'trace'       => $e->getTraceAsString(),
            ]);
        }

        return response()->json(['ok' => true], 200);
    }
}
