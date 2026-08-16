<?php

namespace App\Http\Controllers\Api\V1\Drop;

use App\Http\Controllers\Controller;
use App\Models\Drop\DropPricingRule;
use App\Services\Drop\DropPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Drop Internacional - CRUD de regras de precificacao e calculo ad hoc.
 */
class DropPricingController extends Controller
{
    public function __construct(
        private DropPricingService $pricingService
    ) {}

    private function clientOrFail(Request $request)
    {
        $client = $request->user()->client;
        if (!$client) {
            abort(403, 'Usuario nao possui perfil de lojista.');
        }
        return $client;
    }

    /**
     * Listar regras de precificacao do cliente.
     */
    public function index(Request $request): JsonResponse
    {
        $client = $this->clientOrFail($request);
        $rules  = DropPricingRule::where('client_id', $client->id)->get();
        return response()->json(['data' => $rules]);
    }

    /**
     * Criar nova regra de precificacao para o cliente.
     */
    public function store(Request $request): JsonResponse
    {
        $client = $this->clientOrFail($request);

        $validated = $request->validate([
            'supplier_slug'    => 'nullable|string|in:aliexpress,cj,zendrop',
            'markup_pct'       => 'required|numeric|min:1|max:500',
            'gateway_fee_pct'  => 'nullable|numeric|min:0|max:50',
            'platform_fee_pct' => 'nullable|numeric|min:0|max:50',
            'is_active'        => 'nullable|boolean',
            'label'            => 'nullable|string|max:100',
        ]);

        $validated['client_id'] = $client->id;
        $validated['is_active'] = $validated['is_active'] ?? true;

        $rule = DropPricingRule::create($validated);

        return response()->json(['data' => $rule], 201);
    }

    /**
     * Atualizar regra de precificacao existente.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $client = $this->clientOrFail($request);
        $rule   = DropPricingRule::where('client_id', $client->id)->findOrFail($id);

        $validated = $request->validate([
            'supplier_slug'    => 'nullable|string|in:aliexpress,cj,zendrop',
            'markup_pct'       => 'sometimes|numeric|min:1|max:500',
            'gateway_fee_pct'  => 'nullable|numeric|min:0|max:50',
            'platform_fee_pct' => 'nullable|numeric|min:0|max:50',
            'is_active'        => 'nullable|boolean',
            'label'            => 'nullable|string|max:100',
        ]);

        $rule->fill($validated)->save();

        return response()->json(['data' => $rule]);
    }

    /**
     * Calcular margem ou preco sugerido ad hoc (sem persistencia).
     *
     * Modos:
     *   suggest_price:    retorna preco sugerido dado custo + markup
     *   calculate_margin: retorna margem dado custo + preco de venda
     */
    public function calculate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mode'             => 'required|string|in:suggest_price,calculate_margin',
            'cost_usd'         => 'required|numeric|min:0',
            'shipping_usd'     => 'required|numeric|min:0',
            'markup_pct'       => 'nullable|numeric|min:0|max:500',
            'sell_price'       => 'nullable|numeric|min:0',
            'gateway_fee_pct'  => 'nullable|numeric|min:0|max:50',
            'platform_fee_pct' => 'nullable|numeric|min:0|max:50',
        ]);

        $costUsd        = (float) $validated['cost_usd'];
        $shippingUsd    = (float) $validated['shipping_usd'];
        $gatewayFeePct  = (float) ($validated['gateway_fee_pct']  ?? 3.5);
        $platformFeePct = (float) ($validated['platform_fee_pct'] ?? 5.0);

        if ($validated['mode'] === 'suggest_price') {
            $markupPct      = (float) ($validated['markup_pct'] ?? 40.0);
            $suggestedPrice = $this->pricingService->suggestPrice(
                $costUsd, $shippingUsd, $markupPct, $gatewayFeePct, $platformFeePct
            );
            $margin = $this->pricingService->calculateMargin(
                $costUsd, $shippingUsd, $suggestedPrice, $gatewayFeePct, $platformFeePct
            );

            return response()->json([
                'data' => [
                    'mode'             => 'suggest_price',
                    'cost_usd'         => $costUsd,
                    'shipping_usd'     => $shippingUsd,
                    'markup_pct'       => $markupPct,
                    'gateway_fee_pct'  => $gatewayFeePct,
                    'platform_fee_pct' => $platformFeePct,
                    'suggested_price'  => $suggestedPrice,
                    'margin_usd'       => $margin,
                ],
            ]);
        }

        $sellPrice = (float) ($validated['sell_price'] ?? 0.0);
        $margin    = $this->pricingService->calculateMargin(
            $costUsd, $shippingUsd, $sellPrice, $gatewayFeePct, $platformFeePct
        );

        return response()->json([
            'data' => [
                'mode'             => 'calculate_margin',
                'cost_usd'         => $costUsd,
                'shipping_usd'     => $shippingUsd,
                'sell_price'       => $sellPrice,
                'gateway_fee_pct'  => $gatewayFeePct,
                'platform_fee_pct' => $platformFeePct,
                'margin_usd'       => $margin,
                'gateway_fee_usd'  => round($sellPrice * $gatewayFeePct / 100, 4),
                'platform_fee_usd' => round($sellPrice * $platformFeePct / 100, 4),
            ],
        ]);
    }
}
