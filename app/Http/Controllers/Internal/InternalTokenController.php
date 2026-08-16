<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Services\Integrations\Factories\MarketplaceFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * NOV-061: Endpoint interno consumido pelo sistema legado (goolhub.io)
 * para obter um access_token de marketplace VALIDO sem precisar fazer
 * refresh local. Centraliza a logica de lazy refresh + lock distribuido
 * no novo sistema (matriz nica), evitando "conta dessincronizada".
 *
 * GET /api/internal/marketplace-token?platform=shopee&shop_id=12345
 * GET /api/internal/marketplace-token?platform=ml&ml_user_id=98765
 *
 * Header: X-Internal-Key: {INTERNAL_BRIDGE_KEY}
 *
 * NOV-083: Resposta Shopee enriquecida com shop_id + partner_id (exigidos pela API Shopee).
 * Status=active obrigatorio na busca Shopee (conta needs_reauth nao tem token valido).
 */
class InternalTokenController extends Controller
{
    public function getToken(Request $request)
    {
        $platform = $request->query('platform');
        $shopId   = $request->query('shop_id');
        $mlUserId = $request->query('ml_user_id');

        if (!$platform) {
            return response()->json(['error' => 'platform required'], 422);
        }

        $account = match ($platform) {
            // NOV-03:&filtra status=active -- conta needs_reauth nao tem token valido para o legado usar
            'shopee' => MarketplaceAccount::where('platform', 'shopee')
                ->where('shop_id', $shopId)
                ->whereNotNull('shop_id')
                ->where('status', 'active')
                ->first(),
            'mercadolivre', 'mercado_livre', 'ml' => MarketplaceAccount::whereIn('platform', ['mercadolivre', 'mercado_livre', 'ml'])
                ->where('ml_user_id', $mlUserId)
                ->whereNotNull('ml_user_id')
                ->first(),
            default => null,
        };

        if (!$account) {
            return response()->json(['error' => 'account_not_found'], 404);
        }

        try {
            $service = MarketplaceFactory::make($account);
            $token = $service->getAccessToken($account); // wrapper publico
        } catch (\Throwable $e) {
            Log::error('[InternalTokenController] refresh_failed', [
                'account_id' => $account->id,
                'platform'   => $account->platform,
                'message'    => $e->getMessage(),
            ]);
            return response()->json([
                'error'   => 'refresh_failed',
                'message' => $e->getMessage(),
            ], 503);
        }

        if (!$token) {
            return response()->json([
                'error'  => 'token_unavailable',
                'status' => $account->status,
            ], 422);
        }

        $account->refresh();

        // Payload base (todos os marketplaces)
        $payload = [
            'access_token' => $token,
            'platform'     => $account->platform,
            'expires_at'   => $account->token_expires_at?->toIso8601String()
                ?? $account->ml_token_expires_at?->toIso8601String(),
            'status'       => $account->status,
        ];

        // NOV-083: Shopee exige shop_id + partner_id em TODA transacoautenticada.
        // O legado precisa desses campos para construir os headers de assinatura HMAC-SHA256.
        if ($account->platform === 'shopee') {
            $payload['shop_id']    = (int) $account->shop_id;
            $payload['partner_id'] = (int) config('services.shopee.partner_id', env('SHOPEE_PARTNER_ID'));
        }

        return response()->json($payload);
    }
}
