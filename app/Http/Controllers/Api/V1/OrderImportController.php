<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Models\Order;
use App\Services\GoolhubBridgeService;
use App\Services\Integrations\Marketplaces\MercadoLivreService;
use App\Services\Integrations\Marketplaces\ShopeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\Federation\HubProxyHelper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Endpoints pra importacao manual de pedidos do marketplace (Feature B).
 *
 * Equivalente do legado `v3/importar-pedidos.php` via bridge. O seller
 * informa o numero do pedido + integracao e o worker do K3s baixa pela
 * API do marketplace.
 */
class OrderImportController extends Controller
{
    private const PLATFORM_TO_CANAL = [
        'shopee'       => 3,
        'mercadolivre' => 6,
        'magalu'       => 7,
        'bling'        => 20,
    ];

    public function __construct(private GoolhubBridgeService $bridge)
    {
    }

    private function clientOrFail(Request $request)
    {
        $client = $request->user()->client;
        if (!$client) {
            abort(403, 'Usuario nao possui perfil de lojista.');
        }
        return $client;
    }

    /**
     * GET /api/v1/orders/importable-accounts
     *
     * Lista integracoes do cliente que aceitam import (marketplaces com
     * API — Shopee, ML, Bling, Magalu — exclui 'manual' e canais sem
     * suporte).
     */
    public function accounts(Request $request): JsonResponse
    {
        $client = $this->clientOrFail($request);

        $accounts = MarketplaceAccount::where('client_id', $client->id)
            ->whereIn('platform', array_keys(self::PLATFORM_TO_CANAL))
            ->whereIn('status', ['active', 'imported'])
            ->orderBy('platform')
            ->get(['id', 'platform', 'account_name', 'shop_id', 'seller_id', 'status']);

        return response()->json(['data' => $accounts]);
    }

    /**
     * POST /api/v1/orders/import-by-number
     *
     * Body: { marketplace_account_id: int, order_number: string }
     *
     * Enfileira via bridge. Retorna estado pro frontend fazer polling.
     */
    public function import(Request $request): JsonResponse
    {
        if (HubProxyHelper::isWl()) {
            $u = $request->user();
            $c = $u ? $u->client : null;
            $body = $request->all();
            $body['client_id'] = $c ? ($c->hubai_id ?? $c->id) : null;
            return HubProxyHelper::forwardToHub('post', '/orders/import-by-number', $body);
        }
        $client = $this->clientOrFail($request);

        $data = $request->validate([
            'marketplace_account_id' => 'required|integer',
            'order_number'           => 'required|string|max:100',
        ]);

        $account = MarketplaceAccount::where('id', $data['marketplace_account_id'])
            ->where('client_id', $client->id)
            ->first();

        if (!$account) {
            return response()->json(['error' => 'Integracao nao encontrada'], 404);
        }

        $idCanal = self::PLATFORM_TO_CANAL[$account->platform] ?? null;
        if (!$idCanal) {
            return response()->json(['error' => 'Plataforma sem suporte a importacao'], 422);
        }

        // Verifica localmente se ja existe pra dar feedback rapido
        $existe = Order::where('client_id', $client->id)
            ->where(function ($q) use ($data) {
                $q->where('external_order_id', $data['order_number'])
                  ->orWhere('marketplace_order_id', $data['order_number']);
            })
            ->first(['id', 'external_order_id', 'marketplace_order_id', 'status']);
        if ($existe) {
            return response()->json([
                'data' => [
                    'status'      => 'already_exists',
                    'order_id'    => $existe->id,
                    'external_id' => $existe->external_order_id ?? $existe->marketplace_order_id,
                ],
            ]);
        }

        // Fallback direto via API marketplace quando cliente nao tem legacy_id_login
        if (!$client->legacy_id_login) {
            Log::info('[OrderImportController] import: cliente sem legacy_id_login, usando fetch direto', [
                'client_id'    => $client->id,
                'platform'     => $account->platform,
                'order_number' => $data['order_number'],
            ]);

            if ($account->platform === 'shopee') {
                $shopee = app(ShopeeService::class);
                return $this->fetchShopeeOrder($account, $data['order_number'], $shopee);
            }

            if ($account->platform === 'mercadolivre') {
                $ml = app(MercadoLivreService::class);
                return $this->fetchMLOrder($account, $data['order_number'], $ml);
            }

            if ($account->platform === 'bling') {
                return $this->fetchBlingOrder($account, $data['order_number']);
            }

            return response()->json(['error' => 'Plataforma sem suporte a importacao direta: ' . $account->platform], 422);
        }

        // Caminho padrao: bridge K3s (clientes com legacy_id_login)
        $result = $this->bridge->importOrderByNumber(
            (int) $client->legacy_id_login,
            $idCanal,
            $data['order_number'],
            $account->shop_id ?: $account->seller_id ?: null
        );

        if (!$result['success']) {
            return response()->json(['error' => $result['error'] ?? 'Bridge falhou'], 502);
        }

        return response()->json(['data' => $result['data']]);
    }

    /**
     * GET /api/v1/orders/check-import?order_number=X
     *
     * Usado pelo polling do frontend pra ver se o pedido ja chegou.
     */
    public function checkImport(Request $request): JsonResponse
    {
        $client = $this->clientOrFail($request);
        $num    = trim((string) $request->query('order_number', ''));
        if ($num === '') {
            return response()->json(['error' => 'order_number obrigatorio'], 422);
        }

        $order = Order::where('client_id', $client->id)
            ->where('external_order_id', $num)
            ->first(['id', 'external_order_id', 'status', 'order_processing_status']);

        if (!$order) {
            return response()->json(['data' => ['found' => false]]);
        }

        return response()->json([
            'data' => [
                'found'    => true,
                'order_id' => $order->id,
                'status'   => $order->status,
            ],
        ]);
    }

    /**
     * POST /api/v1/orders/fetch-by-id
     *
     * Busca um pedido pela ID do marketplace (Shopee order_sn ou ML order_id),
     * importa para o banco se ainda nao existir e retorna o pedido.
     *
     * Body: { "order_id": "STRING", "account_id": 527 }
     */
    public function fetchById(Request $request, ShopeeService $shopee, MercadoLivreService $ml): JsonResponse
    {
        if (HubProxyHelper::isWl()) {
            $u = $request->user();
            $c = $u ? $u->client : null;
            $body = $request->all();
            $body['client_id'] = $c ? ($c->hubai_id ?? $c->id) : null;
            return HubProxyHelper::forwardToHub('post', '/orders/fetch-by-id', $body);
        }
        $client = $this->clientOrFail($request);

        $validated = $request->validate([
            'order_id'   => 'required|string|max:100',
            'account_id' => 'required|integer',
        ]);

        $account = MarketplaceAccount::where('id', $validated['account_id'])
            ->where('client_id', $client->id)
            ->whereIn('status', ['active', 'imported'])
            ->first();

        if (! $account) {
            return response()->json(['error' => 'Conta de marketplace nao encontrada'], 404);
        }

        $orderId = trim($validated['order_id']);
        $source  = $account->platform;

        // Verificar se ja existe localmente
        $existing = Order::where('marketplace_order_id', $orderId)
            ->where('marketplace_account_id', $account->id)
            ->first();

        if ($existing) {
            return response()->json([
                'data' => [
                    'status'         => 'already_exists',
                    'order_id'       => $existing->id,
                    'marketplace_id' => $orderId,
                    'order_status'   => $existing->status,
                    'created_at'     => $existing->created_at,
                ],
            ]);
        }

        // Buscar via API do marketplace
        if ($source === 'shopee') {
            return $this->fetchShopeeOrder($account, $orderId, $shopee);
        }

        if ($source === 'mercadolivre') {
            return $this->fetchMLOrder($account, $orderId, $ml);
        }

        if ($source === 'bling') {
            return $this->fetchBlingOrder($account, $orderId);
        }

        return response()->json(['error' => 'Plataforma nao suportada: ' . $source], 422);
    }

    private function fetchShopeeOrder(MarketplaceAccount $account, string $orderSn, ShopeeService $shopee): JsonResponse
    {
        try {
            // getValidAccessToken e protected — usar reflexao
            $ref = new \ReflectionMethod($shopee, "getValidAccessToken");
            $ref->setAccessible(true);
            $token = $ref->invoke($shopee, $account);
            if (! $token) {
                return response()->json(["error" => "Token Shopee invalido"], 422);
            }
            $detail = $shopee->getOrderDetail((int) $account->shop_id, $token, [$orderSn]);
        } catch (\Throwable $e) {
            Log::error('[OrderImportController] fetchShopeeOrder excecao', [
                'account_id' => $account->id,
                'order_sn'   => $orderSn,
                'error'      => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Erro ao chamar API da Shopee: ' . $e->getMessage()], 502);
        }

        $orders = $detail['response']['order_list'] ?? [];

        if (empty($orders)) {
            $apiError = $detail['error'] ?? '';
            $apiMsg   = $detail['message'] ?? '';
            return response()->json([
                'error'   => 'Pedido nao encontrado na Shopee',
                'api_err' => $apiError ?: null,
                'api_msg' => $apiMsg ?: null,
            ], 404);
        }

        $raw          = $orders[0];
        $shopeeStatus = strtolower($raw['order_status'] ?? '');

        $statusMap = [
            'unpaid'        => 'pending',
            'ready_to_ship' => 'processing',
            'processed'     => 'processing',
            'shipped'       => 'shipped',
            'in_cancel'     => 'cancelled',
            'cancelled'     => 'cancelled',
            'to_return'     => 'returned',
            'completed'     => 'completed',
        ];
        $mappedStatus = $statusMap[$shopeeStatus] ?? 'pending';

        try {
            $order = Order::create([
                'client_id'              => $account->client_id,
                'supplier_id'            => $account->supplier_id ?? 1,
                'marketplace_account_id' => $account->id,
                'source'                 => 'shopee',
                'marketplace_order_id'   => $orderSn,
                // FOR-096: mesmo caso do ML — ver comentario no fetchMLOrder.
                'external_order_id'      => $orderSn,
                'order_number'           => $orderSn,
                'shop_id'                => (string) $account->shop_id,
                'status'                 => $mappedStatus,
                'canonical_status'       => $mappedStatus,
                'buyer_username'         => $raw['buyer_username'] ?? null,
                'tracking_number'        => $raw['tracking_no'] ?? null,
                'carrier_name'           => $raw['package_list'][0]['shipping_carrier'] ?? $raw['shipping_carrier'] ?? null,
                'raw_payload'            => json_encode($raw),
            ]);
        } catch (\Throwable $e) {
            Log::error('[OrderImportController] fetchShopeeOrder falha ao criar pedido', [
                'order_sn' => $orderSn,
                'error'    => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Erro ao salvar pedido: ' . $e->getMessage()], 500);
        }

        Log::info('[OrderImportController] fetchShopeeOrder pedido importado', [
            'account_id' => $account->id,
            'order_sn'   => $orderSn,
            'order_id'   => $order->id,
        ]);

        return response()->json([
            'data' => [
                'status'         => 'imported',
                'order_id'       => $order->id,
                'marketplace_id' => $orderSn,
                'order_status'   => $mappedStatus,
                'buyer'          => $raw['buyer_username'] ?? null,
                'tracking'       => $raw['tracking_no'] ?? null,
                'created_at'     => $order->created_at,
            ],
        ], 201);
    }

    private function fetchMLOrder(MarketplaceAccount $account, string $mlOrderId, MercadoLivreService $ml): JsonResponse
    {
        try {
            $ref = new \ReflectionMethod($ml, 'getValidAccessToken');
            $ref->setAccessible(true);
            $token = $ref->invoke($ml, $account);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Nao foi possivel obter token do ML'], 502);
        }

        if (! $token) {
            return response()->json(['error' => 'Token ML ausente ou invalido — reconecte a conta'], 422);
        }

        $response = \Illuminate\Support\Facades\Http::withToken($token)
            ->timeout(15)
            ->get("https://api.mercadolibre.com/orders/{$mlOrderId}");

        if ($response->status() === 404) {
            return response()->json(['error' => 'Pedido nao encontrado no Mercado Livre'], 404);
        }

        if ($response->failed()) {
            return response()->json([
                'error'       => 'API do Mercado Livre retornou erro',
                'http_status' => $response->status(),
                'body'        => $response->json(),
            ], 502);
        }

        $raw      = $response->json();
        $mlStatus = strtolower($raw['status'] ?? '');

        $statusMap = [
            'confirmed'          => 'pending',
            'payment_in_process' => 'pending',
            'payment_required'   => 'pending',
            'paid'               => 'paid',
            'cancelled'          => 'cancelled',
        ];
        $mappedStatus = $statusMap[$mlStatus] ?? 'pending';
        $buyer        = $raw['buyer'] ?? [];

        try {
            $order = Order::create([
                'client_id'              => $account->client_id,
                'supplier_id'            => $account->supplier_id ?? 1,
                'marketplace_account_id' => $account->id,
                'source'                 => 'mercadolivre',
                'marketplace_order_id'   => $mlOrderId,
                // FOR-096: sem external_order_id o EnrichMercadoLivreOrderJob desiste
                // na primeira linha e o pedido fica rascunho eterno — sem item, total
                // zerado, invisivel pro fornecedor. O SyncMLOrdersJob (caminho
                // automatico) sempre preenche; so a importacao manual nao preenchia.
                'external_order_id'      => $mlOrderId,
                'order_number'           => $mlOrderId,
                'status'                 => $mappedStatus,
                'canonical_status'       => $mappedStatus,
                'buyer_id'               => (string) ($buyer['id'] ?? ''),
                'buyer_nickname'         => $buyer['nickname'] ?? null,
                'raw_payload'            => json_encode($raw),
            ]);
        } catch (\Throwable $e) {
            Log::error('[OrderImportController] fetchMLOrder falha ao criar pedido', [
                'order_id' => $mlOrderId,
                'error'    => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Erro ao salvar pedido: ' . $e->getMessage()], 500);
        }

        Log::info('[OrderImportController] fetchMLOrder pedido importado', [
            'account_id' => $account->id,
            'order_id'   => $mlOrderId,
            'local_id'   => $order->id,
        ]);

        return response()->json([
            'data' => [
                'status'         => 'imported',
                'order_id'       => $order->id,
                'marketplace_id' => $mlOrderId,
                'order_status'   => $mappedStatus,
                'buyer'          => $buyer['nickname'] ?? null,
                'created_at'     => $order->created_at,
            ],
        ], 201);
    }


    /**
     * Busca e importa um pedido do Bling pelo ID externo (NOV-077-P6).
     *
     * Chamado por fetchById() e import() quando plataforma = bling.
     * Usa BlingApiClient::getOrder() diretamente, replicando a logica de syncOrder()
     * de BlingOrderSync (que e protected e nao pode ser chamada externamente).
     */
    private function fetchBlingOrder(MarketplaceAccount $account, string $orderId): JsonResponse
    {
        $blingClient = app(\App\Services\Integrations\Erps\Bling\BlingApiClient::class);

        try {
            $response = $blingClient->getOrder($account, (int) $orderId);
        } catch (\Throwable $e) {
            Log::error('[OrderImportController] fetchBlingOrder excecao', [
                'account_id' => $account->id,
                'order_id'   => $orderId,
                'error'      => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Erro ao chamar API do Bling: ' . $e->getMessage()], 502);
        }

        $orderData = $response['data'] ?? null;
        if (! $orderData) {
            $errCode = $response['error'] ?? '';
            return response()->json([
                'error'    => 'Pedido nao encontrado no Bling',
                'api_err'  => $errCode ?: null,
            ], 404);
        }

        $blingId     = (string) ($orderData['id'] ?? $orderId);
        $orderNumber = (string) ($orderData['numero'] ?? $blingId);

        $existing = \App\Models\Order::where('client_id', $account->client_id)
            ->where('external_order_id', $blingId)
            ->where('source', 'bling')
            ->first();

        if ($existing) {
            return response()->json([
                'data' => [
                    'status'         => 'already_exists',
                    'order_id'       => $existing->id,
                    'marketplace_id' => $blingId,
                    'order_status'   => $existing->status,
                    'created_at'     => $existing->created_at,
                ],
            ]);
        }

        $situacao = $orderData['situacao']['valor'] ?? null;
        $statusMap = [
            6  => 'pending',
            9  => 'paid',
            12 => 'cancelled',
            15 => 'shipped',
        ];
        $mappedStatus = $statusMap[(int) $situacao] ?? 'pending';

        try {
            $order = \App\Models\Order::create([
                'client_id'              => $account->client_id,
                'supplier_id'            => $account->supplier_id,
                'marketplace_account_id' => $account->id,
                'source'                 => 'bling',
                'external_order_id'      => $blingId,
                'marketplace_order_id'   => $blingId,
                'order_number'           => $orderNumber,
                'status'                 => $mappedStatus,
                'canonical_status'       => $mappedStatus,
                'customer_name'          => $orderData['contato']['nome'] ?? null,
                'total'                  => (float) ($orderData['totalProdutos'] ?? 0),
                'channel_name'           => $orderData['loja']['nome'] ?? null,
                'carrier_name'           => $orderData['transporte']['transportadora']['nome'] ?? null,
                'raw_payload'            => json_encode($orderData),
            ]);
        } catch (\Throwable $e) {
            Log::error('[OrderImportController] fetchBlingOrder falha ao criar pedido', [
                'bling_order_id' => $blingId,
                'error'          => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Erro ao salvar pedido: ' . $e->getMessage()], 500);
        }

        Log::info('[OrderImportController] fetchBlingOrder pedido importado', [
            'account_id' => $account->id,
            'bling_id'   => $blingId,
            'order_id'   => $order->id,
        ]);

        return response()->json([
            'data' => [
                'status'         => 'imported',
                'order_id'       => $order->id,
                'marketplace_id' => $blingId,
                'order_status'   => $mappedStatus,
                'customer'       => $orderData['contato']['nome'] ?? null,
                'created_at'     => $order->created_at,
            ],
        ], 201);
    }


    /**
     * INF-054 R5: import via federation. Usa marketplace_account do hub (ML/Shopee/Bling token).
     */
    public function importFromFederation(Request $request): JsonResponse
    {
        $request->validate([
            'client_id'              => ['required', 'integer'],
            'marketplace_account_id' => 'required|integer',
            'order_number'           => 'required|string|max:100',
        ]);
        $client = \App\Models\Client::find($request->input('client_id'));
        if (!$client) return response()->json(['error' => 'client_not_found'], 404);
        $request->setUserResolver(function () use ($client) {
            $u = new \stdClass();
            $u->id = null;
            $u->role = 'client';
            $u->client = $client;
            return $u;
        });
        $tenantSlug = $request->attributes->get('federation_tenant');
        $resp = $this->import($request);
        $body = $resp->getData(true);
        if (isset($body['data']['order_id'])) {
            \App\Jobs\FanoutOrderWebhookJob::dispatch((int) $body['data']['order_id'], 'order.created', ['source_wl' => $tenantSlug, 'action' => 'import']);
        }
        return $resp;
    }

    public function fetchByIdFromFederation(Request $request, ShopeeService $shopee, MercadoLivreService $ml): JsonResponse
    {
        $request->validate([
            'client_id'  => ['required', 'integer'],
            'order_id'   => 'required|string|max:100',
            'account_id' => 'required|integer',
        ]);
        $client = \App\Models\Client::find($request->input('client_id'));
        if (!$client) return response()->json(['error' => 'client_not_found'], 404);
        $request->setUserResolver(function () use ($client) {
            $u = new \stdClass();
            $u->id = null;
            $u->role = 'client';
            $u->client = $client;
            return $u;
        });
        $tenantSlug = $request->attributes->get('federation_tenant');
        $resp = $this->fetchById($request, $shopee, $ml);
        $body = $resp->getData(true);
        if (isset($body['data']['order_id'])) {
            \App\Jobs\FanoutOrderWebhookJob::dispatch((int) $body['data']['order_id'], 'order.created', ['source_wl' => $tenantSlug, 'action' => 'fetch_by_id']);
        }
        return $resp;
    }

}
