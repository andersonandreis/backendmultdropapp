<?php

namespace App\Http\Controllers\TenantApi\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');

        $q = Order::forTenant($tenantId)->orderBy('created_at', 'desc')->orderBy('id', 'desc');

        if ($status = $request->query('status')) {
            $statuses = array_filter(array_map('trim', explode(',', $status)));
            if (!empty($statuses)) {
                $q->whereIn('canonical_status', $statuses);
            }
        }
        if ($from = $request->query('from')) {
            try { $q->where('created_at', '>=', Carbon::parse($from)); } catch (\Throwable $e) {}
        }
        if ($to = $request->query('to')) {
            try { $q->where('created_at', '<=', Carbon::parse($to)); } catch (\Throwable $e) {}
        }
        if ($cursor = $request->query('cursor')) {
            $decoded = json_decode(base64_decode($cursor), true);
            if (is_array($decoded) && isset($decoded['t'], $decoded['i'])) {
                $q->where(function ($qq) use ($decoded) {
                    $qq->where('created_at', '<', $decoded['t'])
                       ->orWhere(function ($qqq) use ($decoded) {
                           $qqq->where('created_at', '=', $decoded['t'])
                               ->where('id', '<', $decoded['i']);
                       });
                });
            }
        }
        $limit = (int) min(max((int) $request->query('limit', 50), 1), 200);

        $rows = $q->with('items')->limit($limit + 1)->get();
        $next = null;
        if ($rows->count() > $limit) {
            $last = $rows[$limit - 1];
            $next = base64_encode(json_encode(['t' => $last->created_at?->toIso8601String(), 'i' => $last->id]));
            $rows = $rows->take($limit);
        }

        return response()->json([
            'data'        => $rows->map(fn($o) => $this->shape($o))->values(),
            'next_cursor' => $next,
        ]);
    }

    public function show(Request $request, string $id)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $order = Order::forTenant($tenantId)->with('items')->find($id);
        if (!$order) {
            return response()->json(['error' => 'not_found'], 404);
        }
        return response()->json(['data' => $this->shape($order, true)]);
    }

    private function shape(Order $o, bool $detail = false): array
    {
        $addr = $o->customer_address;
        if (is_string($addr)) {
            $addr = json_decode($addr, true);
        }

        $base = [
            'id'                      => $o->id,
            'order_number'            => $o->order_number,
            'canonical_status'        => $o->canonical_status,
            'status'                  => $o->status,
            'source'                  => $o->source,
            'channel_name'            => $o->channel_name,
            'external_order_id'       => $o->external_order_id,
            'external_pack_id'        => $o->external_pack_id,
            'external_shipping_id'    => $o->external_shipping_id,
            'supplier_id'             => $o->supplier_id,
            'client_id'               => $o->client_id,
            'subtotal'                => (float) $o->subtotal,
            'shipping_cost'           => (float) $o->shipping_cost,
            'marketplace_fee'         => (float) $o->marketplace_fee,
            'total'                   => (float) $o->total,
            'currency'                => $o->currency ?? 'BRL',
            'shipping_mode'           => $o->shipping_mode,
            'delivery_type'           => $o->delivery_type,
            'label_url'               => $o->label_url,
            'packing_photo_url'       => $o->packing_photo_url,
            'paid_at'                 => $o->paid_at?->toIso8601String(),
            'shipped_at'              => $o->shipped_at?->toIso8601String(),
            'delivered_at'            => $o->delivered_at?->toIso8601String(),
            'cancelled_at'            => $o->cancelled_at?->toIso8601String(),
            'customer_name'            => $o->customer_name,
            'customer_document_type'   => $o->customer_document_type,
            'customer_document_number' => $o->customer_document_number,
            'created_at'              => $o->created_at?->toIso8601String(),
            'updated_at'              => $o->updated_at?->toIso8601String(),
            'items'                   => $o->items->map(fn($i) => [
                'sku'           => $i->sku,
                'variation_sku' => $i->variation_sku,
                'name'          => $i->name,
                'quantity'      => (int) $i->quantity,
                'unit_price'    => (float) $i->unit_price,
                'total'         => (float) $i->total,
                'product_image' => $i->product_image,
                'external_item_id' => $i->external_item_id,
            ])->values(),
        ];

        if ($detail) {
            $base['customer'] = [
                'name'            => $o->customer_name,
                'email'           => $o->customer_email,
                'phone'           => $o->customer_phone,
                'document_type'   => $o->customer_document_type,
                'document_number' => $o->customer_document_number,
                'address'         => $addr,
            ];
            $base['tracking'] = [
                'number'  => $o->tracking_number,
                'url'     => $o->tracking_url,
                'carrier' => $o->carrier_name,
            ];
            $base['invoice'] = [
                'number'      => $o->invoice_number,
                'series'      => $o->invoice_series,
                'status'      => $o->invoice_status,
                'access_key'  => $o->invoice_access_key,
                'url'         => $o->invoice_url,
                'xml_url'     => $o->invoice_xml_url,
                'issued_at'   => $o->invoice_issued_at?->toIso8601String(),
            ];
            $base['external_refs'] = $o->external_refs
                ? (is_array($o->external_refs) ? $o->external_refs : json_decode($o->external_refs, true))
                : null;
            $base['buyer'] = [
                'id'       => $o->buyer_id,
                'nickname' => $o->buyer_nickname,
            ];
        }

        return $base;
    }
}
