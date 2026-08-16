<?php
namespace App\Http\Controllers\DropAuto\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PriceController extends Controller
{
    public function show(Request $request, string $skuName)
    {
        $supplierId = $request->attributes->get('supplier_id');
        $p = Product::where('supplier_id', $supplierId)->where('sku', $skuName)->first();
        if (!$p) {
            return response()->json(['erro' => 'Produto não encontrado'], 404);
        }

        return response()->json([
            'sku'    => $p->sku,
            'produto'=> $p->name,
            'preco'  => $p->price !== null ? (float) $p->price : null,
            'custo'  => $p->cost  !== null ? (float) $p->cost  : null,
        ]);
    }

    public function update(Request $request, string $skuName)
    {
        $supplierId = $request->attributes->get('supplier_id');
        $p = Product::where('supplier_id', $supplierId)->where('sku', $skuName)->first();
        if (!$p) {
            return response()->json(['erro' => 'Produto não encontrado'], 404);
        }

        $v = Validator::make($request->all(), [
            'price' => ['required', 'numeric', 'min:0'],
        ]);
        if ($v->fails()) {
            return response()->json(['erro' => 'Payload inválido', 'detalhes' => $v->errors()], 422);
        }

        $p->price = (float) $request->input('price');
        $p->save();

        return response()->json([
            'sku'   => $p->sku,
            'preco' => (float) $p->price,
        ]);
    }
}
