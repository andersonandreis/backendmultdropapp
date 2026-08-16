<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Services\Integrations\Factories\MarketplaceFactory;
use App\Services\Integrations\Erps\Bling\BlingAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * MUL-159: Endpoints internos para gerenciar marketplace_accounts.
 * Consumidos pelos paineis Filament das WLs (multdrop, fornecefy, mestoredrop).
 * Auth: X-Internal-Key (InternalKeyMiddleware).
 *
 * POST /api/internal/marketplace-accounts/{id}/refresh
 * POST /api/internal/marketplace-accounts/{id}/mark-reauth
 * GET  /api/internal/marketplace-accounts/{id}
 */
class InternalMarketplaceAccountController extends Controller
{
    /**
     * Tenta renovar o token da conta.
     * Para Bling: usa BlingAuthService::getValidToken.
     * Para ML/Shopee: usa MarketplaceFactory::make->refreshToken.
     * Retorna 200 com status atualizado ou 422/503 em falha.
     */
    public function refresh(Request $request, int $id)
    {
        $account = MarketplaceAccount::find($id);

        if (! $account) {
            return response()->json(['error' => 'not_found'], 404);
        }

        // Segurança: supplier_id deve ser informado e bater
        $supplierIdHeader = (int) $request->header('X-Supplier-Id', 0);
        if ($supplierIdHeader > 0 && (int) $account->supplier_id !== $supplierIdHeader) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        try {
            if ($account->platform === 'bling') {
                $blingAuth = app(BlingAuthService::class);
                $token     = $blingAuth->getValidToken($account);
                $success   = (bool) $token;
            } else {
                $service = MarketplaceFactory::make($account);
                $token   = $service->refreshToken($account);
                $success = (bool) $token;
            }

            if ($success) {
                $account->update([
                    'sync_errors_count'    => 0,
                    'sync_blocked_at'      => null,
                    'refresh_errors_count' => 0,
                    'last_error_message'   => null,
                    'last_token_refresh_at' => now(),
                    'status'               => 'active',
                ]);

                $account->refresh();

                Log::info('[InternalMarketplaceAccount] refresh OK', [
                    'id'       => $account->id,
                    'platform' => $account->platform,
                    'caller'   => $request->header('X-Caller', 'unknown'),
                ]);

                return response()->json([
                    'success'            => true,
                    'status'             => $account->status,
                    'last_token_refresh' => $account->last_token_refresh_at?->toIso8601String(),
                    'updated_at'         => $account->updated_at?->toIso8601String(),
                ]);
            }

            // Token refresh retornou vazio — provavelmente needs_reauth
            $account->update(['status' => 'needs_reauth']);

            return response()->json([
                'success' => false,
                'error'   => 'refresh_returned_empty',
                'status'  => 'needs_reauth',
            ], 422);

        } catch (\Throwable $e) {
            Log::error('[InternalMarketplaceAccount] refresh_failed', [
                'id'      => $id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error'   => 'refresh_failed',
                'message' => $e->getMessage(),
            ], 503);
        }
    }

    /**
     * Marca a conta como needs_reauth (sem tentar renovar).
     * Util quando o painel admin quer forçar reconexão manual.
     */
    public function markReauth(Request $request, int $id)
    {
        $account = MarketplaceAccount::find($id);

        if (! $account) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $supplierIdHeader = (int) $request->header('X-Supplier-Id', 0);
        if ($supplierIdHeader > 0 && (int) $account->supplier_id !== $supplierIdHeader) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        $account->update([
            'status'             => 'needs_reauth',
            'last_error_message' => 'Marcado manualmente via painel admin (' . ($request->header('X-Caller', 'admin') . ')'),
        ]);

        Log::info('[InternalMarketplaceAccount] mark_reauth', [
            'id'     => $id,
            'caller' => $request->header('X-Caller', 'unknown'),
        ]);

        return response()->json([
            'success'    => true,
            'status'     => 'needs_reauth',
            'updated_at' => $account->fresh()->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * Retorna dados da conta sem campos sensíveis (tokens).
     */
    public function show(Request $request, int $id)
    {
        $account = MarketplaceAccount::find($id);

        if (! $account) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $supplierIdHeader = (int) $request->header('X-Supplier-Id', 0);
        if ($supplierIdHeader > 0 && (int) $account->supplier_id !== $supplierIdHeader) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        // Retornar dados sem campos sensíveis
        $data = $account->only([
            'id', 'client_id', 'account_name', 'platform', 'service',
            'supplier_id', 'seller_id', 'ml_user_id', 'seller_nickname',
            'shop_id', 'shop_tier', 'status', 'needs_reauth',
            'import_mode', 'data_inicial_import',
            'auto_invoice_enabled', 'invoice_series',
            'last_sync_at', 'last_token_refresh_at',
            'sync_errors_count', 'refresh_errors_count', 'last_error_message',
            'sync_blocked_at', 'created_at', 'updated_at',
        ]);

        // Adicionar expiry sem expor o token
        $data['token_expires_at'] = $account->ml_token_expires_at
            ?? $account->token_expires_at
            ?? $account->bling_token_expires_at;

        return response()->json(['data' => $data]);
    }
}
