<?php

namespace App\Services\Integrations\Payments;

use App\Models\Client;
use App\Models\Order;
use App\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Gateway Mercado Pago para pagamentos PIX supplier-driven no HubAI.
 *
 * Documentacao API: https://www.mercadopago.com.br/developers/pt/reference
 * Base URL: https://api.mercadopago.com
 * Auth: Bearer access_token (token permanente ou OAuth2)
 *
 * Fluxo PIX:
 *   POST /v1/payments com payment_method_id=pix
 *   Retorna: point_of_interaction.transaction_data.qr_code + qr_code_base64
 *
 * Webhook signature: header x-signature formato
 *   ts=<timestamp>,v1=<hmac_sha256(ts + "." + x-request-id + "." + body, client_secret)>
 *
 * Credencial necessaria: access_token (armazenado em api_key do SupplierPaymentSetting)
 *
 * NOV-066 — 2026-06-24
 */
class MercadoPagoGatewayService extends AbstractPaymentGateway
{
    protected string $baseUrl = 'https://api.mercadopago.com';
    protected string $accessToken;

    /**
     * supplier_id injetado pelo PaymentGatewayFactory::makeForSupplier() via configure().
     * Usado para o idempotency_key e logs.
     */
    protected ?int $supplierId = null;

    public function __construct()
    {
        $this->accessToken = config('services.mercadopago.access_token', env('MP_ACCESS_TOKEN', ''));
    }

    // -------------------------------------------------------------------------
    // Interface: configure
    // -------------------------------------------------------------------------

    /**
     * MP usa access_token unico (Bearer).
     * O factory passa api_key = access_token (campo canonico do SupplierPaymentSetting).
     *
     * @param array $credentials Chaves esperadas: api_key (access_token), supplier_id
     */
    public function configure(array $credentials): void
    {
        if (!empty($credentials['api_key'])) {
            $this->accessToken = $credentials['api_key'];
        }
        if (!empty($credentials['supplier_id'])) {
            $this->supplierId = (int) $credentials['supplier_id'];
        }
    }

    // -------------------------------------------------------------------------
    // HTTP helper
    // -------------------------------------------------------------------------

    protected function http(string $idempotencyKey = ''): \Illuminate\Http\Client\PendingRequest
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ];

        if ($idempotencyKey !== '') {
            $headers['X-Idempotency-Key'] = $idempotencyKey;
        }

        return Http::withToken($this->accessToken)->withHeaders($headers);
    }

    // -------------------------------------------------------------------------
    // createOrderPayment
    // -------------------------------------------------------------------------

    /**
     * Cria pagamento PIX no Mercado Pago para um pedido HubAI.
     * POST /v1/payments
     *
     * @param Order $order
     * @param array $paymentDetails Aceita: buyer (email, first_name, last_name, cpf)
     * @return array{status: string, qr_code: string, qr_code_text: string, external_id: string}
     */
    public function createOrderPayment(Order $order, array $paymentDetails = []): array
    {
        $client      = $order->client;
        $idempotency = 'hubai-order-' . $order->id . '-' . ($this->supplierId ?? 0);
        $externalRef = 'order-' . ($order->order_number ?? $order->id);
        $description = 'Pedido #' . ($order->order_number ?? $order->id);

        $payload = [
            'transaction_amount' => (float) ($paymentDetails['amount'] ?? $order->supplier_total ?? $order->total),
            'description'        => $description,
            'payment_method_id'  => 'pix',
            'external_reference' => $externalRef,
            'payer'              => [
                'email'          => $paymentDetails['buyer']['email'] ?? ($client->email ?? 'pagador@hubai.io'),
                'first_name'     => $paymentDetails['buyer']['first_name'] ?? ($client->company_name ?? 'Cliente'),
                'last_name'      => $paymentDetails['buyer']['last_name'] ?? '',
                'identification' => [
                    'type'   => 'CPF',
                    'number' => preg_replace('/\D/', '', $paymentDetails['buyer']['cpf'] ?? ($client->document ?? '')),
                ],
            ],
        ];

        $response = $this->http($idempotency)->post("{$this->baseUrl}/v1/payments", $payload);

        if ($response->failed()) {
            Log::error('[MercadoPago] createOrderPayment erro', [
                'supplier_id' => $this->supplierId,
                'order_id'    => $order->id,
                'status'      => $response->status(),
                'body'        => $response->body(),
            ]);
            throw new \RuntimeException('[MercadoPago] createOrderPayment: ' . $response->body());
        }

        $data = $response->json();

        return [
            'status'       => 'SUCCESS',
            'qr_code'      => $data['point_of_interaction']['transaction_data']['qr_code_base64'] ?? '',
            'qr_code_text' => $data['point_of_interaction']['transaction_data']['qr_code'] ?? '',
            'external_id'  => (string) ($data['id'] ?? ''),
        ];
    }

    // -------------------------------------------------------------------------
    // createPixQrCode
    // -------------------------------------------------------------------------

    /**
     * Gera QR Code PIX para pagamento de pedido (assinatura da interface).
     * POST /v1/payments com payment_method_id=pix e date_of_expiration.
     *
     * @param Order  $order          Pedido de referencia
     * @param float  $amount         Valor a cobrar
     * @param string $idempotencyKey Chave unica de idempotencia (ex: wallet-topup-{id})
     * @return array{external_id: string, qr_code: string, qr_code_text: string, expires_at: \Carbon\Carbon}
     */
    public function createPixQrCode(Order $order, float $amount, string $idempotencyKey): array
    {
        $client    = $order->client;
        $expiresAt = Carbon::now()->addMinutes(30);

        $payload = [
            'transaction_amount' => $amount,
            'description'        => "Pedido #{$order->id}",
            'payment_method_id'  => 'pix',
            'external_reference' => $idempotencyKey,
            'date_of_expiration' => $expiresAt->toIso8601String(),
            'payer'              => [
                'email'          => $client->email ?? 'pagador@hubai.io',
                'first_name'     => $client->company_name ?? 'Cliente',
                'last_name'      => '',
                'identification' => [
                    'type'   => 'CPF',
                    'number' => preg_replace('/\D/', '', $client->document ?? ''),
                ],
            ],
        ];

        $response = $this->http($idempotencyKey)->post("{$this->baseUrl}/v1/payments", $payload);

        if ($response->failed()) {
            Log::error('[MercadoPago] createPixQrCode erro', [
                'supplier_id'     => $this->supplierId,
                'order_id'        => $order->id,
                'idempotency_key' => $idempotencyKey,
                'status'          => $response->status(),
                'body'            => $response->body(),
            ]);
            throw new \RuntimeException('[MercadoPago] createPixQrCode: ' . $response->body());
        }

        $data = $response->json();

        return [
            'external_id'  => (string) ($data['id'] ?? ''),
            'qr_code'      => $data['point_of_interaction']['transaction_data']['qr_code_base64'] ?? '',
            'qr_code_text' => $data['point_of_interaction']['transaction_data']['qr_code'] ?? '',
            'expires_at'   => $expiresAt,
        ];
    }

    // -------------------------------------------------------------------------
    // getPaymentStatus
    // -------------------------------------------------------------------------

    /**
     * Consulta status de um payment MP e mapeia para status local.
     * GET /v1/payments/{id}
     *
     * MP status mapeado:
     *   approved                              -> paid
     *   pending, in_process, in_mediation     -> pending
     *   cancelled, rejected, charged_back     -> expired
     *   refunded                              -> refunded
     *
     * @param string $paymentId ID do payment no MP (numerico como string)
     */
    public function getPaymentStatus(string $paymentId): string
    {
        $response = $this->http()->get("{$this->baseUrl}/v1/payments/{$paymentId}");

        if ($response->failed()) {
            Log::error('[MercadoPago] getPaymentStatus erro', [
                'payment_id' => $paymentId,
                'status'     => $response->status(),
                'body'       => $response->body(),
            ]);
            throw new \RuntimeException('[MercadoPago] getPaymentStatus: ' . $response->body());
        }

        $mpStatus = $response->json('status');

        return match ($mpStatus) {
            'approved'                               => 'paid',
            'pending', 'in_process', 'in_mediation'  => 'pending',
            'cancelled', 'rejected', 'charged_back'  => 'expired',
            'refunded'                               => 'refunded',
            default                                  => 'pending',
        };
    }

    // -------------------------------------------------------------------------
    // refundPayment
    // -------------------------------------------------------------------------

    /**
     * Estorna total ou parcialmente um pagamento MP.
     * POST /v1/payments/{id}/refunds
     *
     * Se amount = 0 ou nao informado, realiza estorno total.
     */
    public function refundPayment(string $externalId, float $amount, string $reason): bool
    {
        $payload = [];
        if ($amount > 0) {
            $payload['amount'] = $amount;
        }

        $idempotency = 'refund-' . $externalId . '-' . time();
        $response    = $this->http($idempotency)
            ->post("{$this->baseUrl}/v1/payments/{$externalId}/refunds", $payload);

        if ($response->failed()) {
            Log::error('[MercadoPago] refundPayment erro', [
                'payment_id' => $externalId,
                'amount'     => $amount,
                'reason'     => $reason,
                'status'     => $response->status(),
                'body'       => $response->body(),
            ]);
            return false;
        }

        Log::info('[MercadoPago] Estorno criado', [
            'payment_id' => $externalId,
            'refund_id'  => $response->json('id'),
            'amount'     => $amount,
            'reason'     => $reason,
        ]);

        return true;
    }

    // -------------------------------------------------------------------------
    // createSubscription / cancelSubscription — nao suportado nesta versao
    // -------------------------------------------------------------------------

    public function createSubscription(Client $client, int $planId, array $paymentDetails): Subscription
    {
        throw new \RuntimeException(
            'MercadoPagoGatewayService nao suporta assinaturas recorrentes nesta versao. Use PagarmeService ou AsaasService.'
        );
    }

    public function cancelSubscription(Subscription $subscription): bool
    {
        throw new \RuntimeException(
            'MercadoPagoGatewayService nao suporta cancelamento de assinaturas.'
        );
    }

    // -------------------------------------------------------------------------
    // verifyWebhookSignature
    // -------------------------------------------------------------------------

    /**
     * Verifica assinatura HMAC do webhook do Mercado Pago.
     *
     * MP envia header x-signature no formato:
     *   ts=<unix_timestamp>,v1=<hmac_sha256_hex>
     *
     * O $signature recebido deve ser o valor v1= extraido pelo caller
     * (SupplierPaymentWebhookController). O $secret deve ser o webhook_secret
     * armazenado em supplier_payment_settings.
     *
     * Modo de verificacao: HMAC-SHA256 do payload bruto com o secret.
     * Compativel com o padrao adotado pelo Shipay e demais gateways do HubAI.
     *
     * @param string $payload   Corpo raw do request
     * @param string $signature Hash v1= extraido do header x-signature
     * @param string $secret    webhook_secret armazenado no banco
     */
    public function verifyWebhookSignature(string $payload, string $signature, string $secret): bool
    {
        $expected = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signature);
    }
}
