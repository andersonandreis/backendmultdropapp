<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\OrderSearch\OrderSearchService;
use Illuminate\Http\Request;
use App\Services\Federation\HubProxyHelper;

class OrderSearchController extends Controller
{
    public function search(Request $request, OrderSearchService $service)
    {
        if (HubProxyHelper::isWl()) {
            $u = $request->user();
            $c = $u ? $u->client : null;
            $body = $request->all();
            $body['client_id'] = $c ? ($c->hubai_id ?? $c->id) : null;
            return HubProxyHelper::forwardToHub('post', '/orders/search-marketplace', $body);
        }
        $validated = $request->validate([
            'marketplace'  => 'required|string|in:mercadolivre,ml,bling,shopee',
            'order_number' => 'required|string|min:3|max:100',
        ]);

        $client = $request->user()->client;
        if (!$client) {
            return response()->json(['error' => 'no_client'], 422);
        }

        $result = $service->search($client, $validated['marketplace'], $validated['order_number']);

        if ($result['found']) {
            return response()->json($result, 200);
        }

        return response()->json([
            'found'    => false,
            'error'    => $result['error'] ?? 'unknown',
            'fallback' => 'manual_order',
            'message'  => 'Pedido não encontrado. Você pode registrá-lo manualmente.',
        ], 404);
    }

    /**
     * INF-054 R5: search via federation. Só busca (read), não escreve.
     */
    public function searchFromFederation(Request $request, OrderSearchService $service)
    {
        $request->validate([
            'client_id'    => ['required', 'integer'],
            'marketplace'  => 'required|string|in:mercadolivre,ml,bling,shopee',
            'order_number' => 'required|string|min:3|max:100',
        ]);
        $client = \App\Models\Client::find($request->input('client_id'));
        if (!$client) return response()->json(['error' => 'client_not_found'], 404);
        $result = $service->search($client, $request->input('marketplace'), $request->input('order_number'));
        if ($result['found']) return response()->json($result, 200);
        return response()->json([
            'found' => false,
            'error' => $result['error'] ?? 'unknown',
            'fallback' => 'manual_order',
        ], 404);
    }

}
