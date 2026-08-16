<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\PackingSlipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * PackingSlipController
 *
 * Etiqueta de conferencia interna (packing slip) com foto e SKU.
 * NAO substitui etiqueta de envio do marketplace.
 *
 * GET  /api/v1/orders/{id}/packing-slip          — retorna PDF (download)
 * POST /api/v1/orders/{id}/packing-slip/generate — forca regeneracao
 */
class PackingSlipController extends Controller
{
    public function __construct(private PackingSlipService $service) {}

    /**
     * Retorna o PDF do packing slip para download.
     * Gera automaticamente na primeira vez.
     */
    public function show(Request $request, int $id): Response|JsonResponse
    {
        $order = $this->resolveOrder($request, $id);

        $path = $this->service->generate($order);

        if (!$path) {
            return response()->json([
                'error'   => 'Falha ao gerar packing slip.',
                'order_id' => $id,
            ], 500);
        }

        $pdf = Storage::get($path);

        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"packing-slip-{$order->order_number}.pdf\"",
            'Cache-Control'       => 'private, max-age=3600',
        ]);
    }

    /**
     * Forca a regeneracao do PDF (descarta cache).
     * Retorna JSON com URL para download.
     */
    public function generate(Request $request, int $id): JsonResponse
    {
        $order = $this->resolveOrder($request, $id);

        $path = $this->service->generate($order, force: true);

        if (!$path) {
            return response()->json([
                'error'    => 'Falha ao gerar packing slip.',
                'order_id' => $id,
            ], 500);
        }

        return response()->json([
            'data' => [
                'order_id'     => $order->id,
                'order_number' => $order->order_number,
                'path'         => $path,
                'url'          => route('api.v1.orders.packing-slip', $order->id),
                'items_count'  => $order->items()->count(),
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Resolve o pedido validando que pertence ao client autenticado.
     * Usa client_id quando disponivel (lojista), ou apenas ID quando
     * chamado de contexto supplier-admin (middleware diferente).
     */
    private function resolveOrder(Request $request, int $id): Order
    {
        $user   = $request->user();
        $client = $user?->client;

        if ($client) {
            return Order::where('id', $id)
                ->where('client_id', $client->id)
                ->with(['items.product.media'])
                ->firstOrFail();
        }

        return Order::with(['items.product.media'])->findOrFail($id);
    }
}
