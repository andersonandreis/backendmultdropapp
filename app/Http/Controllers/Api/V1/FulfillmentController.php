<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FulfillmentContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MUL-227 item 30 — Menu Fulfillment (armazenamento + preparo por marketplace).
 */
class FulfillmentController extends Controller
{
    private function clientOrFail(Request $request)
    {
        $client = $request->user()->client;
        if (! $client) abort(403, 'Usuario nao possui perfil de lojista.');
        return $client;
    }

    public function index(Request $request): JsonResponse
    {
        $client = $this->clientOrFail($request);
        $contracts = FulfillmentContract::where('client_id', $client->id)
            ->orderBy('marketplace')
            ->get();

        // Valores default sugeridos (pra tela de contratacao mostrar antes de o admin definir preco)
        // Ruan pode ajustar via SupplierAdmin — por ora usamos default razoavel.
        return response()->json([
            'data' => $contracts,
            'meta' => [
                'marketplaces' => FulfillmentContract::MARKETPLACES,
                'modes'        => FulfillmentContract::MODES,
                'defaults'     => [
                    'valor_m3'         => 120.00, // R$/m³/mês default
                    'valor_por_pedido' => 3.50,   // R$/pedido processado
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $client = $this->clientOrFail($request);

        $data = $request->validate([
            'marketplace'         => ['required', 'string', 'in:all,mercadolivre,shopee,amazon,magalu,tiktok'],
            'mode'                => ['required', 'string', 'in:envio,apenas_processamento'],
            'm3_reservado'        => ['required', 'numeric', 'min:0.1'],
            'valor_m3'            => ['nullable', 'numeric', 'min:0'],
            'valor_por_pedido'    => ['nullable', 'numeric', 'min:0'],
            'warehouse_location'  => ['nullable', 'string', 'max:191'],
        ]);

        $contract = FulfillmentContract::create([
            'client_id'          => $client->id,
            'marketplace'        => $data['marketplace'],
            'mode'               => $data['mode'],
            'm3_reservado'       => $data['m3_reservado'],
            'valor_m3'           => $data['valor_m3'] ?? 120.00,
            'valor_por_pedido'   => $data['valor_por_pedido'] ?? 3.50,
            'warehouse_location' => $data['warehouse_location'] ?? null,
            'status'             => 'active',
            'started_at'         => now(),
        ]);

        return response()->json(['data' => $contract], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $client = $this->clientOrFail($request);
        $contract = FulfillmentContract::where('client_id', $client->id)->findOrFail($id);

        $data = $request->validate([
            'm3_reservado' => ['sometimes', 'numeric', 'min:0.1'],
            'status'       => ['sometimes', 'string', 'in:active,paused,cancelled'],
            'mode'         => ['sometimes', 'string', 'in:envio,apenas_processamento'],
        ]);

        $contract->fill($data)->save();

        return response()->json(['data' => $contract->fresh()]);
    }
}
