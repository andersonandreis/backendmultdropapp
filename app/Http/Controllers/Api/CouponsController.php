<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SEL-186: endpoints publicos de cupom para o checkout seller.global.
 *
 * GET  /api/checkout/coupons/{code}      — pre-valida cupom no frontend (sem state)
 * POST /api/checkout/apply-coupon        — valida + calcula desconto antes de cobrar
 *
 * Nenhum desses endpoints efetiva o desconto — quem faz isso e o CheckoutController
 * ao processar o pagamento. Esses endpoints so respondem "e valido ou nao" e o
 * preco final esperado para que o frontend mostre pro usuario antes de confirmar.
 */
class CouponsController extends Controller
{
    /**
     * GET /api/checkout/coupons/{code}
     *
     * Pre-check leve: apenas verifica se o cupom existe e esta ativo.
     * Nao checa por client_id (chamada publica, antes do login de checkout).
     * Retorna os metadados do cupom para o frontend montar o UI de preview.
     */
    public function validate(string $code): JsonResponse
    {
        $coupon = Coupon::where('code', strtoupper(trim($code)))->first();

        if (!$coupon) {
            return response()->json([
                'valid'    => false,
                'message'  => 'Cupom nao encontrado.',
            ], 404);
        }

        $error = $coupon->validateForCheckout();

        if ($error) {
            return response()->json([
                'valid'   => false,
                'message' => $error,
            ], 422);
        }

        return response()->json([
            'valid'                 => true,
            'code'                  => $coupon->code,
            'discount_type'         => $coupon->discount_type,
            'discount_value'        => (float) $coupon->discount_value,
            'applies_to_plan_slug'  => $coupon->applies_to_plan_slug,
            'ends_at'               => $coupon->ends_at?->toIso8601String(),
        ]);
    }

    /**
     * POST /api/checkout/apply-coupon
     *
     * Validacao completa + calculo do preco final.
     * Chamado pelo frontend antes de submeter o checkout, para mostrar
     * o preco com desconto e o botao "Pagar R$ X".
     *
     * Body: { "code": "GRUPO50", "plan_id": 3 }
     * Resposta: { valid, original_price, discount_amount, final_price, ... }
     */
    public function apply(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code'    => 'required|string|max:50',
            'plan_id' => 'required|integer|exists:plans,id',
        ]);

        $coupon = Coupon::where('code', strtoupper(trim($validated['code'])))->first();

        if (!$coupon) {
            return response()->json([
                'valid'   => false,
                'message' => 'Cupom nao encontrado.',
            ], 404);
        }

        $plan = Plan::findOrFail($validated['plan_id']);

        // Verifica client_id se autenticado (para checar max_uses_per_client)
        $clientId = null;
        if ($request->user() && $request->user()->client) {
            $clientId = $request->user()->client->id;
        }

        $error = $coupon->validateForCheckout($plan->slug, $clientId);

        if ($error) {
            return response()->json([
                'valid'   => false,
                'message' => $error,
            ], 422);
        }

        $originalPrice    = (float) $plan->price_monthly;
        $discountAmount   = $coupon->calculateDiscount($originalPrice);
        $finalPrice       = max(0.0, round($originalPrice - $discountAmount, 2));

        return response()->json([
            'valid'                => true,
            'code'                 => $coupon->code,
            'plan_id'              => $plan->id,
            'plan_name'            => $plan->name,
            'original_price'       => $originalPrice,
            'discount_type'        => $coupon->discount_type,
            'discount_value'       => (float) $coupon->discount_value,
            'discount_amount'      => $discountAmount,
            'final_price'          => $finalPrice,
            'applies_to_plan_slug' => $coupon->applies_to_plan_slug,
            'ends_at'              => $coupon->ends_at?->toIso8601String(),
        ]);
    }
}
