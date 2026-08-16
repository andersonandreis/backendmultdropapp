<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Webhooks\ShopeeWebhookController as ShopeeWebhookHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receiver de relay Shopee — recebe cópia do payload do legado goolhub.io.
 * Autenticado via X-Bridge-Key (shared secret).
 *
 * Rota: POST /api/webhooks/shopee
 *
 * Após validar a bridge key, injeta o request no ShopeeWebhookHandler completo
 * (que está em App\Http\Controllers\Webhooks\ShopeeWebhookController) sem
 * re-validar assinatura Shopee (pois o payload já veio pelo bridge autenticado).
 *
 * O handler completo processa:
 *   code 3  — ORDER_STATUS_UPDATE
 *   code 4  — TRACKING_UPDATE
 *   code 5  — SHOP_UPDATE (desativa conta se BANNED/DEAUTHORIZED)
 *   code 11 — ESCROW_UPDATE (reconciliação)
 *   code 15 — SHIPPING_DOCUMENT_STATUS (baixa etiqueta)
 *   code 16 — ITEM_VIOLATION
 */
class ShopeeWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        // 1. Validar bridge key
        $bridgeKey   = $request->header('X-Bridge-Key');
        $expectedKey = config('services.goolhub.bridge_key', 'hb-bridge-2026-xK9mP3qR7vL2nW8');

        if (! $bridgeKey || $bridgeKey !== $expectedKey) {
            Log::warning('[Shopee Bridge Relay] X-Bridge-Key inválida', [
                'ip' => $request->ip(),
            ]);
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $code   = (int) ($request->input('code') ?? 0);
        $shopId = (int) ($request->input('shop_id') ?? 0);

        Log::info('[Shopee Bridge Relay] Evento recebido, delegando ao handler completo', [
            'code'    => $code,
            'shop_id' => $shopId,
        ]);

        // 2. Delegar ao handler completo — pula validação de assinatura Shopee
        //    pois o payload já chegou autenticado via bridge key.
        //    O handler faz o match por code e processa o evento real.
        try {
            $handler = app(ShopeeWebhookHandler::class);
            return $handler->handle($request);
        } catch (\Throwable $e) {
            Log::error('[Shopee Bridge Relay] Erro ao processar evento', [
                'code'  => $code,
                'error' => $e->getMessage(),
            ]);
            // Sempre retornar 200 para evitar retry storm do legado
            return response()->json(['received' => true]);
        }
    }
}
