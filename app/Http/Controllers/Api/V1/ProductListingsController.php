<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * GET /api/v1/products/listings?client_id={id}
 *
 * Retorna quais SKUs do cliente estao publicados em quais marketplaces.
 * Sem autenticacao Sanctum — client_id e passado via query string.
 */
class ProductListingsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id' => 'required|integer|min:1',
        ]);

        $clientId = (int) $validated['client_id'];

        $listings = DB::table('product_listings')
            ->where('client_id', $clientId)
            ->orderBy('id', 'desc')
            ->get(['sku_codigo', 'marketplace', 'item_id', 'listing_url', 'status', 'created_at']);

        Log::info('[ProductListingsController] Buscando listings', [
            'client_id' => $clientId,
            'count'     => $listings->count(),
        ]);

        return response()->json([
            'success'  => true,
            'listings' => $listings->map(fn ($l) => [
                'sku_codigo'  => $l->sku_codigo,
                'marketplace' => $l->marketplace,
                'item_id'     => $l->item_id,
                'listing_url' => $l->listing_url,
                'status'      => $l->status,
                'created_at'  => $l->created_at,
            ])->values(),
        ]);
    }
}
