<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessSupplierPaymentWebhookJob;
use App\Models\Supplier;
use App\Services\Integrations\Factories\PaymentGatewayFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

class SupplierPaymentWebhookController extends Controller
{
    #[OA\Post(
        path: '/api/webhooks/payment/{supplier_slug}/{gateway}',
        operationId: 'supplierPaymentWebhook',
        summary: 'Webhook de pagamento por fornecedor e gateway',
        description: 'Recebe notificacoes de pagamento para um fornecedor especifico. Valida: (1) existencia do fornecedor pelo slug, (2) configuracao de pagamento ativa e gateway correspondente, (3) assinatura HMAC via X-Webhook-Signature, asaas-access-token ou x-hub-signature quando webhook_secret estiver configurado. Retorna 200 imediatamente e despacha ProcessSupplierPaymentWebhookJob.',
        tags: ['Webhooks'],
        parameters: [
            new OA\Parameter(
                name: 'supplier_slug',
                in: 'path',
                required: true,
                description: 'Slug do fornecedor cadastrado no HubAI',
                schema: new OA\Schema(type: 'string'),
                example: 'distribuidora-alpha'
            ),
            new OA\Parameter(
                name: 'gateway',
                in: 'path',
                required: true,
                description: 'Gateway de pagamento do fornecedor',
                schema: new OA\Schema(type: 'string', enum: ['asaas', 'shipay', 'pagarme']),
                example: 'asaas'
            ),
            new OA\Parameter(
                name: 'X-Webhook-Signature',
                in: 'header',
                required: false,
                description: 'Assinatura HMAC do payload (formato varia por gateway)',
                schema: new OA\Schema(type: 'string'),
                example: 'sha256=abcdef1234567890'
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            description: 'Payload do evento de pagamento (estrutura varia por gateway)',
            content: new OA\JsonContent(
                type: 'object',
                example: ['event' => 'PAYMENT_RECEIVED', 'payment' => ['id' => 'pay_abc123', 'value' => 99.90, 'status' => 'RECEIVED']]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Evento enfileirado com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'ok'),
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Configuracao de pagamento invalida ou gateway incompativel',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'error', type: 'string', example: 'Invalid configuration'),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Assinatura HMAC invalida',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'error', type: 'string', example: 'Invalid signature'),
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Fornecedor nao encontrado',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'error', type: 'string', example: 'Supplier not found'),
                    ]
                )
            ),
        ]
    )]
    /**
     * Recebe webhooks de pagamento por fornecedor e gateway.
     *
     * Rota: POST /api/webhooks/payment/{supplier_slug}/{gateway}
     *
     * Responsabilidades:
     *  1. Validar supplier e configuracao
     *  2. Verificar assinatura HMAC (quando webhook_secret estiver configurado)
     *  3. Retornar 200 imediatamente para o gateway
     *  4. Despachar processamento assincrono via job
     */
    public function handle(Request $request, string $supplierSlug, string $gateway): JsonResponse
    {
        // 1. Buscar supplier pelo slug
        $supplier = Supplier::where('slug', $supplierSlug)->first();

        if (! $supplier) {
            Log::warning('Webhook supplier: fornecedor não encontrado', [
                'slug'    => $supplierSlug,
                'gateway' => $gateway,
                'ip'      => $request->ip(),
            ]);

            return response()->json(['error' => 'Supplier not found'], 404);
        }

        // 2. Buscar e validar configuração de pagamento
        $settings = $supplier->paymentSetting;

        if (! $settings || ! $settings->is_active || $settings->gateway !== $gateway) {
            Log::warning('Webhook supplier: configuração inválida', [
                'supplier_id'    => $supplier->id,
                'gateway'        => $gateway,
                'settings_exist' => (bool) $settings,
                'settings_active'=> $settings?->is_active,
                'settings_gw'    => $settings?->gateway,
            ]);

            return response()->json(['error' => 'Invalid configuration'], 400);
        }

        // 3. Verificar assinatura HMAC (somente se webhook_secret estiver definido)
        if ($settings->webhook_secret) {
            $payload   = $request->getContent();
            $signature = $request->header('X-Webhook-Signature')
                ?? $request->header('asaas-access-token')
                ?? $request->header('x-hub-signature')
                ?? '';

            try {
                $gatewayService = PaymentGatewayFactory::makeForSupplier($supplier);
                $valid          = $gatewayService->verifyWebhookSignature($payload, $signature, $settings->webhook_secret);
            } catch (\RuntimeException $e) {
                Log::error('Webhook supplier: erro ao instanciar gateway para verificação', [
                    'supplier_id' => $supplier->id,
                    'gateway'     => $gateway,
                    'error'       => $e->getMessage(),
                ]);

                return response()->json(['error' => 'Gateway error'], 500);
            }

            if (! $valid) {
                Log::warning('Webhook supplier: assinatura HMAC inválida', [
                    'supplier_id' => $supplier->id,
                    'gateway'     => $gateway,
                    'ip'          => $request->ip(),
                ]);

                return response()->json(['error' => 'Invalid signature'], 401);
            }
        }

        // 4. Logar recebimento e despachar job assíncrono
        Log::info('Webhook supplier: recebido, despachando para fila', [
            'supplier_id' => $supplier->id,
            'gateway'     => $gateway,
        ]);

        $rawPayload = json_decode($request->getContent(), true) ?? $request->all();

        ProcessSupplierPaymentWebhookJob::dispatch(
            $rawPayload,
            $supplier->id,
            $gateway,
        );

        return response()->json(['status' => 'ok'], 200);
    }
}
