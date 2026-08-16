<?php
namespace App\Http\Controllers\DropAuto\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class StockController extends Controller
{
    public function show(Request $request, string $skuName)
    {
        $supplierId = $request->attributes->get('supplier_id');
        $p = Product::where('supplier_id', $supplierId)->where('sku', $skuName)->first();
        if (!$p) {
            return response()->json(['erro' => 'Produto não encontrado'], 404);
        }

        $stock = DB::table('inventory')->where('product_id', $p->id)->sum('quantity');

        return response()->json([
            'sku'     => $p->sku,
            'produto' => $p->name,
            'estoque' => (int) $stock,
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
            'stock' => ['required', 'integer', 'min:0'],
        ]);
        if ($v->fails()) {
            return response()->json(['erro' => 'Payload inválido', 'detalhes' => $v->errors()], 400);
        }

        $novoEstoque = (int) $request->input('stock');

        // Upsert no primeiro warehouse do supplier
        $warehouseId = DB::table('suppliers')->where('id', $supplierId)->value('id');

        $existing = DB::table('inventory')->where('product_id', $p->id)->first();
        if ($existing) {
            DB::table('inventory')->where('product_id', $p->id)->update([
                'quantity'   => $novoEstoque,
                'updated_at' => now(),
            ]);
        } else {
            DB::table('inventory')->insert([
                'product_id'  => $p->id,
                'warehouse_id'=> $supplierId,
                'quantity'    => $novoEstoque,
                'reserved'    => 0,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        return response()->json([
            'sku'     => $p->sku,
            'estoque' => $novoEstoque,
        ]);
    }
}
