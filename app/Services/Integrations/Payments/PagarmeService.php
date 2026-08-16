<?php

namespace App\Services\Integrations\Payments;

use App\Models\Client;
use App\Models\Order;
use App\Models\Subscription;
use App\Services\Integrations\Contracts\PaymentGatewayInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Implementacao do gateway Pagar.me (API v5) para o HubAI.
 *
 * Base URL: https://api.pagar.me/core/v5/
 * Auth: Basic base64(api_key:)  -- senha vazia
 */
class PagarmeService extends AbstractPaymentGateway
{
    protected string $baseUrl = 'https://api.pagar.me/core/v5';
    protected string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.pagarme.api_key', env('PAGARME_API_KEY'));
    }

    /**
     * Recebe credenciais dinamicas vindas de SupplierPaymentSetting.
     */
    public function configure(array $credentials): void
    {
        if (!empty($credentials['api_key'])) {
            $this->apiKey = $credentials['api_key'];
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function http(): \Illuminate\Http\Client\PendingRequest
    {
        $token = base64_encode($this->apiKey . ':');
        return Http::withHeaders([
            'Authorization' => 'Basic ' . $token,
            'Content-Type'  => 'application/json',
        ]);
    }

    protected function throwOnFailure(\Illuminate\Http\Client\Response $response, string $context): void
    {
        if ($response->failed()) {
            Log::error("[Pagarme] Falha em {$context}", [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException("[Pagarme] {$context}: " . $response->body());
        }
    }

    // -------------------------------------------------------------------------
    // Customer
    // -------------------------------------------------------------------------

    /**
     * Cria ou atualiza um customer no Pagar.me.
     * data: name, email, document (CPF/CNPJ), phone, type (individual|company)
     */
    public function createCustomer(array $data): array
    {
        $response = $this->http()->post("{$this->baseUrl}/customers", [
            'name'     => $data['name'],
            'email'    => $data['email'],
            'type'     => $data['type'] ?? 'individual',
            'document' => preg_replace('/\D/', '', $data['document'] ?? ''),
            'phones'   => [
                'mobile_phone' => [
                    'country_code' => '55',
                    'area_code'    => substr(preg_replace('/\D/', '', $data['phone'] ?? '0000000000'), 0, 2),
                    'number'       => substr(preg_replace('/\D/', '', $data['phone'] ?? '0000000000'), 2),
                ],
            ],
        ]);

        $this->throwOnFailure($response, 'createCustomer');
        return $response->json();
    }

    /**
     * Obtem ou cria o pagarme_customer_id para um Client.
     */
    protected function getOrCreateCustomer(Client $client): string
    {
        if ($client->pagarme_customer_id) {
            return $client->pagarme_customer_id;
        }

        $customerData = $this->createCustomer([
            'name'     => $client->company_name,
            'email'    => $client->user->email ?? '',
            'document' => $client->document ?? '',
            'phone'    => $client->phone ?? '',
            'type'     => str_contains($client->document ?? '', '/') ? 'company' : 'individual',
        ]);

        $client->update(['pagarme_customer_id' => $customerData['id']]);

        return $customerData['id'];
    }

    // -------------------------------------------------------------------------
    // Subscription (SaaS billing para planos de sellers)
    // -------------------------------------------------------------------------

    /**
     * Cria assinatura recorrente no Pagar.me.
     * data: plan_id (pagarme), payment_method, card_token (opcional), etc.
     */
    public function createSubscription(Client $client, int $planId, array $paymentDetails): Subscription
    {
        $customerId = $this->getOrCreateCustomer($client);

        $payload = [
            'customer_id'    => $customerId,
            'payment_method' => $paymentDetails['payment_method'] ?? 'credit_card',
            'plan_id'        => $paymentDetails['pagarme_plan_id'],
            'card_token'     => $paymentDetails['card_token'] ?? null,
            'metadata'       => ['hubai_plan_id' => $planId, 'client_id' => $client->id],
        ];

        $response = $this->http()->post("{$this->baseUrl}/subscriptions", array_filter($payload));
        $this->throwOnFailure($response, 'createSubscription');

        $data = $response->json();

        // Cria/atualiza registro local
        $subscription = Subscription::updateOrCreate(
            ['client_id' => $client->id, 'plan_id' => $planId],
            [
                'pagarme_subscription_id' => $data['id'],
                'pagarme_customer_id'     => $customerId,
                'pagarme_status'          => $data['status'],
                'status'                  => 'active',
            ]
        );

        return $subscription;
    }

    /**
     * Cancela assinatura no Pagar.me e marca local como cancelada.
     */
    public function cancelSubscription(Subscription $subscription): bool
    {
        if (! $subscription->pagarme_subscription_id) {
            return false;
        }

        $response = $this->http()->delete(
            "{$this->baseUrl}/subscriptions/{$subscription->pagarme_subscription_id}"
        );

        if ($response->failed()) {
            Log::error('[Pagarme] Falha ao cancelar assinatura', [
                'subscription_id' => $subscription->pagarme_subscription_id,
                'status'          => $response->status(),
            ]);
            return false;
        }

        $subscription->update([
            'pagarme_status' => 'canceled',
            'status'         => 'canceled',
        ]);

        return true;
    }

    // -------------------------------------------------------------------------
    // Cobranca avulsa (pedidos HubAI)
    // -------------------------------------------------------------------------

    /**
     * Cria uma cobranca avulsa (order) no Pagar.me para um pedido HubAI.
     */
    public function createOrderPayment(Order $order, array $paymentDetails): array
    {
        $client     = $order->client;
        $customerId = $this->getOrCreateCustomer($client);

        $payload = [
            'customer_id'    => $customerId,
            'payment_method' => $paymentDetails['payment_method'] ?? 'pix',
            'amount'         => (int) round((($paymentDetails['amount'] ?? $order->supplier_total ?? $order->total) * 100)), // em centavos
            'metadata'       => ['hubai_order_id' => $order->id, 'order_number' => $order->order_number],
        ];

        if (($payload['payment_method'] === 'credit_card') && isset($paymentDetails['card_token'])) {
            $payload['card_token']   = $paymentDetails['card_token'];
            $payload['installments'] = $paymentDetails['installments'] ?? 1;
        }

        $response = $this->http()->post("{$this->baseUrl}/orders", $payload);
        $this->throwOnFailure($response, 'createOrderPayment');

        return $response->json();
    }

    /**
     * Cria uma cobranca generica (sem Order model).
     */
    public function createOrder(array $data): array
    {
        $response = $this->http()->post("{$this->baseUrl}/orders", $data);
        $this->throwOnFailure($response, 'createOrder');
        return $response->json();
    }

    // -------------------------------------------------------------------------
    // PIX QR Code (Fase 2)
    // -------------------------------------------------------------------------

    /**
     * Cria pedido PIX no Pagarme V5 e retorna QR Code.
     *
     * POST /orders com payment_method pix e expires_in 1800s (30 min).
     * Response: charges[0].last_transaction.qr_code + qr_code_url
     */
    public function createPixQrCode(Order $order, float $amount, string $idempotencyKey): array
    {
        $client     = $order->client;
        $customerId = $this->getOrCreateCustomer($client);

        $payload = [
            'customer_id' => $customerId,
            'items'       => [
                [
                    'amount'      => (int) round($amount * 100),
                    'description' => 'Pedido #' . ($order->order_number ?? $order->id),
                    'quantity'    => 1,
                ],
            ],
            'payments' => [
                [
                    'payment_method' => 'pix',
                    'pix'            => [
                        'expires_in'         => 1800,
                        'additional_information' => [
                            ['name' => 'Pedido', 'value' => (string) ($order->order_number ?? $order->id)],
                        ],
                    ],
                    'amount' => (int) round($amount * 100),
                ],
            ],
            'metadata' => [
                'hubai_order_id'    => $order->id,
                'idempotency_key'   => $idempotencyKey,
            ],
        ];

        $response = $this->http()
            ->withHeaders(['idempotency-key' => $idempotencyKey])
            ->post("{$this->baseUrl}/orders", $payload);

        $this->throwOnFailure($response, 'createPixQrCode');

        $data        = $response->json();
        $lastTx      = $data['charges'][0]['last_transaction'] ?? [];
        $chargeId    = $data['charges'][0]['id'] ?? $data['id'];

        return [
            'external_id'  => $chargeId,
            'qr_code'      => $lastTx['qr_code'] ?? '',
            'qr_code_text' => $lastTx['qr_code_url'] ?? $lastTx['qr_code'] ?? '',
            'expires_at'   => \Illuminate\Support\Carbon::now()->addMinutes(30),
        ];
    }

    // -------------------------------------------------------------------------
    // Estorno (Fase 2)
    // -------------------------------------------------------------------------

    /**
     * Estorna total ou parcialmente uma cobranca no Pagarme V5.
     * POST /charges/{chargeId}/cancel
     */
    public function refundPayment(string $externalId, float $amount, string $reason): bool
    {
        try {
            $payload = [
                'cancel_amount' => (int) round($amount * 100),
            ];

            $response = $this->http()->post("{$this->baseUrl}/charges/{$externalId}/cancel", $payload);

            if ($response->failed()) {
                Log::error('[Pagarme] Falha ao estornar cobranca', [
                    'charge_id' => $externalId,
                    'amount'    => $amount,
                    'status'    => $response->status(),
                    'body'      => $response->body(),
                ]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('[Pagarme] Excecao ao estornar cobranca', [
                'charge_id' => $externalId,
                'error'     => $e->getMessage(),
            ]);
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // verifyWebhookSignature (Fase 2)
    // -------------------------------------------------------------------------

    /**
     * Pagarme V5 assina webhooks com HMAC-SHA256 no header "x-hub-signature".
     * Formato do header: "sha256={hash}"
     *
     * @param string $payload   Body raw do request
     * @param string $signature Valor do header x-hub-signature (ex: "sha256=abc123")
     * @param string $secret    webhook_secret armazenado em SupplierPaymentSetting
     */
    public function verifyWebhookSignature(string $payload, string $signature, string $secret): bool
    {
        // Remove prefixo "sha256=" se presente
        $hash = str_starts_with($signature, 'sha256=')
            ? substr($signature, 7)
            : $signature;

        $expected = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $hash);
    }

    // -------------------------------------------------------------------------
    // Consulta
    // -------------------------------------------------------------------------

    public function getPaymentStatus(string $transactionId): string
    {
        $data = $this->getTransaction($transactionId);

        // Mapear status Pagarme → local
        $pagarmeStatus = $data['status'] ?? 'unknown';

        return match ($pagarmeStatus) {
            'paid'                => 'paid',
            'pending', 'waiting_payment' => 'pending',
            'canceled', 'failed' => 'expired',
            'refunded', 'chargedback' => 'refunded',
            default               => $pagarmeStatus,
        };
    }

    public function getTransaction(string $id): array
    {
        $response = $this->http()->get("{$this->baseUrl}/charges/{$id}");
        $this->throwOnFailure($response, 'getTransaction');
        return $response->json();
    }

    // -------------------------------------------------------------------------
    // Webhook
    // -------------------------------------------------------------------------

    /**
     * Processa eventos recebidos do Pagar.me via webhook.
     * Esperado: payload com 'type' e 'data'.
     */
    public function handleWebhook(array $payload): void
    {
        $event = $payload['type'] ?? 'unknown';
        $data  = $payload['data'] ?? [];

        Log::info("[Pagarme] Webhook recebido: {$event}", ['data_id' => $data['id'] ?? null]);

        match ($event) {
            'charge.paid'                  => $this->onChargePaid($data),
            'charge.payment_failed'        => $this->onChargeFailed($data),
            'subscription.canceled'        => $this->onSubscriptionCanceled($data),
            'subscription.created'         => $this->onSubscriptionCreated($data),
            default                        => Log::info("[Pagarme] Evento ignorado: {$event}"),
        };
    }

    protected function onChargePaid(array $data): void
    {
        $orderId = $data['metadata']['hubai_order_id'] ?? null;
        if ($orderId) {
            \App\Models\Order::where('id', $orderId)->update(['status' => 'paid', 'paid_at' => now()]);
        }

        $pagarmeSubId = $data['subscription_id'] ?? null;
        if ($pagarmeSubId) {
            Subscription::where('pagarme_subscription_id', $pagarmeSubId)
                ->update(['pagarme_status' => 'active', 'status' => 'active']);
        }
    }

    protected function onChargeFailed(array $data): void
    {
        $orderId = $data['metadata']['hubai_order_id'] ?? null;
        if ($orderId) {
            \App\Models\Order::where('id', $orderId)->update(['status' => 'payment_failed']);
        }
    }

    protected function onSubscriptionCanceled(array $data): void
    {
        Subscription::where('pagarme_subscription_id', $data['id'])
            ->update(['pagarme_status' => 'canceled', 'status' => 'canceled']);
    }

    protected function onSubscriptionCreated(array $data): void
    {
        Subscription::where('pagarme_subscription_id', $data['id'])
            ->update(['pagarme_status' => $data['status'] ?? 'active']);
    }
}
