<?php
namespace App\Http\Controllers\DropAuto\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SkuController extends Controller
{
    public function index(Request $request)
    {
        $supplierId = $request->attributes->get('supplier_id');
        $page  = max(1, (int) $request->query('page', 1));
        $limit = 50;

        $rows = Product::where('supplier_id', $supplierId)
            ->where('is_active', true)
            ->with([
                'inventory' => fn($q) => $q->select(['product_id', 'quantity']),
                'media'     => fn($q) => $q->whereNull('product_variation_id')->orderBy('position'),
            ])
            ->orderBy('id')
            ->skip(($page - 1) * $limit)
            ->take($limit)
            ->get();

        $total = Product::where('supplier_id', $supplierId)->where('is_active', true)->count();

        return response()->json([
            'skus'         => $rows->map(fn($p) => $this->shape($p))->values(),
            'total'        => $total,
            'pagina'       => $page,
            'por_pagina'   => $limit,
            'ultima_pagina'=> (int) ceil($total / $limit),
        ]);
    }

    public function show(Request $request, string $skuName)
    {
        $supplierId = $request->attributes->get('supplier_id');
        $p = Product::where('supplier_id', $supplierId)
            ->where('sku', $skuName)
            ->with([
                'inventory' => fn($q) => $q->select(['product_id', 'quantity']),
                'media'     => fn($q) => $q->whereNull('product_variation_id')->orderBy('position'),
            ])
            ->first();

        if (!$p) {
            return response()->json(['erro' => 'Produto não encontrado'], 404);
        }
        return response()->json(['sku' => $this->shape($p, true)]);
    }

    public function store(Request $request)
    {
        $supplierId = $request->attributes->get('supplier_id');

        $v = Validator::make($request->all(), [
            'sku'         => ['required', 'string', 'max:100'],
            'produto'     => ['required', 'string', 'max:500'],
            'img_inicial' => ['required', 'url'],
            'custo'       => ['required', 'numeric', 'min:0'],
            'peso'        => ['required', 'numeric', 'min:0'],
            'largura'     => ['required', 'numeric', 'min:0'],
            'altura'      => ['required', 'numeric', 'min:0'],
            'comprimento' => ['required', 'numeric', 'min:0'],
            'descricao'   => ['required', 'string'],
            'ean'         => ['required', 'string', 'max:20'],
            'origem'      => ['required', 'string', 'max:5'],
            'ncm'         => ['required', 'string', 'max:20'],
            'marca'       => ['required', 'string', 'max:100'],
            'garantia'    => ['required', 'numeric', 'min:0'],
            'imgs'        => ['nullable', 'array'],
            'imgs.*'      => ['url'],
        ]);
        if ($v->fails()) {
            return response()->json(['erro' => 'Dados inválidos', 'detalhes' => $v->errors()], 422);
        }

        if (Product::where('supplier_id', $supplierId)->where('sku', $request->input('sku'))->exists()) {
            return response()->json(['erro' => 'SKU já cadastrado'], 409);
        }

        $p = Product::create([
            'supplier_id'    => $supplierId,
            'sku'            => $request->input('sku'),
            'name'           => $request->input('produto'),
            'cost'           => $request->input('custo'),
            'description'    => $request->input('descricao'),
            'ean'            => $request->input('ean'),
            'gtin'           => $request->input('ean'),
            'origem'         => $request->input('origem'),
            'ncm'            => $request->input('ncm'),
            'brand'          => $request->input('marca'),
            'warranty_months'=> (int) $request->input('garantia'),
            'weight_kg'      => (float) $request->input('peso'),
            'width_cm'       => round((float) $request->input('largura') * 100, 2),
            'height_cm'      => round((float) $request->input('altura') * 100, 2),
            'length_cm'      => round((float) $request->input('comprimento') * 100, 2),
            'is_active'      => true,
            'condition'      => 'new',
        ]);

        $position = 0;
        $allImages = array_merge([$request->input('img_inicial')], $request->input('imgs', []));
        foreach ($allImages as $url) {
            DB::table('product_media')->insert([
                'product_id' => $p->id,
                'url'        => $url,
                'position'   => $position,
                'is_cover'   => $position === 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $position++;
        }

        return response()->json(['sku' => $this->shape($p)], 201);
    }

    public function update(Request $request, string $skuName)
    {
        $supplierId = $request->attributes->get('supplier_id');
        $p = Product::where('supplier_id', $supplierId)->where('sku', $skuName)->first();
        if (!$p) {
            return response()->json(['erro' => 'Produto não encontrado'], 404);
        }

        $campos = ['produto' => 'name', 'descricao' => 'description', 'ean' => 'ean',
                   'origem' => 'origem', 'ncm' => 'ncm', 'marca' => 'brand'];
        foreach ($campos as $from => $to) {
            if ($request->filled($from)) $p->$to = $request->input($from);
        }
        if ($request->filled('peso'))        $p->weight_kg    = (float) $request->input('peso');
        if ($request->filled('largura'))     $p->width_cm     = round((float) $request->input('largura') * 100, 2);
        if ($request->filled('altura'))      $p->height_cm    = round((float) $request->input('altura') * 100, 2);
        if ($request->filled('comprimento')) $p->length_cm    = round((float) $request->input('comprimento') * 100, 2);
        if ($request->filled('garantia'))    $p->warranty_months = (int) $request->input('garantia');
        if ($request->filled('img_inicial')) {
            DB::table('product_media')->where('product_id', $p->id)->where('is_cover', true)
                ->update(['url' => $request->input('img_inicial'), 'updated_at' => now()]);
        }
        if ($request->has('imgs') && is_array($request->input('imgs'))) {
            DB::table('product_media')->where('product_id', $p->id)->where('is_cover', false)->delete();
            foreach ($request->input('imgs') as $idx => $url) {
                DB::table('product_media')->insert([
                    'product_id' => $p->id, 'url' => $url,
                    'position' => $idx + 1, 'is_cover' => false,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        $p->save();
        return response()->json(null, 204);
    }

    public function destroy(Request $request, string $skuName)
    {
        $supplierId = $request->attributes->get('supplier_id');
        $p = Product::where('supplier_id', $supplierId)->where('sku', $skuName)->first();
        if (!$p) return response()->json(['erro' => 'Produto não encontrado'], 404);

        $temItens = DB::table('order_items')->where('sku', $skuName)->exists();
        if ($temItens) {
            return response()->json(['erro' => 'Produto possui pedidos vinculados e não pode ser removido'], 409);
        }
        DB::table('product_media')->where('product_id', $p->id)->delete();
        DB::table('inventory')->where('product_id', $p->id)->delete();
        $p->delete();
        return response()->json(null, 204);
    }

    private function shape(Product $p, bool $detail = false): array
    {
        $cover = $p->relationLoaded('media') ? $p->media->firstWhere('is_cover', true) : null;
        $stock = $p->relationLoaded('inventory') ? (int) $p->inventory->sum('quantity') : 0;

        $base = [
            'sku'           => $p->sku,
            'produto'       => $p->name,
            'img_inicial'   => $cover?->url,
            'custo'         => $p->cost  !== null ? (float) $p->cost  : null,
            'preco'         => $p->price !== null ? (float) $p->price : null,
            'estoque'       => $stock,
            'peso'          => $p->weight_kg  !== null ? (float) $p->weight_kg : null,
            'largura'       => $p->width_cm   !== null ? round($p->width_cm / 100, 4) : null,
            'altura'        => $p->height_cm  !== null ? round($p->height_cm / 100, 4) : null,
            'comprimento'   => $p->length_cm  !== null ? round($p->length_cm / 100, 4) : null,
            'ean'           => $p->ean,
            'origem'        => $p->origem,
            'ncm'           => $p->ncm,
            'marca'         => $p->brand,
            'garantia'      => $p->warranty_months,
            'ativo'         => (bool) $p->is_active,
            'atualizado_em' => $p->updated_at?->toIso8601String(),
        ];

        if ($detail) {
            $base['descricao'] = $p->description;
            $base['imgs'] = $p->relationLoaded('media')
                ? $p->media->where('is_cover', false)->pluck('url')->values()
                : [];
        }
        return $base;
    }
}
