<?php

namespace App\Services\Marketplace\OrderSearch;

use App\Models\Client;
use App\Models\MarketplaceAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OrderSearchService
{
    public function search(Client $client, string $marketplace, string $orderNumber): array
    {
        $marketplace = strtolower($marketplace);

        switch ($marketplace) {
            case 'mercadolivre':
            case 'ml':
                return $this->searchML($client, $orderNumber);
            case 'bling':
                return $this->searchBling($client, $orderNumber);
            case 'shopee':
                return $this->searchShopee($client, $orderNumber);
            default:
                return ['found' => false, 'error' => 'marketplace_not_supported'];
        }
    }

    private function searchML(Client $client, string $orderId): array
    {
        $account = MarketplaceAccount::where('client_id', $client->id)
            ->where('platform', 'mercadolivre')
            ->where('status', 'active')
            ->first();

        if (!$account) {
            return ['found' => false, 'error' => 'no_ml_account'];
        }

        $token = $account->access_token;
        if (!$token) {
            return ['found' => false, 'error' => 'no_token'];
        }

        try {
            $response = Http::withToken($token)
                ->timeout(15)
                ->get("https://api.mercadolibre.com/orders/{$orderId}");

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'found'        => true,
                    'marketplace'  => 'mercadolivre',
                    'order_id'     => (string) $data['id'],
                    'buyer_name'   => trim(($data['buyer']['first_name'] ?? '') . ' ' . ($data['buyer']['last_name'] ?? '')),
                    'buyer_doc'    => $data['buyer']['billing_info']['doc_number'] ?? null,
                    'amount_cents' => (int) round(($data['total_amount'] ?? 0) * 100),
                    'currency'     => $data['currency_id'] ?? 'BRL',
                    'products'     => array_map(fn($i) => [
                        'sku'        => $i['item']['seller_sku'] ?? $i['item']['id'] ?? null,
                        'title'      => $i['item']['title'] ?? null,
                        'qty'        => $i['quantity'] ?? 1,
                        'unit_price' => $i['unit_price'] ?? 0,
                    ], $data['order_items'] ?? []),
                    'raw' => $data,
                ];
            }

            return [
                'found'       => false,
                'error'       => 'order_not_found_in_marketplace',
                'http_status' => $response->status(),
            ];
        } catch (\Throwable $e) {
            Log::error('OrderSearch ML failed', ['error' => $e->getMessage(), 'order_id' => $orderId]);
            return ['found' => false, 'error' => 'api_error', 'message' => $e->getMessage()];
        }
    }

    private function searchBling(Client $client, string $orderNumber): array
    {
        $account = MarketplaceAccount::where('client_id', $client->id)
            ->where('platform', 'bling')
            ->where('status', 'active')
            ->first();

        if (!$account) {
            return ['found' => false, 'error' => 'no_bling_account'];
        }

        try {
            $response = Http::withToken($account->access_token)
                ->timeout(15)
                ->get('https://api.bling.com.br/Api/v3/pedidos/vendas', ['numero' => $orderNumber]);

            if ($response->successful()) {
                $data = $response->json('data', []);
                if (empty($data)) {
                    return ['found' => false, 'error' => 'order_not_found_in_marketplace'];
                }

                $order = $data[0];
                return [
                    'found'        => true,
                    'marketplace'  => 'bling',
                    'order_id'     => (string) ($order['id'] ?? $order['numero']),
                    'buyer_name'   => $order['contato']['nome'] ?? null,
                    'buyer_doc'    => $order['contato']['numeroDocumento'] ?? null,
                    'amount_cents' => (int) round(($order['total'] ?? 0) * 100),
                    'currency'     => 'BRL',
                    'products'     => array_map(fn($i) => [
                        'sku'        => $i['codigo'] ?? null,
                        'title'      => $i['descricao'] ?? null,
                        'qty'        => $i['quantidade'] ?? 1,
                        'unit_price' => $i['valor'] ?? 0,
                    ], $order['itens'] ?? []),
                    'raw' => $order,
                ];
            }

            return ['found' => false, 'error' => 'order_not_found_in_marketplace'];
        } catch (\Throwable $e) {
            Log::error('OrderSearch Bling failed', ['error' => $e->getMessage(), 'order_number' => $orderNumber]);
            return ['found' => false, 'error' => 'api_error'];
        }
    }

    private function searchShopee(Client $client, string $orderSN): array
    {
        // Pulsar esta implementando GoolhubBridgeService::searchShopeeOrder em paralelo.
        // Chamada defensiva: verifica existencia do metodo antes de invocar.
        try {
            $bridge = app(\App\Services\GoolhubBridgeService::class);

            if (!method_exists($bridge, 'searchShopeeOrder')) {
                return ['found' => false, 'error' => 'shopee_bridge_not_ready'];
            }

            $result = $bridge->searchShopeeOrder($client->legacy_id_login, $orderSN);

            if (!$result['success']) {
                return ['found' => false, 'error' => 'bridge_failed', 'message' => $result['error'] ?? ''];
            }

            $data = $result['data'];
            return [
                'found'        => true,
                'marketplace'  => 'shopee',
                'order_id'     => (string) ($data['order_sn'] ?? $orderSN),
                'buyer_name'   => $data['buyer_username'] ?? null,
                'buyer_doc'    => null,
                'amount_cents' => (int) round(($data['total_amount'] ?? 0) * 100),
                'currency'     => $data['currency'] ?? 'BRL',
                'products'     => array_map(fn($i) => [
                    'sku'        => $i['item_sku'] ?? null,
                    'title'      => $i['item_name'] ?? null,
                    'qty'        => $i['model_quantity_purchased'] ?? 1,
                    'unit_price' => $i['model_discounted_price'] ?? $i['model_original_price'] ?? 0,
                ], $data['item_list'] ?? []),
                'raw' => $data,
            ];
        } catch (\Throwable $e) {
            Log::error('OrderSearch Shopee failed', ['error' => $e->getMessage(), 'order_sn' => $orderSN]);
            return ['found' => false, 'error' => 'bridge_error', 'message' => $e->getMessage()];
        }
    }
}
