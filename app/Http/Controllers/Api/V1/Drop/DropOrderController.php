<?php

namespace App\Http\Controllers\Api\V1\Drop;

use App\Http\Controllers\Controller;
use App\Models\Drop\DropOrder;
use App\Models\Drop\DropSupplierOrder;
use App\Services\Drop\DropOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Drop Internacional - Gestao de pedidos Drop.
 */
class DropOrderController extends Controller
{
    public function __construct(
        private DropOrderService $orderService
    ) {}

    private function clientOrFail(Request $request)
    {
        $client = $request->user()->client;
        if (!$client) {
            abort(403, 'Usuario nao possui perfil de lojista.');
        }
        return $client;
    }

    /**
     * Listar pedidos Drop do cliente (paginados).
     */
    public function index(Request $request): JsonResponse
    {
        $client = $this->clientOrFail($request);

        $query = DropOrder::where('client_id', $client->id)
            ->with(['supplierOrders', 'importedProduct'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('shopify_order_id', 'like', '%' . $request->input('search') . '%')
                  ->orWhere('customer_name', 'like', '%' . $request->input('search') . '%');
            });
        }

        return response()->json($query->paginate((int) $request->input('per_page', 15)));
    }

    /**
     * Detalhe de um pedido Drop especifico.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $client = $this->clientOrFail($request);
        $order  = DropOrder::where('client_id', $client->id)
            ->with(['supplierOrders.trackingUpdates', 'importedProduct'])
            ->findOrFail($id);

        return response()->json(['data' => $order]);
    }

    /**
     * Criar ordem de compra no fornecedor para o pedido indicado.
     */
    public function createSupplierOrder(Request $request, int $id): JsonResponse
    {
        $client = $this->clientOrFail($request);
        $order  = DropOrder::where('client_id', $client->id)->findOrFail($id);

        $validated = $request->validate([
            'supplier_slug' => 'required|string|in:aliexpress,cj,zendrop',
            'product_url'   => 'required|url|max:2000',
            'variant_title' => 'required|string|max:255',
            'cost_paid_usd' => 'required|numeric|min:0',
        ]);

        $supplierOrder = $this->orderService->createSupplierOrder(
            $order,
            $validated['supplier_slug'],
            $validated['product_url'],
            $validated['variant_title'],
            (float) $validated['cost_paid_usd']
        );

        return response()->json(['data' => $supplierOrder], 201);
    }

    /**
     * Registrar rastreio de uma ordem de fornecedor.
     */
    public function registerTracking(Request $request, int $id): JsonResponse
    {
        $client        = $this->clientOrFail($request);
        $order         = DropOrder::where('client_id', $client->id)->findOrFail($id);
        $supplierOrder = DropSupplierOrder::where('drop_order_id', $order->id)->firstOrFail();

        $validated = $request->validate([
            'tracking_code' => 'required|string|max:100',
            'carrier'       => 'required|string|max:100',
        ]);

        $trackingUpdate = $this->orderService->registerTracking(
            $supplierOrder,
            $validated['tracking_code'],
            $validated['carrier']
        );

        return response()->json(['data' => $trackingUpdate], 201);
    }

    /**
     * Cancelar pedido Drop.
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $client = $this->clientOrFail($request);
        $order  = DropOrder::where('client_id', $client->id)->findOrFail($id);

        $order = $this->orderService->cancelOrder($order, $request->input('reason', ''));

        return response()->json(['data' => $order]);
    }
}
