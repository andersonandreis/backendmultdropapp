<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * NOV-121 — Dashboard operacional do fornecedor (substitui dashboard.php legado).
 *
 * GET /api/v1/supplier/dashboard-stats?period=today|week|month
 *
 * Resposta:
 *   {
 *     orders: { today/week/month: {count, by_status: {pending,paid,shipped,...}} },
 *     revenue: { today/week/month: number },
 *     low_stock: [ {product_id, sku, name, quantity, threshold}, ... ] (max 10),
 *     top_products: [ {product_id, sku, name, qty_sold, revenue}, ... ] (max 10)
 *   }
 *
 * Cache: 5 min por supplier_id.
 */
class SupplierDashboardController extends Controller
{
    public function stats(Request $request): JsonResponse
    {
        $request->validate([
            'period' => 'sometimes|string|in:today,week,month',
        ]);

        $user = $request->user();
        $supplierId = $this->resolveSupplierId($user);

        $cacheKey = "dashboard_stats:{$supplierId}";
        $data = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($supplierId) {
            return [
                'orders'       => $this->orderStats($supplierId),
                'revenue'      => $this->revenueStats($supplierId),
                'low_stock'    => $this->lowStock($supplierId),
                'top_products' => $this->topProducts($supplierId),
                'generated_at' => now()->toIso8601String(),
            ];
        });

        return response()->json($data);
    }

    private function resolveSupplierId($user): ?int
    {
        if (!$user) {
            return null;
        }
        if ($user->role === 'supplier' && $user->supplier_id ?? null) {
            return (int) $user->supplier_id;
        }
        // super_admin → todos
        return null;
    }

    private function orderStats(?int $supplierId): array
    {
        $base = Order::query();
        if ($supplierId) {
            $base->where('supplier_id', $supplierId);
        }

        $periods = [
            'today' => now()->startOfDay(),
            'week'  => now()->startOfWeek(),
            'month' => now()->startOfMonth(),
        ];

        $out = [];
        foreach ($periods as $key => $start) {
            $byStatus = (clone $base)
                ->where('created_at', '>=', $start)
                ->selectRaw('status, COUNT(*) as c')
                ->groupBy('status')
                ->pluck('c', 'status');

            $out[$key] = [
                'count'     => $byStatus->sum(),
                'by_status' => $byStatus,
            ];
        }

        return $out;
    }

    private function revenueStats(?int $supplierId): array
    {
        $base = Order::query()->whereIn('status', ['paid', 'shipped', 'delivered', 'completed']);
        if ($supplierId) {
            $base->where('supplier_id', $supplierId);
        }

        return [
            'today' => (float) (clone $base)->where('created_at', '>=', now()->startOfDay())->sum('subtotal'),
            'week'  => (float) (clone $base)->where('created_at', '>=', now()->startOfWeek())->sum('subtotal'),
            'month' => (float) (clone $base)->where('created_at', '>=', now()->startOfMonth())->sum('subtotal'),
        ];
    }

    private function lowStock(?int $supplierId): array
    {
        $q = Inventory::query()
            ->withoutGlobalScopes()
            ->whereNotNull('stock_alert_threshold')
            ->whereColumn('quantity', '<', 'stock_alert_threshold')
            ->with(['product:id,sku,name']);

        if ($supplierId) {
            $q->where('producer_id', $supplierId);
        }

        return $q->limit(10)->get()->map(fn ($i) => [
            'product_id' => $i->product_id,
            'sku'        => $i->product?->sku,
            'name'       => $i->product?->name,
            'quantity'   => (int) $i->quantity,
            'threshold'  => (int) $i->stock_alert_threshold,
        ])->all();
    }

    private function topProducts(?int $supplierId): array
    {
        $q = OrderItem::query()
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->whereIn('orders.status', ['paid', 'shipped', 'delivered', 'completed'])
            ->where('orders.created_at', '>=', now()->startOfMonth());

        if ($supplierId) {
            $q->where('orders.supplier_id', $supplierId);
        }

        return $q->selectRaw('products.id as product_id, products.sku, products.name, SUM(order_items.quantity) as qty_sold, SUM(order_items.unit_price * order_items.quantity) as revenue')
            ->groupBy('products.id', 'products.sku', 'products.name')
            ->orderByDesc('qty_sold')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'product_id' => (int) $r->product_id,
                'sku'        => $r->sku,
                'name'       => $r->name,
                'qty_sold'   => (int) $r->qty_sold,
                'revenue'    => (float) $r->revenue,
            ])
            ->all();
    }
}
