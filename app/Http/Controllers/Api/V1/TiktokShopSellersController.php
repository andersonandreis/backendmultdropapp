<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TiktokShopSeller;
use App\Models\TiktokShopSellerProduct;
use Illuminate\Http\Request;

/**
 * SEL-198: Endpoints publicos de sellers do TikTok Shop BR.
 *
 * GET /api/v1/tiktok/sellers
 *   Filtros: category, min_rating, order_by (gmv|rating|followers)
 *   RestrictFreeAccess ja cobre api/v1/tiktok* — nao precisa middleware extra.
 *   Cap free: 30 registros (forcado pelo per_page cap no middleware).
 *
 * GET /api/v1/tiktok/sellers/{handle}/catalog
 *   Retorna produtos do seller pelo handle.
 */
class TiktokShopSellersController extends Controller
{
    public function index(Request $request)
    {
        $q = TiktokShopSeller::query()->where('is_visible', true);

        // Filtro por categoria
        if ($category = $request->query('category')) {
            $q->where('category_l1', 'like', '%' . $category . '%');
        }

        // Filtro por avaliacao minima
        if ($minRating = $request->query('min_rating')) {
            $q->where('avg_rating', '>=', (float) $minRating);
        }

        // Ordenacao
        $orderBy = $request->query('order_by', 'rank_position');
        match ($orderBy) {
            'gmv'       => $q->orderByDesc('gmv_estimate'),
            'rating'    => $q->orderByDesc('avg_rating'),
            'followers' => $q->orderByDesc('followers'),
            default     => $q->orderBy('rank_position')->orderByDesc('gmv_estimate'),
        };

        // Cap free: RestrictFreeAccess middleware ja limita per_page=30
        $perPage = min((int) $request->query('per_page', 30), 200);

        $sellers = $q->select([
            'id', 'handle', 'name', 'avatar_url', 'followers',
            'products_count', 'avg_rating', 'avg_commission_rate', 'gmv_estimate',
            'rank_position', 'category_l1', 'updated_at',
        ])->paginate($perPage);

        return response()->json($sellers);
    }

    public function catalog(Request $request, string $handle)
    {
        $handle = ltrim(trim($handle), '@');

        $seller = TiktokShopSeller::where('handle', $handle)
            ->where('is_visible', true)
            ->select(['id', 'handle', 'name', 'avatar_url', 'avg_rating', 'products_count'])
            ->firstOrFail();

        $products = TiktokShopSellerProduct::where('seller_id', $seller->id)
            ->orderByDesc('sales_count')
            ->limit(50)
            ->get(['id', 'external_product_id', 'product_json', 'sales_count', 'rating', 'price', 'commission_rate', 'affiliate_link']);

        return response()->json([
            'seller'   => $seller,
            'products' => $products,
        ]);
    }
}
