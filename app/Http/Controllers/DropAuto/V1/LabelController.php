<?php
namespace App\Http\Controllers\DropAuto\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LabelController extends Controller
{
    public function show(Request $request, string $pedido)
    {
        $supplierId = $request->attributes->get('supplier_id');
        $order = Order::where('supplier_id', $supplierId)
            ->where(function ($q) use ($pedido) {
                $q->where('order_number', $pedido)
                  ->orWhere('external_order_id', $pedido);
            })
            ->first();

        if (!$order) {
            return response()->json(['erro' => 'Pedido não encontrado'], 404);
        }

        if (!$order->label_url && !$order->manual_label_path) {
            return response()->json(['erro' => 'Etiqueta não disponível para este pedido'], 404);
        }

        // Marca como impressa ao consumir
        if (!$order->label_printed_at) {
            DB::table('orders')->where('id', $order->id)->update([
                'label_printed_at' => now(),
                'updated_at'       => now(),
            ]);
            \App\Jobs\EmitBlingNfeJob::dispatchIfTrigger($order->id, 'label_printed'); // MUL-276
        }

        $url = $order->label_url ?: $order->manual_label_path;

        return response()->json([
            'nr_pedido'   => $order->order_number,
            'etiqueta'    => $url,
            'impresso_em' => $order->label_printed_at?->toIso8601String() ?? now()->toIso8601String(),
        ]);
    }
}
