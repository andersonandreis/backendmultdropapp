<?php
namespace App\Http\Controllers\DropAuto\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class InvoiceController extends Controller
{
    private function findOrder(int $supplierId, string $pedido): ?Order
    {
        return Order::where('supplier_id', $supplierId)
            ->where(function ($q) use ($pedido) {
                $q->where('order_number', $pedido)
                  ->orWhere('external_order_id', $pedido);
            })
            ->first();
    }

    /** GET /api/v1/invoice/entrada/{pedido} — NF emitida para o vendedor */
    public function entrada(Request $request, string $pedido)
    {
        $supplierId = $request->attributes->get('supplier_id');
        $order = $this->findOrder($supplierId, $pedido);
        if (!$order) {
            return response()->json(['erro' => 'Pedido não encontrado'], 404);
        }

        return response()->json([
            'nr_pedido' => $order->order_number,
            'nf'        => [
                'numero'     => $order->invoice_number,
                'serie'      => $order->invoice_series,
                'chave'      => $order->invoice_access_key,
                'danfe'      => $order->invoice_url,
                'xml'        => $order->invoice_xml_url,
                'status'     => $order->invoice_status,
                'emitido_em' => $order->invoice_issued_at?->toIso8601String(),
            ],
        ]);
    }

    /** PUT /api/v1/invoice/entrada/{pedido} — atualiza NF do fornecedor */
    public function updateEntrada(Request $request, string $pedido)
    {
        $supplierId = $request->attributes->get('supplier_id');
        $order = $this->findOrder($supplierId, $pedido);
        if (!$order) {
            return response()->json(['erro' => 'Pedido não encontrado'], 404);
        }

        $v = Validator::make($request->all(), [
            'numero' => ['nullable', 'string', 'max:50'],
            'serie'  => ['nullable', 'string', 'max:10'],
            'xml'    => ['nullable', 'string'],
            'chave'  => ['nullable', 'string', 'max:60'],
            'danfe'  => ['nullable', 'url', 'max:500'],
        ]);
        if ($v->fails()) {
            return response()->json(['erro' => 'Dados inválidos', 'detalhes' => $v->errors()], 422);
        }

        $updates = ['updated_at' => now()];
        if ($request->filled('numero')) $updates['invoice_number']     = $request->input('numero');
        if ($request->filled('serie'))  $updates['invoice_series']     = $request->input('serie');
        if ($request->filled('xml'))    $updates['invoice_xml']        = $request->input('xml');
        if ($request->filled('chave'))  $updates['invoice_access_key'] = $request->input('chave');
        if ($request->filled('danfe'))  $updates['invoice_url']        = $request->input('danfe');

        if (count($updates) > 1) {
            DB::table('orders')->where('id', $order->id)->update($updates);
        }

        $order->refresh();
        return response()->json([
            'nr_pedido' => $order->order_number,
            'nf'        => [
                'numero'  => $order->invoice_number,
                'serie'   => $order->invoice_series,
                'chave'   => $order->invoice_access_key,
                'danfe'   => $order->invoice_url,
                'status'  => $order->invoice_status,
            ],
        ]);
    }

    /** GET /api/v1/invoice/saida/{pedido} — NF de saída para rastreio */
    public function saida(Request $request, string $pedido)
    {
        $supplierId = $request->attributes->get('supplier_id');
        $order = $this->findOrder($supplierId, $pedido);
        if (!$order) {
            return response()->json(['erro' => 'Pedido não encontrado'], 404);
        }

        return response()->json([
            'nr_pedido'   => $order->order_number,
            'rastreio'    => $order->tracking_number,
            'transportadora' => $order->carrier_name,
            'nf_saida'    => [
                'chave'   => $order->invoice_access_key,
                'danfe'   => $order->invoice_url,
                'xml_url' => $order->invoice_xml_url,
            ],
        ]);
    }
}
