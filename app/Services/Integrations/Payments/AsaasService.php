<?php

namespace App\Services\Integrations\Payments;

use App\Models\Client;
use App\Models\Order;
use App\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Gateway Asaas v3 para pagamentos supplier-driven no HubAI.
 *
 * Base URL (prod):    https://api.asaas.com/v3
 * Base URL (sandbox): https://sandbox.asaas.com/api/v3
 * Auth: header access_token: {api_key}
 */
class AsaasService extends AbstractPaymentGateway
{
    protected string $baseUrl;
    protected string $apiKey;

    public function __construct()
    {
        $this->baseUrl = config('services.asaas.base_url', env('ASAAS_BASE_URL', 'https://sandbox.asaas.com/api/v3'));
        $this->apiKey  = config('services.asaas.api_key', env('ASAAS_API_KEY', ''));
    }

    // -------------------------------------------------------------------------
    // Interface: configure
    // -------------------------------------------------------------------------

    public function configure(array $credentials): void
    {
        if (!empty($credentials['api_key'])) {
            $this->apiKey = $credentials['api_key'];
        }
    }

    // -------------------------------------------------------------------------
    // Helper HTTP
    // -------------------------------------------------------------------------

    protected function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders([
            'access_token' => $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ]);
    }

    /**
     * Lanca excecao descritiva quando a resposta Asaas indica erro.
     * Asaas retorna {"errors": [{"code": "...", "description": "..."}]}
     */
    protected function throwOnFailure(\Illuminate\Http\Client\Response $response, string $context): void
    {
        if ($response->failed()) {
            $body   = $response->json();
            $errors = $body['errors'] ?? [];
            $msg    = collect($errors)->pluck('description')->implode('; ');

            Log::error("[Asaas] Falha em {$context}", [
                'status' => $response->status(),
                'errors' => $errors,
            ]);

            throw new \RuntimeException("[Asaas] {$context}: " . ($msg ?: $response->body()));
        }
    }

    // -------------------------------------------------------------------------
    // Customer
    // -------------------------------------------------------------------------

    /**
     * Busca ou cria o customer no Asaas para um Client HubAI.
     * Usa externalReference "hubai_client_{id}" para lookup.
     * Cacheia o customer_id por 24h.
     */
    public function getOrCreateCustomer(Client $client): string
    {
        $cacheKey = "asaas_customer_{$client->id}";

        return Cache::remember($cacheKey, now()->addDay(), function () use ($client) {
            // 1. Tentar campo local primeiro
            if (!empty($client->asaas_customer_id)) {
                return $client->asaas_customer_id;
            }

            $externalRef = "hubai_client_{$client->id}";

            // 2. Buscar no Asaas por externalReference
            $search = $this->http()->get("{$this->baseUrl}/customers", [
                'externalReference' => $externalRef,
            ]);

            if ($search->successful()) {
                $data = $search->json();
                $rows = $data['data'] ?? [];

                if (!empty($rows)) {
                    $customerId = $rows[0]['id'];
                    if (empty($rows[0]['notificationDisabled'])) {
                        $this->http()->put("{$this->baseUrl}/customers/{$customerId}", [
                            'notificationDisabled' => true,
                        ]);
                    }
                    // Persistir localmente se o campo existir
                    if (isset($client->asaas_customer_id)) {
                        $client->update(['asaas_customer_id' => $customerId]);
                    }
                    return $customerId;
                }
            }

            // 3. Criar novo customer
            $cpfCnpj = preg_replace('/\D/', '', $client->document ?? '');

            $response = $this->http()->post("{$this->baseUrl}/customers", [
                'name'              => $client->company_name,
                'email'             => $client->user->email ?? '',
                'cpfCnpj'           => $cpfCnpj,
                'phone'             => preg_replace('/\D/', '', $client->phone ?? ''),
                'externalReference' => $externalRef,
                'notificationDisabled' => true,
            ]);

            $this->throwOnFailure($response, 'getOrCreateCustomer');

            $customerId = $response->json('id');

            if (!empty($customerId) && isset($client->asaas_customer_id)) {
                $client->update(['asaas_customer_id' => $customerId]);
            }

            return $customerId;
        });
    }

    // -------------------------------------------------------------------------
    // createOrderPayment
    // -------------------------------------------------------------------------

    /**
     * Cria cobranca PIX avulsa no Asaas para um pedido.
     *
     * @param Order $order
     * @param array $paymentDetails Aceita keys: method, due_date, description
     * @return array{transaction_id: string, status: string, invoice_url: string}
     */
    public function createOrderPayment(Order $order, array $paymentDetails = []): array
    {
        $customerId = $this->getOrCreateCustomer($order->client);

        $payload = [
            'customer'          => $customerId,
            'billingType'       => $paymentDetails['method'] ?? 'PIX',
            'value'             => $paymentDetails['amount'] ?? $order->supplier_total ?? $order->total,
            'dueDate'           => ($paymentDetails['due_date'] ?? now()->addDays(3)->format('Y-m-d')),
            'description'       => $paymentDetails['description'] ?? 'Pedido #' . ($order->order_number ?? $order->id),
            'externalReference' => (string) $order->id,
        ];

        $response = $this->http()->post("{$this->baseUrl}/payments", $payload);
        $this->throwOnFailure($response, 'createOrderPayment');

        $data = $response->json();

        return [
            'transaction_id' => $data['id'],
            'status'         => $data['status'],
            'invoice_url'    => $data['invoiceUrl'] ?? '',
        ];
    }

    // -------------------------------------------------------------------------
    // createPixQrCode
    // -------------------------------------------------------------------------

    /**
     * Cria pagamento PIX e retorna o QR Code.
     * Asaas: POST /payments → GET /payments/{id}/pixQrCode
     */
    public function createPixQrCode(Order $order, float $amount, string $idempotencyKey): array
    {
        $customerId = $this->getOrCreateCustomer($order->client);

        // 1. Criar payment PIX
        $payloadPayment = [
            'customer'          => $customerId,
            'billingType'       => 'PIX',
            'value'             => $amount,
            'dueDate'           => now()->addDays(1)->format('Y-m-d'),
            'description'       => 'Pedido #' . ($order->order_number ?? $order->id),
            'externalReference' => $idempotencyKey,
        ];

        $paymentResponse = $this->http()->post("{$this->baseUrl}/payments", $payloadPayment);
        $this->throwOnFailure($paymentResponse, 'createPixQrCode/createPayment');

        $paymentId = $paymentResponse->json('id');

        // 2. Buscar QR Code
        $qrResponse = $this->http()->get("{$this->baseUrl}/payments/{$paymentId}/pixQrCode");
        $this->throwOnFailure($qrResponse, 'createPixQrCode/pixQrCode');

        $qrData = $qrResponse->json();

        return [
            'external_id'   => $paymentId,
            'qr_code'       => $qrData['encodedImage'] ?? '',
            'qr_code_text'  => $qrData['payload'] ?? '',
            'expires_at'    => Carbon::now()->addMinutes(30),
        ];
    }

    /**
     * Busca o QR Code de um payment ja criado (usado por PixService para topup).
     */
    public function getPixQrCode(string $paymentId): array
    {
        $qrResponse = $this->http()->get("{$this->baseUrl}/payments/{$paymentId}/pixQrCode");
        $this->throwOnFailure($qrResponse, 'getPixQrCode');

        $qrData = $qrResponse->json();

        return [
            'external_id'  => $paymentId,
            'qr_code'      => $qrData['encodedImage'] ?? '',
            'qr_code_text' => $qrData['payload'] ?? '',
            'expires_at'   => Carbon::now()->addMinutes(30),
        ];
    }

    // -------------------------------------------------------------------------
    // getPaymentStatus
    // -------------------------------------------------------------------------

    /**
     * Consulta status de um pagamento e mapeia para status local.
     *
     * Asaas → local:
     *   PENDING, AWAITING_RISK_ANALYSIS → pending
     *   CONFIRMED, RECEIVED             → paid
     *   OVERDUE                         → expired
     *   REFUNDED, REFUND_REQUESTED      → refunded
     *   CHARGEBACK_REQUESTED            → refunded
     */
    public function getPaymentStatus(string $transactionId): string
    {
        $response = $this->http()->get("{$this->baseUrl}/payments/{$transactionId}");
        $this->throwOnFailure($response, 'getPaymentStatus');

        $asaasStatus = $response->json('status');

        return match ($asaasStatus) {
            'PENDING', 'AWAITING_RISK_ANALYSIS'    => 'pending',
            'CONFIRMED', 'RECEIVED'                => 'paid',
            'OVERDUE'                              => 'expired',
            'REFUNDED', 'REFUND_REQUESTED',
            'CHARGEBACK_REQUESTED'                 => 'refunded',
            'CANCELLED'                            => 'expired',
            default => 'pending',
        };
    }

    // -------------------------------------------------------------------------
    // refundPayment
    // -------------------------------------------------------------------------

    /**
     * Estorna total ou parcialmente um pagamento.
     * POST /payments/{id}/refund
     */
    public function refundPayment(string $externalId, float $amount, string $reason): bool
    {
        try {
            $response = $this->http()->post("{$this->baseUrl}/payments/{$externalId}/refund", [
                'value'       => $amount,
                'description' => $reason,
            ]);

            if ($response->failed()) {
                $errors = $response->json('errors', []);
                Log::error('[Asaas] Falha ao estornar pagamento', [
                    'payment_id' => $externalId,
                    'amount'     => $amount,
                    'errors'     => $errors,
                ]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('[Asaas] Excecao ao estornar pagamento', [
                'payment_id' => $externalId,
                'error'      => $e->getMessage(),
            ]);
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // createSubscription / cancelSubscription
    // -------------------------------------------------------------------------

    /**
     * Cria assinatura recorrente PIX mensal no Asaas.
     */
    public function createSubscription(Client $client, int $planId, array $paymentDetails): Subscription
    {
        $customerId = $this->getOrCreateCustomer($client);

        $payload = [
            'customer'          => $customerId,
            'billingType'       => $paymentDetails['billing_type'] ?? 'PIX',
            'value'             => $paymentDetails['value'],
            'cycle'             => $paymentDetails['cycle'] ?? 'MONTHLY',
            'nextDueDate'       => $paymentDetails['next_due_date'] ?? now()->format('Y-m-d'),
            'description'       => $paymentDetails['description'] ?? 'HubAI Plano',
            'externalReference' => "hubai_plan_{$planId}_client_{$client->id}",
        ];

        $response = $this->http()->post("{$this->baseUrl}/subscriptions", $payload);
        $this->throwOnFailure($response, 'createSubscription');

        $data = $response->json();

        $subscription = Subscription::updateOrCreate(
            ['client_id' => $client->id, 'plan_id' => $planId],
            [
                'external_payment_id'  => $data['id'],
                'status'               => 'active',
                'current_period_start' => now(),
                'current_period_end'   => now()->addMonth(),
            ]
        );

        return $subscription;
    }

    /**
     * Cancela assinatura no Asaas.
     * DELETE /subscriptions/{id}
     */
    public function cancelSubscription(Subscription $subscription): bool
    {
        $externalId = $subscription->external_payment_id ?? null;

        if (!$externalId) {
            Log::warning('[Asaas] cancelSubscription sem external_id', ['subscription_id' => $subscription->id]);
            return false;
        }

        try {
            $response = $this->http()->delete("{$this->baseUrl}/subscriptions/{$externalId}");

            if ($response->failed()) {
                Log::error('[Asaas] Falha ao cancelar assinatura', [
                    'external_id' => $externalId,
                    'status'      => $response->status(),
                ]);
                return false;
            }

            $subscription->update(['status' => 'canceled', 'cancelled_at' => now()]);
            return true;
        } catch (\Throwable $e) {
            Log::error('[Asaas] Excecao ao cancelar assinatura', [
                'external_id' => $externalId,
                'error'       => $e->getMessage(),
            ]);
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // verifyWebhookSignature
    // -------------------------------------------------------------------------

    /**
     * Asaas autentica webhooks via header "asaas-access-token".
     * Compara com o webhook_secret armazenado em SupplierPaymentSetting.
     *
     * @param string $payload   Body raw do request (ignorado — Asaas usa token fixo)
     * @param string $signature Valor do header "asaas-access-token"
     * @param string $secret    Valor de webhook_secret no SupplierPaymentSetting
     */
    public function verifyWebhookSignature(string $payload, string $signature, string $secret): bool
    {
        return hash_equals($secret, $signature);
    }
}
