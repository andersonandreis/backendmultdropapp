<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fase 5 — listagem de notas fiscais.
 *
 * Nao temos tabela de NF separada porque a NF emitida e armazenada
 * direto em `orders.invoice_*` (Fase 1). Esse endpoint serve a pagina
 * `/notas-fiscais` listando todos os pedidos com NF emitida.
 */
class InvoiceController extends Controller
{
    private function clientOrFail(Request $request)
    {
        $client = $request->user()->client;
        if (!$client) abort(403, 'Usuario nao possui perfil de lojista.');
        return $client;
    }

    public function index(Request $request): JsonResponse
    {
        $client = $this->clientOrFail($request);
        $perPage = (int) $request->query('per_page', 25);

        $query = Order::where('client_id', $client->id)
            ->whereNotNull('invoice_number')
            ->where('invoice_number', '!=', '')
            ->orderByDesc('invoice_issued_at')
            ->orderByDesc('id');

        if ($request->filled('search')) {
            $s = '%' . $request->query('search') . '%';
            $query->where(function ($q) use ($s) {
                $q->where('invoice_number', 'like', $s)
                  ->orWhere('invoice_access_key', 'like', $s)
                  ->orWhere('external_order_id', 'like', $s)
                  ->orWhere('customer_name', 'like', $s);
            });
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())->map(function ($o) {
                return [
                    'order_id'           => $o->id,
                    'order_number'       => $o->order_number,
                    'external_order_id'  => $o->external_order_id,
                    'invoice_number'     => $o->invoice_number,
                    'invoice_series'     => $o->invoice_series,
                    'invoice_access_key' => $o->invoice_access_key,
                    'invoice_status'     => $o->invoice_status,
                    'invoice_issued_at'  => $o->invoice_issued_at,
                    'customer_name'      => $o->customer_name,
                    'total'              => $o->total,
                    'has_xml'            => !empty($o->invoice_xml),
                    'created_at'         => $o->created_at,
                ];
            }),
            'meta' => [
                'total'        => $paginator->total(),
                'per_page'     => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }
}
