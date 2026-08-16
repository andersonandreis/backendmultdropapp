<?php

namespace App\Services\Drop\Payment;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Driver Mercado Pago para checkout da Loja Nativa.
 * Usa as credenciais do seller (access_token MP), dinheiro vai direto para ele.
 */
class MercadoPagoDropService
{
    private const BASE_URL = 'https://api.mercadopago.com';

    private string $accessToken;

    public function __construct(string $accessToken)
    {
        $this->accessToken = $accessToken;
    }

    public function createPixPayment(array $order): array
    {
        try {
            $payload = [
                'transaction_amount' => (float) $order['total_amount'],
                'description'        => 'Pedido #' . ($order['order_key'] ?? ''),
                'payment_method_id'  => 'pix',
                'payer' => [
                    'email'           => $order['customer_email'],
                    'first_name'      => $order['customer_name'],
                    'identification'  => [
                        'type'   => 'CPF',
                        'number' => preg_replace('/\D/', '', $order['customer_cpf'] ?? ''),
                    ],
                ],
                'notification_url' => config('app.url') . '/api/webhooks/drop/mercadopago',
                'external_reference' => $order['order_key'] ?? '',
                'metadata' => [
                    'drop_order_key' => $order['order_key'] ?? '',
                    'store_slug'     => $order['store_slug'] ?? '',
                ],
            ];

            $response = Http::withToken($this->accessToken)
                ->timeout(30)
                ->post(self::BASE_URL . '/v1/payments', $payload);

            if (!$response->successful()) {
                Log::warning('MercadoPago Drop: erro ao criar pagamento PIX', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return ['success' => false, 'error' => $response->json('message', 'Erro desconhecido')];
            }

            $data = $response->json();

            return [
                'success'       => true,
                'payment_id'    => $data['id'],
                'status'        => $data['status'],
                'qr_code'       => $data['point_of_interaction']['transaction_data']['qr_code'] ?? null,
                'qr_code_base64'=> $data['point_of_interaction']['transaction_data']['qr_code_base64'] ?? null,
                'expires_at'    => $data['date_of_expiration'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::error('MercadoPago Drop: excecao ao criar PIX', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function createCardPayment(array $order, string $cardToken): array
    {
        try {
            $installments = max(1, (int) ($order['installments'] ?? 1));

            $payload = [
                'transaction_amount'  => (float) $order['total_amount'],
                'token'               => $cardToken,
                'description'         => 'Pedido #' . ($order['order_key'] ?? ''),
                'installments'        => $installments,
                'payment_method_id'   => $order['payment_method_id'] ?? 'visa',
                'payer' => [
                    'email'           => $order['customer_email'],
                    'identification'  => [
                        'type'   => 'CPF',
                        'number' => preg_replace('/\D/', '', $order['customer_cpf'] ?? ''),
                    ],
                ],
                'notification_url'   => config('app.url') . '/api/webhooks/drop/mercadopago',
                'external_reference' => $order['order_key'] ?? '',
            ];

            $response = Http::withToken($this->accessToken)
                ->timeout(30)
                ->post(self::BASE_URL . '/v1/payments', $payload);

            if (!$response->successful()) {
                return ['success' => false, 'error' => $response->json('message', 'Erro no cartao')];
            }

            $data = $response->json();

            return [
                'success'    => in_array($data['status'], ['approved', 'in_process']),
                'payment_id' => $data['id'],
                'status'     => $data['status'],
                'detail'     => $data['status_detail'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::error('MercadoPago Drop: excecao cartao', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function getPaymentStatus(string $paymentId): array
    {
        try {
            $response = Http::withToken($this->accessToken)
                ->timeout(15)
                ->get(self::BASE_URL . '/v1/payments/' . $paymentId);

            if (!$response->successful()) {
                return ['status' => 'error', 'error' => $response->body()];
            }

            $data = $response->json();

            return [
                'payment_id'  => $data['id'],
                'status'      => $data['status'],
                'detail'      => $data['status_detail'] ?? null,
                'amount'      => $data['transaction_amount'] ?? null,
                'approved_at' => $data['date_approved'] ?? null,
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    public function handleWebhook(array $payload): array
    {
        $paymentId = $payload['data']['id'] ?? null;
        if (!$paymentId) {
            return ['handled' => false, 'reason' => 'no payment_id'];
        }

        $status = $this->getPaymentStatus((string) $paymentId);

        return [
            'handled'    => true,
            'payment_id' => $paymentId,
            'status'     => $status['status'] ?? 'unknown',
        ];
    }
}
