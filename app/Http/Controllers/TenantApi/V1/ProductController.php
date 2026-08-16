<?php

namespace App\Http\Controllers\TenantApi\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $q = Product::query()->where('is_active', true)->orderBy('id');

        if ($sku = $request->query('sku')) {
            $q->where('sku', $sku);
        }
        if ($supplierId = $request->query('supplier_id')) {
            $q->where('supplier_id', (int) $supplierId);
        }
        $limit = (int) min(max((int) $request->query('limit', 50), 1), 200);
        $cursor = (int) $request->query('cursor', 0);
        if ($cursor > 0) {
            $q->where('id', '>', $cursor);
        }

        $rows = $q->limit($limit + 1)->get([
            'id', 'sku', 'name', 'supplier_id',
            'price', 'cost',
            'weight_kg', 'height_cm', 'width_cm', 'length_cm',
            'ncm', 'ean', 'gtin', 'brand', 'model',
            'condition', 'warranty_type', 'warranty_days',
            'is_active', 'created_at', 'updated_at',
        ]);

        $next = null;
        if ($rows->count() > $limit) {
            $next = (string) $rows[$limit - 1]->id;
            $rows = $rows->take($limit);
        }

        $rows->load([
            'inventory' => fn($q) => $q->select(['product_id', 'quantity']),
        ]);

        return response()->json([
            'data'        => $rows->map(fn($p) => $this->shape($p))->values(),
            'next_cursor' => $next,
        ]);
    }

    public function show(Request $request, string $sku)
    {
        $p = Product::with([
            'inventory' => fn($q) => $q->select(['product_id', 'quantity']),
            'media'     => fn($q) => $q->whereNull('product_variation_id')->orderBy('position'),
        ])->where('sku', $sku)->where('is_active', true)->first();

        if (!$p) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->json(['data' => $this->shape($p, detail: true)]);
    }

    private function shape(Product $p, bool $detail = false): array
    {
        $base = [
            'id'             => $p->id,
            'sku'            => $p->sku,
            'name'           => $p->name,
            'supplier_id'    => $p->supplier_id,
            'stock'          => (int) $p->effective_stock,
            'price'          => $p->price !== null ? (float) $p->price : null,
            'cost'           => $p->cost  !== null ? (float) $p->cost  : null,
            'weight_kg'      => $p->weight_kg  !== null ? (float) $p->weight_kg  : null,
            'height_cm'      => $p->height_cm  !== null ? (float) $p->height_cm  : null,
            'width_cm'       => $p->width_cm   !== null ? (float) $p->width_cm   : null,
            'length_cm'      => $p->length_cm  !== null ? (float) $p->length_cm  : null,
            'ncm'            => $p->ncm,
            'ean'            => $p->ean,
            'gtin'           => $p->gtin,
            'brand'          => $p->brand,
            'model'          => $p->model,
            'condition'      => $p->condition,
            'warranty_type'  => $p->warranty_type,
            'warranty_days'  => $p->warranty_days,
            'is_active'      => (bool) $p->is_active,
            'created_at'     => $p->created_at?->toIso8601String(),
            'updated_at'     => $p->updated_at?->toIso8601String(),
        ];

        if ($detail) {
            $base['description']      = $p->description;
            $base['ai_title']         = $p->ai_title;
            $base['ai_description']   = $p->ai_description;
            $base['ai_bullet_points'] = $p->ai_bullet_points;
            $base['attributes']       = $p->attributes;
            $base['images']           = $p->relationLoaded('media')
                ? $p->media->map(fn($m) => [
                    'url'      => $m->url,
                    'position' => $m->position,
                    'is_cover' => $m->is_cover,
                ])->values()
                : [];
        }

        return $base;
    }
}
