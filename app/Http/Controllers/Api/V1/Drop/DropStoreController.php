<?php

namespace App\Http\Controllers\Api\V1\Drop;

use App\Http\Controllers\Controller;
use App\Models\Drop\DropStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DropStoreController extends Controller
{
    private function clientOrFail(Request $request)
    {
        $client = $request->user()->client;
        if (!$client) {
            abort(403, 'Usuario nao possui perfil de lojista.');
        }
        return $client;
    }

    /**
     * Retorna todas as lojas do cliente (Shopify + Nativa).
     */
    public function show(Request $request): JsonResponse
    {
        $client = $this->clientOrFail($request);
        $stores = DropStore::where('client_id', $client->id)
            ->get()
            ->makeHidden(['access_token'])
            ->map(fn ($store) => array_merge($store->toArray(), [
                'public_url'  => $store->isNative() ? $store->getPublicUrl() : null,
                'gateways_count' => $store->isNative()
                    ? $store->paymentGateways()->where('is_active', true)->count()
                    : null,
            ]));

        if ($stores->isEmpty()) {
            return response()->json(['data' => [], 'message' => 'Nenhuma loja configurada.'], 200);
        }

        return response()->json(['data' => $stores]);
    }

    /**
     * Conectar uma loja Shopify ao cliente via ShopifyConnectionService.
     */
    public function connect(Request $request): JsonResponse
    {
        $client = $this->clientOrFail($request);

        $validated = $request->validate([
            'shop_domain'  => 'required|string|max:255',
            'access_token' => 'required|string',
        ]);

        $connectionService = app(\App\Services\Drop\Shopify\ShopifyConnectionService::class);
        $store = $connectionService->connect($client->id, $validated['shop_domain'], $validated['access_token']);

        return response()->json([
            'data'    => $store->makeHidden(['access_token']),
            'message' => 'Loja Shopify conectada com sucesso.',
        ], 201);
    }

    /**
     * Desconectar a loja Shopify do cliente.
     */
    public function disconnect(Request $request): JsonResponse
    {
        $client = $this->clientOrFail($request);

        $connectionService = app(\App\Services\Drop\Shopify\ShopifyConnectionService::class);
        $connectionService->disconnect($client->id);

        return response()->json(['message' => 'Loja desconectada com sucesso.']);
    }

    /**
     * Verificar saude da conexao com a loja Shopify.
     */
    public function health(Request $request): JsonResponse
    {
        $client = $this->clientOrFail($request);

        $connectionService = app(\App\Services\Drop\Shopify\ShopifyConnectionService::class);
        $result = $connectionService->healthCheck($client->id);

        return response()->json(['data' => $result]);
    }
}
