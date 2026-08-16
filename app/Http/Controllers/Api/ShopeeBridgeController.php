<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Services\InstallationConfig;
use App\Services\Integrations\Marketplaces\ShopeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * NOV-181: bridge de autenticacao central Shopee (hub <-> WLs).
 *
 * Todos os endpoints sao autenticados via HMAC-SHA256 do raw body no header
 * X-HubAI-Bridge-Sig, com SHOPEE_BRIDGE_SECRET (mesmo canal do relay OAuth).
 *
 *  - refresh      (roda no HUB): WL com token espelhado expirado pede refresh;
 *                  o hub renova na Shopee (se preciso) e devolve os tokens.
 *  - export       (roda na WL): handoff — hub importa a cadeia atual da WL.
 *  - markManaged  (roda na WL): hub marca/desmarca centrally_managed apos handoff.
 */
class ShopeeBridgeController extends Controller
{
    /**
     * POST /api/oauth/shopee/bridge-refresh (hub)
     */
    public function refresh(Request $request): JsonResponse
    {
        if (! $this->validSignature($request)) {
            return response()->json(['error' => 'invalid_signature'], 403);
        }

        $config = app(InstallationConfig::class);
        if (! $config->isHub()) {
            return response()->json(['error' => 'not_a_hub'], 409);
        }

        $shopId = (string) $request->input('shop_id', '');
        if ($shopId === '') {
            return response()->json(['error' => 'missing_shop_id'], 422);
        }

        $account = MarketplaceAccount::where('platform', 'shopee')
            ->where('shop_id', $shopId)
            ->orderByDesc('id')
            ->first();

        if (! $account) {
            return response()->json(['error' => 'unknown_shop'], 404);
        }

        try {
            $token = app(ShopeeService::class)->getValidAccessToken($account);
        } catch (\Throwable $e) {
            Log::error('[ShopeeBridge] bridge-refresh falhou', [
                'shop_id' => $shopId,
                'error'   => $e->getMessage(),
            ]);
            $token = null;
        }

        if (! $token) {
            return response()->json(['error' => 'refresh_failed', 'shop_id' => $shopId], 502);
        }

        $account->refresh();

        Log::info('[ShopeeBridge] bridge-refresh atendido', [
            'shop_id'      => $shopId,
            'requested_by' => (string) $request->input('requested_by', ''),
            'expires_at'   => (string) $account->token_expires_at,
        ]);

        return response()->json([
            'success'                  => true,
            'shop_id'                  => $shopId,
            'access_token'             => $token,
            'refresh_token'            => $this->decryptToken($account->refresh_token),
            'token_expires_at'         => optional($account->token_expires_at)->toDateTimeString(),
            'refresh_token_expires_at' => optional($account->refresh_token_expires_at)->toDateTimeString(),
        ]);
    }

    /**
     * POST /api/oauth/shopee/bridge-export (WL)
     */
    public function export(Request $request): JsonResponse
    {
        if (! $this->validSignature($request)) {
            return response()->json(['error' => 'invalid_signature'], 403);
        }

        $shopId = (string) $request->input('shop_id', '');
        if ($shopId === '') {
            return response()->json(['error' => 'missing_shop_id'], 422);
        }

        $account = MarketplaceAccount::where('platform', 'shopee')
            ->where('shop_id', $shopId)
            ->orderByDesc('id')
            ->first();

        if (! $account) {
            return response()->json(['error' => 'unknown_shop'], 404);
        }

        Log::info('[ShopeeBridge] bridge-export (handoff) solicitado', [
            'shop_id'    => $shopId,
            'account_id' => $account->id,
            'status'     => $account->status,
        ]);

        return response()->json([
            'success'                  => true,
            'shop_id'                  => $shopId,
            'account_id'               => $account->id,
            'status'                   => $account->status,
            'centrally_managed'        => (bool) $account->centrally_managed,
            'access_token'             => $this->decryptToken($account->access_token),
            'refresh_token'            => $this->decryptToken($account->refresh_token),
            'token_expires_at'         => optional($account->token_expires_at)->toDateTimeString(),
            'refresh_token_expires_at' => optional($account->refresh_token_expires_at)->toDateTimeString(),
            'last_token_refresh_at'    => optional($account->last_token_refresh_at)->toDateTimeString(),
        ]);
    }

    /**
     * POST /api/oauth/shopee/bridge-mark-managed (WL)
     */
    public function markManaged(Request $request): JsonResponse
    {
        if (! $this->validSignature($request)) {
            return response()->json(['error' => 'invalid_signature'], 403);
        }

        $shopId = (string) $request->input('shop_id', '');
        if ($shopId === '') {
            return response()->json(['error' => 'missing_shop_id'], 422);
        }

        $managed = filter_var($request->input('managed', true), FILTER_VALIDATE_BOOLEAN);

        $updated = MarketplaceAccount::where('platform', 'shopee')
            ->where('shop_id', $shopId)
            ->update(['centrally_managed' => $managed]);

        Log::info('[ShopeeBridge] bridge-mark-managed', [
            'shop_id' => $shopId,
            'managed' => $managed,
            'updated' => $updated,
        ]);

        return response()->json(['success' => true, 'shop_id' => $shopId, 'managed' => $managed, 'updated' => $updated]);
    }

    private function validSignature(Request $request): bool
    {
        $secret = (string) config('services.shopee.bridge_secret', '');
        $sig    = (string) $request->header('X-HubAI-Bridge-Sig', '');

        if ($secret === '' || $sig === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $sig);
    }

    private function decryptToken(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return decrypt($value);
        } catch (\Throwable $e) {
            return $value;
        }
    }
}
