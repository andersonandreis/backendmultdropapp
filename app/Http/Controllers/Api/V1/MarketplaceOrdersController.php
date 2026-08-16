<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Services\Integrations\Marketplaces\ShopeeService;
use App\Services\Integrations\Marketplaces\MercadoLivreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MarketplaceOrdersController extends Controller
{
    public function index(Request $request, ShopeeService $shopee, MercadoLivreService $ml): JsonResponse
    {
        $accountId = $request->query('account_id');
        if (! $accountId) {
            return response()->json(['error' => 'account_id e obrigatorio.'], 422);
        }
        $user   = $request->user();
        $client = $user->client;
        if (! $client) {
            return response()->json(['error' => 'Cliente nao encontrado.'], 404);
        }
        $account = MarketplaceAccount::where('id', $accountId)->where('client_id', $client->id)->first();
        if (! $account) {
            return response()->json(['error' => 'Conta marketplace nao encontrada.'], 404);
        }
        if ($account->status !== 'active') {
            return response()->json(['error' => 'Conta nao esta ativa.', 'status' => $account->status], 422);
        }
        try {
            if ($account->platform === 'shopee') {
                $orders = $this->fetchShopeeOrders($account, $shopee);
            } elseif ($account->platform === 'mercadolivre') {
                $orders = $this->fetchMLOrders($account, $ml);
            } else {
                return response()->json(['error' => 'Plataforma nao suportada: ' . $account->platform], 422);
            }
        } catch (\Throwable $e) {
            Log::channel('marketplace')->error('[MarketplaceOrdersController] Erro ao buscar pedidos', [
                'account_id' => $accountId, 'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Erro ao buscar pedidos da plataforma.'], 502);
        }
        return response()->json([
            'account_id' => (int) $accountId,
            'platform'   => $account->platform,
            'total'      => count($orders),
            'orders'     => $orders,
        ]);
    }

    private function fetchShopeeOrders(MarketplaceAccount $account, ShopeeService $shopee): array
    {
        $rawOrders = $shopee->fetchOrders($account, now()->subDays(30)->toDateTimeString());
        $orders = [];
        foreach (array_slice($rawOrders, 0, 50) as $raw) {
            $items = [];
            foreach ($raw['item_list'] ?? [] as $item) {
                $items[] = [
                    'item_id'   => $item['item_id'] ?? null,
                    'item_name' => $item['item_name'] ?? null,
                    'quantity'  => $item['model_quantity_purchased'] ?? 1,
                    'price'     => $item['model_discounted_price'] ?? null,
                ];
            }
            $orders[] = [
                'order_id'   => $raw['order_sn'] ?? null,
                'status'     => $raw['order_status'] ?? null,
                'total'      => $raw['total_amount'] ?? null,
                'buyer_name' => $raw['buyer_username'] ?? null,
                'items'      => $items,
                'created_at' => isset($raw['create_time']) ? date('Y-m-d H:i:s', (int) $raw['create_time']) : null,
            ];
        }
        return $orders;
    }

    private function fetchMLOrders(MarketplaceAccount $account, MercadoLivreService $ml): array
    {
        $rawOrders = $ml->fetchOrders($account, now()->subDays(30)->toIso8601String());
        $orders = [];
        foreach (array_slice($rawOrders, 0, 50) as $raw) {
            $items = [];
            foreach ($raw['order_items'] ?? [] as $item) {
                $items[] = [
                    'item_id'   => $item['item']['id'] ?? null,
                    'item_name' => $item['item']['title'] ?? null,
                    'quantity'  => $item['quantity'] ?? 1,
                    'price'     => $item['unit_price'] ?? null,
                ];
            }
            $buyer     = $raw['buyer'] ?? [];
            $buyerName = $buyer['nickname'] ?? (trim(($buyer['first_name'] ?? '') . ' ' . ($buyer['last_name'] ?? '')));
            $total     = $raw['payments'][0]['total_paid_amount'] ?? null;
            $orders[] = [
                'order_id'   => $raw['id'] ?? null,
                'status'     => $raw['status'] ?? null,
                'total'      => $total,
                'buyer_name' => $buyerName ?: null,
                'items'      => $items,
                'created_at' => $raw['date_created'] ?? null,
            ];
        }
        return $orders;
    }
}
