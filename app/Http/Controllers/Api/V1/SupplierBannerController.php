<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SupplierBanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * NOV-140 — Banners promocionais por supplier (whitelabel).
 *
 * GET /api/v1/supplier/banners          (publico: lista banners ativos para a WL)
 * GET /api/v1/supplier-admin/banners    (admin: lista todos)
 * POST/PUT/DELETE /api/v1/supplier-admin/banners (CRUD)
 */
class SupplierBannerController extends Controller
{
    /**
     * Resolve supplier_id a partir do usuario autenticado ou parametro publico.
     */
    private function resolveSupplierId(Request $request): ?int
    {
        $user = $request->user();
        if ($user && in_array($user->role, ['supplier', 'admin'])) {
            return $user->supplier?->id;
        }
        if ($user && $user->role === 'super_admin') {
            $tenantSlug = $request->header('X-Tenant-Slug');
            if ($tenantSlug) {
                $id = \DB::table('tenant_supplier')
                    ->join('tenants', 'tenants.id', '=', 'tenant_supplier.tenant_id')
                    ->where('tenants.slug', $tenantSlug)
                    ->orderBy('tenant_supplier.supplier_id')
                    ->value('tenant_supplier.supplier_id');
                if ($id) return (int) $id;
            }
        }
        if ($request->filled('supplier_id')) {
            return (int) $request->input('supplier_id');
        }
        return null;
    }

    /** GET /api/v1/supplier/banners — publico para o frontend WL. */
    public function publicIndex(Request $request): JsonResponse
    {
        $supplierId = $this->resolveSupplierId($request);
        if (!$supplierId) {
            return response()->json(['data' => []]);
        }

        $banners = SupplierBanner::withoutTenantSupplierScope()
            ->where('supplier_id', $supplierId)
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $banners]);
    }

    /** GET /api/v1/supplier-admin/banners */
    public function adminIndex(Request $request): JsonResponse
    {
        $supplierId = $this->resolveSupplierId($request);
        if (!$supplierId) abort(403, 'supplier_nao_resolvido');

        $banners = SupplierBanner::withoutTenantSupplierScope()
            ->where('supplier_id', $supplierId)
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $banners]);
    }

    /** POST /api/v1/supplier-admin/banners */
    public function store(Request $request): JsonResponse
    {
        $supplierId = $this->resolveSupplierId($request);
        if (!$supplierId) abort(403, 'supplier_nao_resolvido');

        $validated = $request->validate([
            'title'      => 'required|string|max:255',
            'url'        => 'nullable|string|max:500',
            'image_url'  => 'required|string|max:1000',
            'active'     => 'boolean',
            'sort_order' => 'integer',
        ]);
        $validated['supplier_id'] = $supplierId;

        $banner = SupplierBanner::create($validated);

        return response()->json(['data' => $banner], 201);
    }

    /** PUT /api/v1/supplier-admin/banners/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        $supplierId = $this->resolveSupplierId($request);
        if (!$supplierId) abort(403, 'supplier_nao_resolvido');

        $banner = SupplierBanner::withoutTenantSupplierScope()
            ->where('supplier_id', $supplierId)
            ->findOrFail($id);

        $validated = $request->validate([
            'title'      => 'sometimes|string|max:255',
            'url'        => 'nullable|string|max:500',
            'image_url'  => 'sometimes|string|max:1000',
            'active'     => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer',
        ]);

        $banner->update($validated);

        return response()->json(['data' => $banner->fresh()]);
    }

    /** DELETE /api/v1/supplier-admin/banners/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $supplierId = $this->resolveSupplierId($request);
        if (!$supplierId) abort(403, 'supplier_nao_resolvido');

        $banner = SupplierBanner::withoutTenantSupplierScope()
            ->where('supplier_id', $supplierId)
            ->findOrFail($id);

        $banner->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }
}
