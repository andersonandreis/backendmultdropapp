<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Models\Order;
use App\Services\Integrations\Marketplaces\ShopeeService;
use App\Services\Financial\ReconciliationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShopeePaymentController extends Controller
{
    public function __construct(
        private readonly ShopeeService $shopeeService,
        private readonly ReconciliationService $reconciliationService,
    ) {}

    /**
     * GET /api/v1/admin/shopee/pending-captures
     * Lista pedidos Shopee entregues sem captura de pagamento.
     * Filtra automaticamente por client_id quando o usuario nao e admin.
     * Aceita query params: client_id (admin filtrar por cliente especifico), per_page.
     */
    public function pendingCaptures(Request $request)
    {
        $perPage = min((int) $request->get('per_page', 20), 100);
        $user = $request->user();

        $q = Order::where('source', 'shopee')
            ->whereIn('status', ['delivered', 'shipped'])
            ->whereNull('captured_at')
            ->whereNotNull('external_order_id')
            ->with('items:id,order_id,name,sku,quantity,unit_price')
            ->select([
                'id', 'order_number', 'external_order_id', 'customer_name',
                'total', 'status', 'paid_at', 'shipped_at', 'delivered_at',
                'tracking_number', 'created_at', 'client_id', 'supplier_id',
            ])
            ->latest();

        // Isolamento: nao-admin so ve os proprios pedidos
        $isAdmin = $user && in_array($user->role, ['super_admin', 'admin', 'supplier']);
        if (!$isAdmin) {
            $clientId = $user?->client?->id;
            if (!$clientId) {
                return response()->json([
                    'data' => [],
                    'meta' => ['total' => 0, 'current_page' => 1, 'last_page' => 1],
                ]);
            }
            $q->where('client_id', $clientId);
        } elseif ($filterClientId = (int) $request->query('client_id')) {
            // Admin pode filtrar por cliente especifico via query param
            $q->where('client_id', $filterClientId);
        }

        $orders = $q->paginate($perPage);

        // Mapear name -> product_name para compatibilidade com AdminShopeePayments.tsx
        $mappedItems = collect($orders->items())->map(function ($order) {
            $arr = $order->toArray();
            if (isset($arr['items'])) {
                $arr['items'] = array_map(function ($item) {
                    $item['product_name'] = $item['name'] ?? null;
                    return $item;
                }, $arr['items']);
            }
            return $arr;
        })->all();

        return response()->json([
            'data'  => $mappedItems,
            'meta'  => [
                'total'        => $orders->total(),
                'current_page' => $orders->currentPage(),
                'last_page'    => $orders->lastPage(),
            ],
        ]);
    }

    /**
     * POST /api/v1/admin/shopee/capture-payment/{orderId}
     * Captura o repasse Shopee (escrow) para um pedido entregue.
     */
    public function capturePayment(Request $request, int $orderId)
    {
        $order = Order::where('source', 'shopee')
            ->whereNotNull('external_order_id')
            ->findOrFail($orderId);

        if ($order->captured_at) {
            return response()->json(['message' => 'Pagamento ja capturado em ' . $order->captured_at], 409);
        }

        // Encontrar a conta Shopee do cliente
        $account = MarketplaceAccount::where('client_id', $order->client_id)
            ->where('platform', 'shopee')
            ->where('is_active', true)
            ->first();

        $escrowData = null;
        $capturedAmount = (float) $order->total;
        $captureSource = 'manual';

        if ($account) {
            $escrow = $this->shopeeService->getEscrowDetail($account, $order->external_order_id);
            if (!empty($escrow['response'])) {
                $resp = $escrow['response'];
                $capturedAmount = (float) ($resp['order_income'] ?? $resp['seller_income'] ?? $capturedAmount);
                $escrowData = $resp;
                $captureSource = 'shopee_escrow';
            } else {
                Log::warning('[ShopeePaymentController] Escrow nao disponivel, usando valor do pedido', [
                    'order_id' => $orderId,
                    'escrow'   => $escrow,
                ]);
            }
        }

        DB::transaction(function () use ($order, $capturedAmount, $captureSource, $escrowData) {
            $order->update([
                'captured_amount'  => $capturedAmount,
                'captured_at'      => now(),
                'capture_source'   => $captureSource,
                'capture_payload'  => $escrowData ? json_encode($escrowData) : null,
            ]);

            // Creditar no ledger do fornecedor se o servico existir
            try {
                $this->reconciliationService->creditSale($order);
            } catch (\Throwable $e) {
                Log::warning('[ShopeePaymentController] reconciliationService nao creditou', [
                    'order_id' => $order->id,
                    'error'    => $e->getMessage(),
                ]);
            }
        });

        return response()->json([
            'message'          => 'Pagamento capturado com sucesso.',
            'order_id'         => $order->id,
            'captured_amount'  => $capturedAmount,
            'capture_source'   => $captureSource,
            'captured_at'      => $order->fresh()->captured_at,
        ]);
    }

    /**
     * POST /api/v1/admin/shopee/capture-batch
     * Captura em lote (array de order_ids).
     */
    public function captureBatch(Request $request)
    {
        $request->validate(['order_ids' => 'required|array|min:1|max:50', 'order_ids.*' => 'integer']);
        $ids = $request->input('order_ids');

        $results = ['captured' => [], 'skipped' => [], 'errors' => []];

        foreach ($ids as $id) {
            try {
                $res = $this->capturePayment($request, (int) $id);
                $data = $res->getData(true);
                if ($res->getStatusCode() === 409) {
                    $results['skipped'][] = $id;
                } else {
                    $results['captured'][] = ['order_id' => $id, 'amount' => $data['captured_amount']];
                }
            } catch (\Throwable $e) {
                $results['errors'][] = ['order_id' => $id, 'error' => $e->getMessage()];
            }
        }

        return response()->json($results);
    }
}
