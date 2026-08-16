<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\MarketplaceAccount;
use App\Models\ClientProduct;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\Log;

class OrderWebhookController extends Controller
{
    /**
     * Receives order notifications from Marketplaces
     */
    public function handle(Request $request, $platform)
    {
        Log::info("HubAI Webhook: Recebido evento de pedido via {$platform}", $request->all());

        // Fake payload parser based on platform
        // In a real scenario, you parse the specific platform's JSON
        $marketplaceOrderId = $request->input('order_id', 'ORDER_' . rand(1000, 9999));
        $items = $request->input('items', []); // Example: [['sku' => '1-CANECA-MLI1', 'quantity' => 1, 'price' => 50.0]]
        $customerPayload = $request->input('customer', ['name' => 'John Doe', 'email' => 'john@example.com']);

        if (empty($items)) {
            Log::warning("HubAI Webhook: Pedido sem itens. Ignorando.");
            return response()->json(['message' => 'No items in order'], 200);
        }

        // We take the first item's SKU to find the route
        $firstSku = $items[0]['sku'] ?? null;
        if (!$firstSku) {
            Log::error("HubAI Webhook: Produto sem SKU no Pedido.");
            return response()->json(['error' => 'Missing SKU'], 400);
        }

        // 1. ACHAR O PRODUTO DO LOJISTA VIA SUB-SKU
        $clientProduct = ClientProduct::where('custom_sku', $firstSku)->with('marketplaceAccount', 'product')->first();

        if (!$clientProduct || !$clientProduct->marketplaceAccount) {
            Log::error("HubAI Webhook: Sub-SKU nao encontrado ou sem conexao atrelada: {$firstSku}");
            return response()->json(['error' => 'Sub-SKU mapping not found'], 404);
        }

        $account = $clientProduct->marketplaceAccount;
        $supplierId = $account->supplier_id;
        $clientId = $account->client_id;

        // 2. CRIAR O PEDIDO NO BANCO ROTEADO PARA O FORNECEDOR CORRETO
        $order = Order::updateOrCreate(
            [
                'order_number' => $marketplaceOrderId,
                'client_id'    => $clientId,
                'supplier_id'  => $supplierId,
            ],
            [
                'status'           => 'pending',
                'total'            => collect($items)->sum(fn($i) => ($i['price'] ?? 0) * ($i['quantity'] ?? 1)),
                'customer_name'    => $customerPayload['name'] ?? 'Unknown',
                'customer_email'   => $customerPayload['email'] ?? null,
                'customer_address' => $request->input('shipping_address', []),
            ]
        );

        // 3. ATRELAR OS ITENS DO PEDIDO
        foreach ($items as $item) {
            // Se tiver varios itens no carrinho, checa cada Sub-SKU
            $cp = ClientProduct::where('custom_sku', $item['sku'] ?? '')->first();
            if ($cp) {
                OrderItem::updateOrCreate(
                    [
                        'order_id'   => $order->id,
                        'product_id' => $cp->product_id,
                    ],
                    [
                        'quantity'   => $item['quantity'] ?? 1,
                        'unit_price' => $item['price'] ?? $cp->custom_price,
                        'total'      => ($item['quantity'] ?? 1) * ($item['price'] ?? $cp->custom_price),
                    ]
                );
            }
        }

        Log::info("HubAI Webhook: Pedido {$marketplaceOrderId} roteado com sucesso para fornecedor ID {$supplierId} (Loja ID: {$clientId})");

        return response()->json(['message' => 'Order routed successfully', 'order_id' => $order->id], 200);
    }
}
