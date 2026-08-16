<?php
namespace App\Http\Controllers\DropAuto\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    // Mapeamento status Goolhub → canonical_status interno
    const TO_CANONICAL = [
        'ABERTO'            => 'created',
        'ETIQUETA_IMPRESSA' => 'paid',
        'EMBALADO'          => 'processing',
        'AGUARDANDO_COLETA' => 'processing',
        'ENVIADO'           => 'shipped',
        'CANCELADO'         => 'cancelled',
        'RETORNADO'         => 'returned',
    ];

    // Mapeamento canonical_status interno → status Goolhub
    const TO_GOOLHUB = [
        'created'         => 'ABERTO',
        'pending'         => 'ABERTO',
        'pending_payment' => 'ABERTO',
        'paid'            => 'ETIQUETA_IMPRESSA',
        'processing'      => 'EMBALADO',
        'shipped'         => 'ENVIADO',
        'delivered'       => 'ENVIADO',
        'completed'       => 'ENVIADO',
        'cancelled'       => 'CANCELADO',
        'returned'        => 'RETORNADO',
        'refunded'        => 'CANCELADO',
    ];

    public function index(Request $request)
    {
        $supplierId = $request->attributes->get('supplier_id');
        $page  = max(1, (int) $request->query('page', 1));
        $limit = 20;

        // Somente pedidos com pelo menos 1 item de SKU D20- (catálogo Drop Auto Peças)
        $skuPrefix = 'D20-%';
        $base = Order::where('supplier_id', $supplierId)
            ->whereHas('items', function ($q) use ($skuPrefix) {
                $q->where('sku', 'like', $skuPrefix);
            });

        $q = (clone $base)
            ->with('items')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->skip(($page - 1) * $limit)
            ->take($limit);

        $rows  = $q->get();
        $total = (clone $base)->count();

        return response()->json([
            'pedidos'      => $rows->map(fn($o) => $this->shape($o))->values(),
            'total'        => $total,
            'pagina'       => $page,
            'por_pagina'   => $limit,
            'ultima_pagina'=> (int) ceil($total / $limit),
        ]);
    }

    public function show(Request $request, string $pedido)
    {
        $supplierId = $request->attributes->get('supplier_id');
        $order = Order::where('supplier_id', $supplierId)
            ->where(function ($q) use ($pedido) {
                $q->where('order_number', $pedido)
                  ->orWhere('external_order_id', $pedido);
            })
            ->with('items')
            ->first();

        if (!$order) {
            return response()->json(['erro' => 'Pedido não encontrado'], 404);
        }

        return response()->json(['pedido' => $this->shape($order, true)]);
    }

    public function update(Request $request, string $pedido)
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

        $statusGoolhub = strtoupper((string) $request->input('status', ''));
        if (!isset(self::TO_CANONICAL[$statusGoolhub])) {
            $validos = implode(', ', array_keys(self::TO_CANONICAL));
            return response()->json([
                'erro'   => 'Status inválido',
                'validos'=> $validos,
            ], 400);
        }

        $canonical = self::TO_CANONICAL[$statusGoolhub];

        DB::table('orders')->where('id', $order->id)->update([
            'canonical_status' => $canonical,
            'status'           => $canonical,
            'updated_at'       => now(),
        ]);

        $order->refresh();

        return response()->json(['pedido' => $this->shape($order)]);
    }

    private function shape(Order $o, bool $detail = false): array
    {
        $addr = $o->customer_address;
        if (is_string($addr)) {
            $addr = json_decode($addr, true);
        }

        $data = [
            'id'               => $o->id,
            'nr_pedido'        => $o->order_number,
            'nr_canal'         => $o->external_order_id,
            'status'           => self::TO_GOOLHUB[$o->canonical_status] ?? 'ABERTO',
            'status_interno'   => $o->canonical_status,
            'canal'            => $o->source,
            'canal_nome'       => $o->channel_name,
            'data_pedido'      => $o->created_at?->toIso8601String(),
            'data_pago'        => $o->paid_at?->toIso8601String(),
            'data_enviado'     => $o->shipped_at?->toIso8601String(),
            'data_entregue'    => $o->delivered_at?->toIso8601String(),
            'data_cancelado'   => $o->cancelled_at?->toIso8601String(),
            'cliente_nome'     => $o->customer_name,
            'cliente_cpf'      => $o->customer_document_number,
            'cliente_email'    => $o->customer_email,
            'cliente_telefone' => $o->customer_phone,
            'endereco'         => $addr,
            'rastreio'         => $o->tracking_number,
            'transportadora'   => $o->carrier_name,
            'tipo_entrega'     => $o->delivery_type,
            'etiqueta'         => $o->label_url,
            'valor_total'      => (float) $o->total,
            'frete'            => (float) $o->shipping_cost,
            'subtotal'         => (float) $o->subtotal,
            'itens'            => $o->relationLoaded('items')
                ? $o->items->map(fn($i) => [
                    'sku'        => $i->sku,
                    'produto'    => $i->name,
                    'qtd'        => (int) $i->quantity,
                    'valor'      => (float) $i->unit_price,
                    'total'      => (float) $i->total,
                    'imagem'     => $i->product_image,
                ])->values()
                : [],
        ];

        if ($detail) {
            $data['nf_entrada'] = [
                'numero' => $o->invoice_number,
                'serie'  => $o->invoice_series,
                'chave'  => $o->invoice_access_key,
                'danfe'  => $o->invoice_url,
                'xml'    => $o->invoice_xml_url,
                'status' => $o->invoice_status,
                'emitido_em' => $o->invoice_issued_at?->toIso8601String(),
            ];
        }

        return $data;
    }
}
