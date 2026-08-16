<?php

namespace App\Http\Controllers\TenantApi\V1;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        // Por enquanto: todos suppliers ativos. Quando "default_supplier_visibility=scoped"
        // for implementado, filtrar pelos vinculos do tenant via client_supplier.
        $rows = Supplier::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->limit(500)
            ->get(['id', 'company_name', 'legacy_id', 'legacy_empresa_id', 'document']);

        return response()->json([
            'data' => $rows->map(fn($s) => [
                'id'                 => $s->id,
                'name'               => $s->company_name,
                'document'           => $s->document,
                'legacy_id'          => $s->legacy_id,
                'legacy_deposito_id' => $s->legacy_empresa_id, // renomeado pra evitar confusao
            ])->values(),
        ]);
    }
}
