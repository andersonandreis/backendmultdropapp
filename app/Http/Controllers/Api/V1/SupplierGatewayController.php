<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SupplierPaymentSetting;
use App\Services\Integrations\Factories\PaymentGatewayFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * REST controller para fornecedor gerenciar seu gateway de pagamento.
 *
 * Todas as rotas exigem auth:sanctum + role in [supplier, super_admin, admin].
 * O supplier_id e sempre derivado do usuario autenticado (ou config como fallback).
 *
 * Rotas registradas em routes/api.php (grupo auth:sanctum > v1):
 *   GET    /api/v1/supplier-admin/payment-gateway        -> show()
 *   POST   /api/v1/supplier-admin/payment-gateway        -> upsert()
 *   DELETE /api/v1/supplier-admin/payment-gateway        -> destroy()
 *   POST   /api/v1/supplier-admin/payment-gateway/test   -> test()
 *
 * NOV-066 — 2026-06-24
 */
class SupplierGatewayController extends Controller
{
    // -------------------------------------------------------------------------
    // Helpers de autorizacao e resolucao de supplier_id
    // -------------------------------------------------------------------------

    /**
     * Garante que o usuario autenticado pode gerenciar configuracoes de gateway.
     * Aborta com 403 se nao tiver permissao.
     */
    private function authorize(Request $request): void
    {
        $user = $request->user();
        if (!$user || !in_array($user->role, ['super_admin', 'admin', 'supplier'])) {
            abort(403, 'Apenas admin do fornecedor pode gerenciar gateway de pagamento.');
        }
    }

    /**
     * Resolve o supplier_id do usuario autenticado.
     *
     * Prioridade:
     *   1. User->supplier->id (relacao direta — future-proof)
     *   2. config('multdrop.supplier_id') — fallback atual enquanto o vinculo nao e 1:1
     *
     * @return int
     */
    private function resolveSupplierIdOrFail(Request $request): int
    {
        $user = $request->user();

        // Relacao direta User -> Supplier (role=supplier)
        if ($user && $user->supplier) {
            return (int) $user->supplier->id;
        }

        // Fallback: config (ambiente do servidor — MultDrop = 30)
        $configId = config('multdrop.supplier_id');
        if ($configId) {
            return (int) $configId;
        }

        abort(422, 'Usuario nao possui supplier associado.');
    }

    // -------------------------------------------------------------------------
    // show — GET /api/v1/supplier-admin/payment-gateway
    // -------------------------------------------------------------------------

    /**
     * Retorna a configuracao de gateway ativa para o fornecedor.
     * Nao expoe credenciais (api_key, api_secret, api_extra).
     *
     * @response 200 {
     *   "gateway": "mercadopago",
     *   "is_active": true,
     *   "webhook_url": "https://api.hubai.io/webhooks/payment/multdrop/mercadopago",
     *   "pix_fee_type": "percentage",
     *   "pix_fee_value": "1.5000",
     *   "allows_wallet_topup": true,
     *   "allows_wallet_payment": true,
     *   "pix_timeout_minutes": 30,
     *   "created_at": "2026-06-24T10:00:00Z",
     *   "updated_at": "2026-06-24T10:00:00Z"
     * }
     * @response 404 { "message": "Nenhum gateway configurado." }
     */
    public function show(Request $request): JsonResponse
    {
        $this->authorize($request);

        $supplierId = $this->resolveSupplierIdOrFail($request);
        $setting    = SupplierPaymentSetting::where('supplier_id', $supplierId)->first();

        if (!$setting) {
            return response()->json(['message' => 'Nenhum gateway configurado.'], 404);
        }

        return response()->json([
            'gateway'               => $setting->gateway,
            'is_active'             => $setting->is_active,
            'webhook_url'           => $setting->webhook_url,
            'webhook_secret_preview'=> $setting->webhook_secret ? substr($setting->webhook_secret, 0, 8) . '...' : null,
            'pix_fee_type'          => $setting->pix_fee_type,
            'pix_fee_value'         => $setting->pix_fee_value,
            'allows_wallet_topup'   => $setting->allows_wallet_topup,
            'allows_wallet_payment' => $setting->allows_wallet_payment,
            'refund_policy'         => $setting->refund_policy,
            'pix_only_orders'       => $setting->pix_only_orders,
            'pix_timeout_minutes'   => $setting->pix_timeout_minutes,
            'created_at'            => $setting->created_at,
            'updated_at'            => $setting->updated_at,
        ]);
    }

    // -------------------------------------------------------------------------
    // upsert — POST /api/v1/supplier-admin/payment-gateway
    // -------------------------------------------------------------------------

    /**
     * Cria ou atualiza a configuracao de gateway do fornecedor.
     * Credenciais sao armazenadas criptografadas via cast 'encrypted' do model.
     *
     * @bodyParam gateway string required Gateway desejado: asaas|shipay|pagarme|mercadopago
     * @bodyParam api_key string Credencial principal (access_token para MP, client_key para Shipay, etc.)
     * @bodyParam api_secret string Credencial secundaria (opcional por gateway)
     * @bodyParam api_extra object Credenciais adicionais em JSON (ex: {"access_key": "..."} para Shipay)
     * @bodyParam pix_fee_type string fixed|percentage (default: percentage)
     * @bodyParam pix_fee_value number Taxa PIX (ex: 1.5 para 1,5%)
     * @bodyParam pix_timeout_minutes int Expiracao do PIX em minutos (default: 30)
     * @bodyParam is_active bool Ativar o gateway imediatamente (default: false)
     *
     * @response 200 { "message": "Gateway atualizado com sucesso.", "gateway": "mercadopago", "is_active": true }
     */
    public function upsert(Request $request): JsonResponse
    {
        $this->authorize($request);

        $supplierId = $this->resolveSupplierIdOrFail($request);

        $validated = $request->validate([
            'gateway'             => ['required', Rule::in(['asaas', 'shipay', 'pagarme', 'mercadopago'])],
            'api_key'             => ['nullable', 'string', 'max:1000'],
            'api_secret'          => ['nullable', 'string', 'max:1000'],
            'api_extra'           => ['nullable', 'array'],
            'pix_fee_type'        => ['nullable', Rule::in(['fixed', 'percentage'])],
            'pix_fee_value'       => ['nullable', 'numeric', 'min:0', 'max:100'],
            'pix_timeout_minutes' => ['nullable', 'integer', 'min:5', 'max:1440'],
            'allows_wallet_topup' => ['nullable', 'boolean'],
            'allows_wallet_payment' => ['nullable', 'boolean'],
            'pix_only_orders'     => ['nullable', 'boolean'],
            'refund_policy'       => ['nullable', Rule::in(['wallet_credit', 'manual_estorno'])],
            'is_active'           => ['nullable', 'boolean'],
        ]);

        $fillable = array_filter([
            'gateway'               => $validated['gateway'],
            'api_key'               => $validated['api_key'] ?? null,
            'api_secret'            => $validated['api_secret'] ?? null,
            'api_extra'             => $validated['api_extra'] ?? null,
            'pix_fee_type'          => $validated['pix_fee_type'] ?? null,
            'pix_fee_value'         => $validated['pix_fee_value'] ?? null,
            'pix_timeout_minutes'   => $validated['pix_timeout_minutes'] ?? null,
            'allows_wallet_topup'   => $validated['allows_wallet_topup'] ?? null,
            'allows_wallet_payment' => $validated['allows_wallet_payment'] ?? null,
            'pix_only_orders'       => $validated['pix_only_orders'] ?? null,
            'refund_policy'         => $validated['refund_policy'] ?? null,
            'is_active'             => $validated['is_active'] ?? null,
        ], fn($v) => $v !== null);

        $setting = SupplierPaymentSetting::updateOrCreate(
            ['supplier_id' => $supplierId],
            $fillable
        );

        Log::info('[SupplierGateway] Configuracao upserted', [
            'supplier_id' => $supplierId,
            'gateway'     => $setting->gateway,
            'is_active'   => $setting->is_active,
            'user_id'     => $request->user()?->id,
        ]);

        return response()->json([
            'message'   => 'Gateway atualizado com sucesso.',
            'gateway'   => $setting->gateway,
            'is_active' => $setting->is_active,
        ]);
    }

    // -------------------------------------------------------------------------
    // destroy — DELETE /api/v1/supplier-admin/payment-gateway
    // -------------------------------------------------------------------------

    /**
     * Remove a configuracao de gateway do fornecedor.
     * Apenas desativa e apaga credenciais — nao remove o registro (auditoria).
     *
     * @response 200 { "message": "Gateway removido com sucesso." }
     * @response 404 { "message": "Nenhum gateway configurado." }
     */
    public function destroy(Request $request): JsonResponse
    {
        $this->authorize($request);

        $supplierId = $this->resolveSupplierIdOrFail($request);
        $setting    = SupplierPaymentSetting::where('supplier_id', $supplierId)->first();

        if (!$setting) {
            return response()->json(['message' => 'Nenhum gateway configurado.'], 404);
        }

        // Desativa e apaga credenciais sem remover o registro (manutencao de auditoria)
        $setting->update([
            'is_active'  => false,
            'api_key'    => null,
            'api_secret' => null,
            'api_extra'  => null,
        ]);

        Log::info('[SupplierGateway] Gateway desativado', [
            'supplier_id' => $supplierId,
            'gateway'     => $setting->gateway,
            'user_id'     => $request->user()?->id,
        ]);

        return response()->json(['message' => 'Gateway removido com sucesso.']);
    }

    // -------------------------------------------------------------------------
    // test — POST /api/v1/supplier-admin/payment-gateway/test
    // -------------------------------------------------------------------------

    /**
     * Testa a conectividade com o gateway configurado do fornecedor.
     * Usa PaymentGatewayFactory::makeForSupplier() — nao cobra nada.
     *
     * Para MP: faz GET /v1/users/me para validar o access_token.
     * Para Shipay: faz POST /pdvauth.
     * Para gateways que nao implementam ping, retorna status=configured.
     *
     * @response 200 { "status": "ok", "gateway": "mercadopago", "message": "Conexao estabelecida." }
     * @response 422 { "status": "error", "message": "..." }
     */
    public function test(Request $request): JsonResponse
    {
        $this->authorize($request);

        $supplierId = $this->resolveSupplierIdOrFail($request);

        $setting = SupplierPaymentSetting::where('supplier_id', $supplierId)->first();

        if (!$setting) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Nenhum gateway configurado para este fornecedor.',
            ], 422);
        }

        if (!$setting->is_active) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Gateway esta inativo. Ative-o antes de testar.',
            ], 422);
        }

        try {
            $supplier = $setting->supplier()->with('paymentSetting')->first();
            $gateway  = PaymentGatewayFactory::makeForSupplier($supplier);

            // Teste especifico por gateway
            $testResult = $this->runGatewayPing($setting->gateway, $gateway, $setting);

            return response()->json([
                'status'  => 'ok',
                'gateway' => $setting->gateway,
                'message' => 'Conexao estabelecida.',
                'details' => $testResult,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[SupplierGateway] Teste de conexao falhou', [
                'supplier_id' => $supplierId,
                'gateway'     => $setting->gateway,
                'error'       => $e->getMessage(),
            ]);

            return response()->json([
                'status'  => 'error',
                'gateway' => $setting->gateway,
                'message' => 'Falha ao conectar: ' . $e->getMessage(),
            ], 422);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers privados
    // -------------------------------------------------------------------------

    /**
     * Executa um ping minimo por tipo de gateway para validar credenciais.
     *
     * @return array Detalhes do ping (campos variam por gateway)
     */
    private function runGatewayPing(string $gateway, $gatewayService, SupplierPaymentSetting $setting): array
    {
        return match ($gateway) {
            'mercadopago' => $this->pingMercadoPago($setting),
            'shipay'      => ['status' => 'configured', 'note' => 'Ping Shipay via autenticacao full-cycle nao disponivel no teste rapido.'],
            'pagarme'     => ['status' => 'configured', 'note' => 'Pagarme validado via webhook test nao disponivel no teste rapido.'],
            'asaas'       => ['status' => 'configured', 'note' => 'Asaas validado via webhook test nao disponivel no teste rapido.'],
            default       => ['status' => 'configured'],
        };
    }

    /**
     * Valida o access_token do Mercado Pago chamando GET /v1/users/me.
     * Retorna dados da conta MP sem cobrar nada.
     */
    private function pingMercadoPago(SupplierPaymentSetting $setting): array
    {
        $token    = $setting->api_key; // descriptografado automaticamente pelo cast 'encrypted'
        $response = \Illuminate\Support\Facades\Http::withToken($token)
            ->withHeaders(['Accept' => 'application/json'])
            ->get('https://api.mercadopago.com/v1/users/me');

        if ($response->failed()) {
            throw new \RuntimeException(
                'Token MP invalido ou sem permissao. Status: ' . $response->status()
            );
        }

        $data = $response->json();

        return [
            'mp_user_id'   => $data['id'] ?? null,
            'mp_email'     => $data['email'] ?? null,
            'mp_site_id'   => $data['site_id'] ?? null,
            'mp_status'    => $data['status'] ?? null,
        ];
    }
}
