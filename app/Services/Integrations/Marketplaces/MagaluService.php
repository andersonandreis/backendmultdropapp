<?php

namespace App\Services\Integrations\Marketplaces;

use App\Models\MarketplaceAccount;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * NOV-132 — Integração Magazine Luiza (Magalu Marketplace).
 *
 * Docs: https://app.swaggerhub.com/apis-docs/magalu/api-magalu
 * OAuth: https://id.magalu.com/oauth/authorize
 * API: https://api.magalu.com/magalu-marketplace
 *
 * Status: implementação MVP — auth + sync de pedidos + sync de estoque.
 * Publicação de produto: stub, requer aprovação manual pela Magalu (catálogo curado).
 */
class MagaluService
{
    protected string $authBase = 'https://id.magalu.com/oauth';
    protected string $apiBase  = 'https://api.magalu.com/magalu-marketplace/v2';

    public function getAuthorizeUrl(string $state, string $clientId, string $redirectUri): string
    {
        return $this->authBase.'/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'scope'         => 'magalu-marketplace',
            'state'         => $state,
        ]);
    }

    public function exchangeCodeForToken(string $code, string $clientId, string $clientSecret, string $redirectUri): array
    {
        try {
            $resp = Http::asForm()->timeout(20)->post($this->authBase.'/token', [
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri'  => $redirectUri,
            ]);
            return $resp->json() ?: [];
        } catch (\Throwable $e) {
            Log::error('[NOV-132] Magalu exchange falhou', ['err' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    public function refreshToken(MarketplaceAccount $account): ?string
    {
        if (!$account->refresh_token) return null;
        try {
            $resp = Http::asForm()->timeout(20)->post($this->authBase.'/token', [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $account->refresh_token,
                'client_id'     => env('MAGALU_CLIENT_ID'),
                'client_secret' => env('MAGALU_CLIENT_SECRET'),
            ]);
            $data = $resp->json() ?: [];
            if (!empty($data['access_token'])) {
                $account->access_token = $data['access_token'];
                $account->refresh_token = $data['refresh_token'] ?? $account->refresh_token;
                $account->token_expires_at = isset($data['expires_in']) ? now()->addSeconds((int) $data['expires_in']) : null;
                $account->save();
                return $data['access_token'];
            }
        } catch (\Throwable $e) {
            Log::error('[NOV-132] Magalu refresh falhou', ['err' => $e->getMessage()]);
        }
        return null;
    }

    public function getValidAccessToken(MarketplaceAccount $account): ?string
    {
        if ($account->token_expires_at && $account->token_expires_at->isFuture()) {
            return $account->access_token;
        }
        return $this->refreshToken($account);
    }

    public function fetchOrders(MarketplaceAccount $account, ?string $sinceDate = null): array
    {
        $token = $this->getValidAccessToken($account);
        if (!$token) return [];
        try {
            $resp = Http::withToken($token)->timeout(30)
                ->get($this->apiBase.'/orders', [
                    'created_at_gte' => $sinceDate ?? now()->subDays(7)->toIso8601String(),
                    'limit'          => 100,
                ]);
            return $resp->successful() ? ($resp->json('data') ?? []) : [];
        } catch (\Throwable $e) {
            Log::error('[NOV-132] Magalu fetchOrders falhou', ['err' => $e->getMessage()]);
            return [];
        }
    }

    public function syncInventoryAndPrice(MarketplaceAccount $account, string $sku, int $quantity, float $price): bool
    {
        $token = $this->getValidAccessToken($account);
        if (!$token) return false;
        try {
            $resp = Http::withToken($token)->timeout(20)
                ->put($this->apiBase.'/inventory/'.$sku, [
                    'quantity' => $quantity,
                    'price'    => $price,
                ]);
            return $resp->successful();
        } catch (\Throwable $e) {
            Log::error('[NOV-132] Magalu syncInventory falhou', ['sku' => $sku, 'err' => $e->getMessage()]);
            return false;
        }
    }

    public function syncProduct(MarketplaceAccount $account, Product $product): bool|array
    {
        // Magalu exige aprovação manual pela curadoria — stub que retorna estado pendente.
        Log::info('[NOV-132] Magalu syncProduct stub', ['product_id' => $product->id]);
        return ['status' => 'pending_curation', 'message' => 'Magalu requer aprovação manual da curadoria — submeta via portal do seller.'];
    }

    public function getShippingLabel(MarketplaceAccount $account, Order $order): ?string
    {
        $token = $this->getValidAccessToken($account);
        if (!$token || !$order->external_order_id) return null;
        try {
            $resp = Http::withToken($token)->timeout(30)
                ->get($this->apiBase.'/orders/'.$order->external_order_id.'/shipping/label');
            return $resp->successful() ? ($resp->json('label_url') ?? $resp->body()) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function getShippingLabelBatch(MarketplaceAccount $account, Collection $orders): Collection
    {
        return $orders->mapWithKeys(fn (Order $o) => [$o->id => $this->getShippingLabel($account, $o)]);
    }
}
