<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ErpAccount;
use App\Models\MarketplaceAccount;
use App\Services\Logging\MigracaoLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

/**
 * MUL-029-2: Receiver do relay Bling OAuth (api.hubai.io -> WL).
 *
 * O hubai.io troca o authorization code Bling por tokens reais (tem o client_secret),
 * e faz POST HMAC-assinado pra esta rota na WL de origem.
 *
 * O mesmo codigo roda nas 3 WLs (multdrop, fornecefy, hubai) — o tenant local eh
 * identificado por config('bling.app_tenant') (env APP_TENANT). O payload tem
 * 'tenant' que precisa bater com app_tenant — anti cross-tenant.
 *
 * Header HMAC: X-HubAI-Bridge-Sig = hash_hmac(sha256, raw_body, BLING_RELAY_HMAC_SECRET)
 */
class BlingRelayController extends Controller
{
    #[OA\Post(
        path: '/api/oauth/bling/wl-relay',
        operationId: 'blingWlRelay',
        summary: 'Receiver do relay Bling — recebe tokens trocados pelo api.hubai.io',
        description: 'Recebe POST HMAC-assinado de api.hubai.io com tokens Bling pos-OAuth. Valida assinatura, valida tenant local, salva em MarketplaceAccount. Endpoint exposto em TODAS as WLs (multdrop, fornecefy, hubai) com o mesmo controller — diferencia tenant via APP_TENANT no .env.',
        tags: ['OAuth', 'Bling'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['tenant', 'client_id', 'access_token', 'refresh_token', 'expires_in'],
                properties: [
                    new OA\Property(property: 'tenant', type: 'string', example: 'multdrop'),
                    new OA\Property(property: 'client_id', type: 'integer', example: 1),
                    new OA\Property(property: 'supplier_id', type: 'integer', nullable: true, example: 1),
                    new OA\Property(property: 'access_token', type: 'string'),
                    new OA\Property(property: 'refresh_token', type: 'string'),
                    new OA\Property(property: 'expires_in', type: 'integer', example: 21600),
                    new OA\Property(property: 'scope', type: 'string', nullable: true),
                    new OA\Property(property: 'account_name', type: 'string', nullable: true),
                    new OA\Property(property: 'relayed_by', type: 'string', example: 'api.hubai.io'),
                    new OA\Property(property: 'user_email', type: 'string', nullable: true, example: 'snapmixbrasil@gmail.com'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Tokens salvos'),
            new OA\Response(response: 401, description: 'Assinatura HMAC invalida'),
            new OA\Response(response: 403, description: 'tenant payload nao bate com APP_TENANT (cross-tenant)'),
            new OA\Response(response: 422, description: 'Campos faltando'),
        ]
    )]
    public function receive(Request $request): JsonResponse
    {
        $relaySecret = (string) config('bling.relay_secret', '');
        $appTenant   = (string) config('bling.app_tenant', 'hubai');

        if ($relaySecret === '') {
            Log::error('[Bling WL Relay] BLING_RELAY_HMAC_SECRET nao configurada');
            return response()->json(['error' => 'relay_misconfigured'], 500);
        }

        // 1. Valida assinatura HMAC sobre raw body
        $sig      = (string) $request->header('X-HubAI-Bridge-Sig', '');
        $rawBody  = $request->getContent();
        $expected = hash_hmac('sha256', $rawBody, $relaySecret);

        if ($sig === '' || ! hash_equals($expected, $sig)) {
            Log::warning('[Bling WL Relay] Assinatura invalida', [
                'sig_recv' => substr($sig, 0, 16),
                'tenant'   => $appTenant,
                'ip'       => $request->ip(),
            ]);
            return response()->json(['error' => 'invalid_signature'], 401);
        }

        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            return response()->json(['error' => 'invalid_payload'], 422);
        }

        $payloadTenant = (string) ($payload['tenant'] ?? '');
        $clientId      = (int) ($payload['client_id'] ?? 0);
        $supplierId    = array_key_exists("supplier_id", $payload) && $payload["supplier_id"] !== null ? (int) $payload["supplier_id"] : null;
        $accountType   = isset($payload['account_type']) ? (string) $payload['account_type'] : null; // NOV-153
        $accessToken   = (string) ($payload['access_token'] ?? '');
        $refreshToken  = (string) ($payload['refresh_token'] ?? '');
        $expiresIn     = (int) ($payload['expires_in'] ?? 21600);
        $scope         = (string) ($payload['scope'] ?? '');
        $accountName   = (string) ($payload['account_name'] ?? 'Bling');
        $userEmail     = (string) ($payload['user_email'] ?? '');

        // 2. Anti cross-tenant: payload precisa indicar este sistema
        if ($payloadTenant !== $appTenant) {
            Log::warning('[Bling WL Relay] Tenant mismatch (cross-tenant blocked)', [
                'payload_tenant' => $payloadTenant,
                'app_tenant'     => $appTenant,
                'client_id'      => $clientId,
            ]);
            return response()->json(['error' => 'tenant_mismatch'], 403);
        }

        // 3. Validar campos obrigatorios
        if (! $accessToken) {
            return response()->json(['error' => 'missing_fields'], 422);
        }
        // Para fluxo lojista (default), client_id é obrigatório.
        // Para fluxo supplier_erp, supplier_id é obrigatório (client_id ainda é validado abaixo).
        if ($accountType !== 'supplier_erp' && ! $clientId) {
            return response()->json(['error' => 'missing_fields'], 422);
        }
        if ($accountType === 'supplier_erp' && ! $supplierId) {
            return response()->json(['error' => 'missing_supplier_id'], 422);
        }

        // SEL-372: supplier_id do payload vive no espaco de IDs do HUB (ex: 30=multdrop)
        // e pode nao existir neste banco WL — a FK marketplace_accounts.supplier_id explode
        // (cliente 765 nunca salvava Bling). Mesmo padrao SEL-077 do OAuthController:
        // se nao existe local, usa LOCAL_SUPPLIER_ID; se invalido, NULL.
        if ($supplierId !== null && ! \App\Models\Supplier::whereKey($supplierId)->exists()) {
            $fallback = (int) config('app.local_supplier_id', env('LOCAL_SUPPLIER_ID', 0));
            $resolved = ($fallback > 0 && \App\Models\Supplier::whereKey($fallback)->exists()) ? $fallback : null;
            Log::warning('[Bling WL Relay] supplier_id do hub nao existe local — fallback (SEL-372)', [
                'supplier_id_hub' => $supplierId,
                'fallback'        => $resolved,
                'tenant'          => $appTenant,
            ]);
            $supplierId = $resolved;
        }

        try {
            // SEL-325 (22/07): resolver via user_email quando disponivel (mesmo padrao MUL-183 ML).
            // Substitui find(clientId) cru que pode gravar tokens em cliente errado se houver
            // colisao numerica entre hub.client_id e WL.client_id — bug MUL-183 04/07 causou
            // contaminação de 2.487 produtos multdrop + 2.975 fornecefy pelo Super Admin.
            $client = null;
            if ($userEmail !== '') {
                $client = Client::whereHas('user', function ($q) use ($userEmail) {
                    $q->where('email', $userEmail);
                })->first();
                if ($client && $clientId && $client->id !== $clientId) {
                    Log::warning('[Bling WL Relay] client_id do hub divergiu do lookup por email — corrigido (SEL-325)', [
                        'raw_client_id_from_hub' => $clientId,
                        'user_email'             => $userEmail,
                        'resolved_client_id'     => $client->id,
                    ]);
                    $clientId = $client->id;
                } elseif (! $client) {
                    Log::warning('[Bling WL Relay] user_email do hub nao corresponde a nenhum usuario local (SEL-325)', [
                        'user_email'    => $userEmail,
                        'raw_client_id' => $clientId,
                    ]);
                }
            }

            // 4. Fallback: se lookup por email nao resolveu, cai no find(clientId) tradicional.
            if (! $client && $clientId) {
                $client = Client::find($clientId);
            }
            if ($clientId && ! $client) {
                Log::warning('[Bling WL Relay] Client nao encontrado', [
                    'client_id' => $clientId,
                    'tenant'    => $appTenant,
                ]);
                return response()->json(['error' => 'client_not_found', 'client_id' => $clientId], 404);
            }

            // NOV-153: desvio supplier_erp — salvar em erp_accounts.
            // Bling do FORNECEDOR (ERP), não do lojista. Token vai ser usado para importar
            // produtos/estoque do Bling do fornecedor para a plataforma e/ou exportar.
            // O cast 'encrypted' no ErpAccount cuida da criptografia automaticamente.
            if ($accountType === 'supplier_erp') {
                $erpAccount = ErpAccount::updateOrCreate(
                    [
                        'supplier_id' => $supplierId,
                        'platform'    => 'bling',
                    ],
                    [
                        'client_id'        => $clientId ?: null,
                        'access_token'     => $accessToken,
                        'refresh_token'    => $refreshToken,
                        'token_expires_at' => now()->addSeconds($expiresIn),
                        'status'           => 'active',
                        'account_name'     => $accountName,
                        'api_version'      => 'v3',
                        'last_sync_at'     => null,
                    ]
                );

                Log::channel('marketplace')->info('[Bling WL Relay] ErpAccount salvo via relay', [
                    'erp_account_id' => $erpAccount->id,
                    'supplier_id'    => $supplierId,
                    'tenant'         => $appTenant,
                    'relayed_by'     => $payload['relayed_by'] ?? null,
                ]);

                try {
                    MigracaoLogger::log('bling.relay.received.supplier_erp', $userEmail ?: ($client?->user?->email ?? ''), [
                        'tenant'         => $appTenant,
                        'supplier_id'    => $supplierId,
                        'erp_account_id' => $erpAccount->id,
                        'relayed_by'     => $payload['relayed_by'] ?? null,
                    ]);
                } catch (\Throwable $e) {
                    // log opcional
                }

                return response()->json([
                    'success'        => true,
                    'tenant'         => $appTenant,
                    'account_type'   => 'supplier_erp',
                    'erp_account_id' => $erpAccount->id,
                ]);
            }

            // 5. Upsert MarketplaceAccount (fluxo lojista — fluxo original sem alteração)
            $account = MarketplaceAccount::updateOrCreate(
                [
                    'client_id'   => $clientId,
                    'supplier_id' => $supplierId,
                    'platform'    => 'bling',
                ],
                [
                    'status'                   => 'active',
                    'centrally_managed'        => true, // MUL-188: hub central e dono da cadeia de tokens
                    'sync_blocked_at'          => null,
                    'access_token'             => encrypt($accessToken),
                    'refresh_token'            => encrypt($refreshToken),
                    'token_expires_at'         => now()->addSeconds($expiresIn),
                    'refresh_token_expires_at' => now()->addDays(30),
                    'last_token_refresh_at'    => now(),
                    'account_name'             => $accountName,
                    'scope'                    => $scope ?: null,
                    // NOV-077-P1: campos lidos por BlingAuthService::getValidToken()
                    'bling_access_token'       => encrypt($accessToken),
                    'bling_refresh_token'      => encrypt($refreshToken),
                    'bling_token_expires_at'   => now()->addSeconds($expiresIn),
                ]
            );

            Log::channel('marketplace')->info('[Bling WL Relay] Account salvo via relay', [
                'account_id' => $account->id,
                'client_id'  => $clientId,
                'tenant'     => $appTenant,
                'relayed_by' => $payload['relayed_by'] ?? null,
            ]);

            // 6. Migracao log (MUL-029)
            try {
                MigracaoLogger::log('bling.relay.received', $userEmail ?: ($client->user?->email ?? ''), [
                    'tenant'     => $appTenant,
                    'client_id'  => $clientId,
                    'account_id' => $account->id,
                    'relayed_by' => $payload['relayed_by'] ?? null,
                ]);
            } catch (\Throwable $e) {
                // log opcional
            }

            return response()->json([
                'success'    => true,
                'tenant'     => $appTenant,
                'account_id' => $account->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('[Bling WL Relay] Erro ao salvar', [
                'error'     => $e->getMessage(),
                'client_id' => $clientId,
                'tenant'    => $appTenant,
            ]);
            return response()->json(['error' => 'internal_error'], 500);
        }
    }
}
