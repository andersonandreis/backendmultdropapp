<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * NOV-117 — Endpoint REST de histórico de movimentação de estoque por produto.
 *
 * GET /api/v1/products/{product}/inventory-movements
 *   ?marketplace=ml|shopee|bling|manual
 *   ?type=entrada|saida|ajuste|venda|devolucao|zerar|sync_marketplace
 *   ?from=YYYY-MM-DD
 *   ?to=YYYY-MM-DD
 *   ?per_page=50 (default 50, max 200)
 *
 * Auth: Sanctum + tenant. Scope automático via TenantSupplierScope no model.
 */
class InventoryMovementController extends Controller
{
    public function index(Request $request, Product $product): JsonResponse
    {
        $request->validate([
            'marketplace' => 'sometimes|string|in:ml,shopee,bling,manual',
            'type'        => 'sometimes|string',
            'from'        => 'sometimes|date',
            'to'          => 'sometimes|date',
            'per_page'    => 'sometimes|integer|min:1|max:200',
        ]);

        $query = InventoryMovement::query()
            ->with(['user:id,name', 'inventory:id,warehouse_id,product_id', 'variation:id,sku'])
            ->where('product_id', $product->id)
            ->latest('created_at');

        if ($mp = $request->query('marketplace')) {
            $query->where('marketplace', $mp);
        }

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        if ($from = $request->query('from')) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        $perPage = (int) $request->query('per_page', 50);

        return response()->json($query->paginate($perPage));
    }
}
