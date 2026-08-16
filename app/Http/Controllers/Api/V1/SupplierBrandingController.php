<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SupplierBranding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** NOV-136 — Endpoint público de branding para o frontend Lovable. */
class SupplierBrandingController extends Controller
{
    /** GET /api/v1/supplier/branding */
    public function show(Request $request): JsonResponse
    {
        $supplierId = null;
        if (auth()->user()?->role === 'supplier') {
            $supplierId = auth()->user()->supplier?->id;
        } elseif ($request->filled('supplier_id')) {
            $supplierId = (int) $request->input('supplier_id');
        }
        if (!$supplierId) {
            return response()->json(['error' => 'missing_supplier'], 400);
        }

        $branding = SupplierBranding::query()
            ->where('supplier_id', $supplierId)
            ->first();

        $defaults = [
            'platform_name'    => 'Loja',
            'logo_url'         => null,
            'favicon_url'      => null,
            'primary_color'    => '#3b82f6',
            'secondary_color'  => '#1e40af',
            'accent_color'     => '#f59e0b',
            'background_color' => '#ffffff',
            'text_color'       => '#111827',
        ];

        return response()->json([
            'data' => $branding ? $branding->toArray() : array_merge($defaults, ['supplier_id' => $supplierId]),
        ]);
    }
}
