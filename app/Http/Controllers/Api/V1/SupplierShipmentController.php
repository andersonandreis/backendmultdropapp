<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * NOV-126 — Scanner de remessas (recebimento por código de barras / etiqueta).
 */
class SupplierShipmentController extends Controller
{
    /** POST /api/v1/supplier/shipments/{id}/scan-item */
    public function scanItem(Request $request, int $shipmentId): JsonResponse
    {
        $request->validate([
            'barcode'    => 'required|string|max:255',
            'box_number' => 'nullable|integer|min:1',
        ]);

        $userId   = auth()->id();
        $barcode  = trim($request->input('barcode'));
        $boxNum   = $request->input('box_number');

        /** @var Shipment|null $shipment */
        $shipment = Shipment::query()->find($shipmentId);
        if (!$shipment) {
            return response()->json(['ok' => false, 'reason' => 'shipment_not_found'], 404);
        }

        // Acesso supplier-scoped: producer_id é o fornecedor.
        $user = auth()->user();
        if ($user && $user->role === 'supplier') {
            $supplierId = $user->supplier?->id;
            if ($supplierId && $shipment->producer_id !== $supplierId) {
                return response()->json(['ok' => false, 'reason' => 'forbidden'], 403);
            }
        }

        $item = ShipmentItem::query()
            ->where('shipment_id', $shipment->id)
            ->where(function ($q) use ($barcode) {
                $q->where('label_code', $barcode);
            })
            ->first();

        if (!$item) {
            // Tenta achar por sku do produto
            $item = ShipmentItem::query()
                ->where('shipment_id', $shipment->id)
                ->whereHas('product', fn ($qq) => $qq->where('sku', $barcode))
                ->first();
        }

        if (!$item) {
            return response()->json([
                'ok'     => false,
                'reason' => 'item_not_found_in_shipment',
                'beep'   => 'red',
                'barcode' => $barcode,
            ], 404);
        }

        if ($item->scanned_at) {
            return response()->json([
                'ok'     => true,
                'duplicate' => true,
                'beep'   => 'yellow',
                'item_id' => $item->id,
                'sku'    => $item->product?->sku,
                'name'   => $item->product?->name,
                'quantity' => $item->quantity,
                'quantity_received' => $item->quantity_received,
                'scanned_at' => $item->scanned_at,
            ]);
        }

        DB::transaction(function () use ($item, $userId, $boxNum, $shipment) {
            $item->quantity_received = (int) $item->quantity;
            $item->scanned_at = now();
            $item->scanned_by_user_id = $userId;
            $item->checked_at = now();
            if ($boxNum) {
                $item->box_number = $boxNum;
            }
            $item->save();

            $shipment->total_checked = ShipmentItem::where('shipment_id', $shipment->id)
                ->whereNotNull('scanned_at')->count();
            if ($shipment->total_checked >= $shipment->total_items) {
                $shipment->status = 'arrived';
                $shipment->received_at = now();
                $shipment->checked_at = now();
            }
            $shipment->save();
        });

        return response()->json([
            'ok'     => true,
            'beep'   => 'green',
            'item_id' => $item->id,
            'sku'    => $item->product?->sku,
            'name'   => $item->product?->name,
            'quantity' => $item->quantity,
            'box_number' => $item->box_number,
            'shipment_progress' => [
                'checked' => $shipment->total_checked,
                'total'   => $shipment->total_items,
                'pct'     => $shipment->total_items > 0
                    ? round(($shipment->total_checked / $shipment->total_items) * 100, 1)
                    : 0,
            ],
        ]);
    }

    /** GET /api/v1/supplier/shipments — listagem com filtros */
    public function index(Request $request): JsonResponse
    {
        $query = Shipment::query()->with(['producer', 'warehouse']);
        if (auth()->user()?->role === 'supplier') {
            $supplierId = auth()->user()->supplier?->id;
            if ($supplierId) {
                $query->where('producer_id', $supplierId);
            }
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('carrier')) {
            $query->where('carrier', $request->input('carrier'));
        }
        if ($request->filled('marketplace')) {
            $query->where('marketplace', $request->input('marketplace'));
        }
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->input('to'));
        }
        return response()->json([
            'data' => $query->orderByDesc('created_at')->paginate((int) $request->input('per_page', 20)),
        ]);
    }
}
