<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\RepricingCostConfig;
use App\Services\Repricing\RepricingCalculatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * NOV-127: CRUD de configurações de repricing por marketplace.
 * Escopo: supplier_id do usuário autenticado (via TenantSupplierScope no Model).
 */
class RepricingCostConfigController extends Controller
{
    public function __construct(private RepricingCalculatorService $calculator) {}

    /** GET /api/v1/supplier/repricing-configs */
    public function index(): JsonResponse
    {
        $configs = RepricingCostConfig::with('supplier:id,display_name')
            ->orderBy('marketplace')
            ->orderBy('product_category')
            ->get();

        return response()->json($configs);
    }

    /** POST /api/v1/supplier/repricing-configs */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'marketplace'         => 'required|string|max:50',
            'product_category'    => 'nullable|string|max:100',
            'shipping_cost_pct'   => 'required|numeric|min:0|max:100',
            'marketplace_fee_pct' => 'required|numeric|min:0|max:100',
            'desired_margin_pct'  => 'required|numeric|min:0|max:95',
            'extra_cost_fixed'    => 'nullable|numeric|min:0',
            'active'              => 'boolean',
        ]);

        $supplierId = Auth::user()?->supplier?->id;
        if (!$supplierId) {
            return response()->json(['error' => 'supplier_not_found'], 422);
        }

        $config = RepricingCostConfig::create(array_merge($data, ['supplier_id' => $supplierId]));

        return response()->json($config, 201);
    }

    /** GET /api/v1/supplier/repricing-configs/{id} */
    public function show(int $id): JsonResponse
    {
        $config = RepricingCostConfig::findOrFail($id);

        return response()->json($config);
    }

    /** PUT /api/v1/supplier/repricing-configs/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        $config = RepricingCostConfig::findOrFail($id);

        $data = $request->validate([
            'marketplace'         => 'sometimes|string|max:50',
            'product_category'    => 'nullable|string|max:100',
            'shipping_cost_pct'   => 'sometimes|numeric|min:0|max:100',
            'marketplace_fee_pct' => 'sometimes|numeric|min:0|max:100',
            'desired_margin_pct'  => 'sometimes|numeric|min:0|max:95',
            'extra_cost_fixed'    => 'nullable|numeric|min:0',
            'active'              => 'boolean',
        ]);

        $config->update($data);

        return response()->json($config);
    }

    /** DELETE /api/v1/supplier/repricing-configs/{id} */
    public function destroy(int $id): JsonResponse
    {
        RepricingCostConfig::findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    /** POST /api/v1/supplier/repricing-configs/calculate */
    public function calculate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id'  => 'required|integer|exists:products,id',
            'marketplace' => 'required|string|max:50',
            'unit_cost'   => 'nullable|numeric|min:0',
        ]);

        $product = Product::findOrFail($data['product_id']);
        $result  = $this->calculator->calculateWithCosts(
            $product,
            $data['marketplace'],
            $data['unit_cost'] ?? null,
        );

        return response()->json($result);
    }
}
