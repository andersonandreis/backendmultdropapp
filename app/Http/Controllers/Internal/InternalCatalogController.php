<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * NOV-058-C — Endpoint interno para proxy de catalogo de WLs (ex: MEStoreDrop).
 *
 * Autenticado via X-Internal-Key (InternalKeyMiddleware).
 * O tenant e identificado pelo header X-Tenant-Slug.
 *
 * GET /internal/catalog/products?per_page=15&search=...
 */
class InternalCatalogController extends Controller
{
    public function products(Request $request): \Illuminate\Http\JsonResponse
    {
        $slug = $request->header('X-Tenant-Slug');

        if (!$slug) {
            return response()->json(['error' => 'X-Tenant-Slug header obrigatorio'], 400);
        }

        $tenant = Tenant::where('slug', $slug)->where('status', 'active')->first();
        if (!$tenant) {
            return response()->json(['error' => 'Tenant nao encontrado ou inativo'], 404);
        }

        // Fornecedores acessiveis para este tenant
        $supplierIds = DB::table('tenant_supplier')
            ->where('tenant_id', $tenant->id)
            ->pluck('supplier_id')
            ->toArray();

        // Se o tenant nao tiver suppliers especificos, usa os publicos (is_private = false)
        $query = Product::where('is_active', true)
            ->with([
                'media' => function ($q) {
                    // FOR-099: despriorizar img_temp (legado sync) em favor de URLs CDN reais
                    $q->select('id', 'product_id', 'url', 'type', 'is_cover', 'position')
                      ->orderByRaw("CASE WHEN url LIKE '%img_temp%' THEN 1 ELSE 0 END ASC")
                      ->orderByDesc('is_cover')
                      ->orderBy('position')
                      ->orderBy('id');
                },
                'inventory:id,product_id,quantity',
            ]);

        if (!empty($supplierIds)) {
            $query->whereIn('supplier_id', $supplierIds);
        } else {
            // Fallback: produtos de fornecedores publicos
            $publicSupplierIds = Supplier::where('is_active', true)
                ->where('is_private', false)
                ->pluck('id')
                ->toArray();
            $query->whereIn('supplier_id', $publicSupplierIds);
        }

        // Filtros opcionais
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%")
                  ->orWhere('ean', 'like', "%{$search}%");
            });
        }

        if ($brand = $request->query('brand')) {
            $query->where('brand', $brand);
        }

        if ($categoryId = $request->query('category_id')) {
            $query->where('category_id', (int) $categoryId);
        }

        if ($priceMin = $request->query('price_min')) {
            $query->where('price', '>=', (float) $priceMin);
        }

        if ($priceMax = $request->query('price_max')) {
            $query->where('price', '<=', (float) $priceMax);
        }

        if ($request->query('in_stock') === '1') {
            $query->whereHas('inventory', function ($q) {
                $q->where('quantity', '>', 0);
            });
        }

        $perPage = min((int) $request->query('per_page', 15), 100);

        $sortField = in_array($request->query('sort'), ['price', 'name', 'created_at'])
            ? $request->query('sort')
            : 'created_at';
        $sortDir = $request->query('sort_dir', 'desc') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortField, $sortDir);

        $products = $query->paginate($perPage);

        return response()->json([
            'data' => $products->items(),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page'    => $products->lastPage(),
                'per_page'     => $products->perPage(),
                'total'        => $products->total(),
                'tenant_slug'  => $slug,
            ],
        ]);
    }

    public function product(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $slug = $request->header('X-Tenant-Slug');

        if (!$slug) {
            return response()->json(['error' => 'X-Tenant-Slug header obrigatorio'], 400);
        }

        $tenant = Tenant::where('slug', $slug)->where('status', 'active')->first();
        if (!$tenant) {
            return response()->json(['error' => 'Tenant nao encontrado ou inativo'], 404);
        }

        $product = Product::where('id', $id)
            ->where('is_active', true)
            ->with([
                'media' => function ($q) {
                    // FOR-099: despriorizar img_temp (legado sync) em favor de URLs CDN reais
                    $q->select('id', 'product_id', 'url', 'type', 'is_cover', 'position')
                      ->orderByRaw("CASE WHEN url LIKE '%img_temp%' THEN 1 ELSE 0 END ASC")
                      ->orderByDesc('is_cover')
                      ->orderBy('position')
                      ->orderBy('id');
                },
                'inventory:id,product_id,quantity',
                'variations',
            ])
            ->first();

        if (!$product) {
            return response()->json(['error' => 'Produto nao encontrado'], 404);
        }

        return response()->json(['data' => $product]);
    }
}
