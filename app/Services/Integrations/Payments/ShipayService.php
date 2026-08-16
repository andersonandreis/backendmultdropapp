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
 * Gateway Shipay para pagamentos PIX supplier-driven no HubAI.
 *
 * Base URL (prod):    https://api.shipay.com.br
 * Base URL (staging): https://api-staging.shipay.com.br
 * Auth: POST /authentication → Bearer token (~60min)
 *
 * Credenciais: client_key + secret_key + access_key
 */
class ShipayService extends AbstractPaymentGateway
{
    protected string $baseUrl;
    protected string $clientKey;
    protected string $secretKey;
    protected string $accessKey;

    /**
     * supplier_id injetado pelo PaymentGatewayFactory::makeForSupplier() para o cache key.
     * Populado via configure().
     */
    protected ?int $supplierId = null;

    public function __construct()
    {
        $this->baseUrl   = config('services.shipay.base_url', env('SHIPAY_BASE_URL', 'https://api-staging.shipay.com.br'));
        $this->clientKey = config('services.shipay.client_id', env('SHIPAY_CLIENT_KEY', ''));
        $this->secretKey = config('services.shipay.secret_key', env('SHIPAY_SECRET_KEY', ''));
        $this->accessKey = config('services.shipay.access_key', env('SHIPAY_ACCESS_KEY', ''));
    }

    // -------------------------------------------------------------------------
    // Interface: configure
    // -------------------------------------------------------------------------

    /**
     * Shipay usa 3 credenciais:
     *   api_key    → client_key
     *   api_secret → secret_key
     *   extra[access_key] → access_key
     */
    public function configure(array $credentials): void
    {
        if (!empty($credentials['api_key'])) {
            $this->clientKey = $credentials['api_key'];
        }
        if (!empty($credentials['api_secret'])) {
            $this->secretKey = $credentials['api_secret'];
        }
        if (!empty($credentials['extra']['access_key'])) {
            $this->accessKey = $credentials['extra']['access_key'];
        }
        if (!empty($credentials['supplier_id'])) {
            $this->supplierId = (int) $credentials['supplier_id'];
        }
    }

    // -------------------------------------------------------------------------
    // Auth
    // -------------------------------------------------------------------------

    /**
     * Obtem token Bearer do Shipay com cache de 50 minutos.
     * POST /authentication
     */
    protected function getAuthToken(): string
    {
        $cacheKey = 'shipay_token_' . ($this->supplierId ?? md5($this->clientKey));

        return Cache::remember($cacheKey, 3000, function () {
            Log::info('[Shipay] Auth attempt', [
                'base_url' => $this->baseUrl,
                'access_key_len' => strlen($this->accessKey),
                'client_key_len' => strlen($this->clientKey),
                'secret_key_len' => strlen($this->secretKey),
                'access_key_preview' => substr($this->accessKey, 0, 10),
                'client_key_preview' => substr($this->clientKey, 0, 10),
            ]);

            $response = Http::post("{$this->baseUrl}/pdvauth", [
                'access_key' => $this->accessKey,
                'client_id' => $this->clientKey,
                'secret_key' => $this->secretKey,
            ]);

            if ($response->failed()) {
                Log::error('[Shipay] Falha ao autenticar', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                throw new \RuntimeException('[Shipay] Falha na autenticacao: ' . $response->body());
            }

            $token = $response->json('access_token');
            if (empty($token)) {
                throw new \RuntimeException('[Shipay] access_token nao retornado na autenticacao.');
            }

            return $token;
        });
    }

    protected function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken($this->getAuthToken())
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ]);
    }

    // -------------------------------------------------------------------------
    // createOrderPayment
    // -------------------------------------------------------------------------

    /**
     * Cria order PIX no Shipay para um pedido HubAI.
     * POST /order com wallet "all_pix" (aceita qualquer carteira PIX).
     *
     * @param Order $order
     * @param array $paymentDetails Aceita keys: buyer (nome/cpf_cnpj), description
     * @return array{status: string, qr_code: string, qr_code_text: string, external_id: string}
     */
    public function createOrderPayment(Order $order, array $paymentDetails = []): array
    {
        $client = $order->client;

        $payload = [
            'order_ref'    => (string) ($order->order_number ?? $order->id),
            'wallet'       => 'pix',
            'total'        => $paymentDetails['amount'] ?? $order->supplier_total ?? $order->total,
            'postback_url' => $this->webhookUrl(),
            'items'        => [
                [
                    'item_title' => 'Pedido #' . ($order->order_number ?? $order->id),
                    'quantity'   => 1,
                    'unit_price' => $paymentDetails['amount'] ?? $order->supplier_total ?? $order->total,
                ],
            ],
            'buyer' => [
                'name'      => $paymentDetails['buyer']['name'] ?? ($client->company_name ?? 'Cliente'),
                'cpf_cnpj'  => $paymentDetails['buyer']['cpf_cnpj'] ?? preg_replace('/\D/', '', $client->document ?? ''),
            ],
        ];

        $response = $this->http()->post("{$this->baseUrl}/order", $payload);

        if ($response->failed()) {
            Log::error('[Shipay] createOrderPayment erro', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException('[Shipay] createOrderPayment: ' . $response->body());
        }

        $data = $response->json();

        return [
            'status'       => 'SUCCESS',
            'qr_code'      => $data['qr_code'] ?? $data['qr_code_url'] ?? '',
            'qr_code_text' => $data['qr_code_text'] ?? $data['pix_qr_code'] ?? $data['pix_dict_key'] ?? '',
            'external_id'  => $data['order_id'] ?? '',
        ];
    }

    // -------------------------------------------------------------------------
    // createPixQrCode
    // -------------------------------------------------------------------------

    /**
     * Gera QR Code PIX para pagamento de pedido.
     * POST /order → retorna qr_code_url / pix_qr_code.
     */
    public function createPixQrCode(Order $order, float $amount, string $idempotencyKey, ?string $buyerDoc = null): array
    {
        $client = $order->client;

        $doc = $buyerDoc ?? $this->sanitizeDocument($client->document ?? '');
        $buyer = ['name' => $client->company_name ?? 'Cliente'];
        if ($doc) {
            $buyer['cpf_cnpj'] = $doc;
        }

        $payload = [
            'order_ref'    => $idempotencyKey,
            'wallet'       => 'pix',
            'total'        => $amount,
            'postback_url' => $this->webhookUrl(),
            'items'        => [
                [
                    'item_title' => "Pedido #{$order->id}",
                    'quantity'   => 1,
                    'unit_price' => $amount,
                ],
            ],
            'buyer' => $buyer,
        ];

        $response = $this->http()->post("{$this->baseUrl}/order", $payload);

        if ($response->failed()) {
            Log::error('[Shipay] createPixQrCode erro', [
                'status'          => $response->status(),
                'body'            => $response->body(),
                'idempotency_key' => $idempotencyKey,
            ]);
            throw new \RuntimeException('[Shipay] createPixQrCode: ' . $response->body());
        }

        $data = $response->json();

        return [
            'external_id'  => $data['order_id'] ?? '',
            'qr_code'      => $data['qr_code'] ?? $data['qr_code_url'] ?? '',
            'qr_code_text' => $data['qr_code_text'] ?? $data['pix_qr_code'] ?? $data['pix_dict_key'] ?? '',
            'expires_at'   => Carbon::now()->addMinutes(30),
        ];
    }

    // -------------------------------------------------------------------------
    // getPaymentStatus
    // -------------------------------------------------------------------------

    /**
     * Consulta status de uma order e mapeia para status local.
     *
     * Shipay → local:
     *   approved                    → paid
     *   pending                     → pending
     *   cancelled, expired          → expired
     *   refund_pending, refunded    → refunded
     */
    public function getPaymentStatus(string $transactionId): string
    {
        $response = $this->http()->get("{$this->baseUrl}/order/{$transactionId}");

        if ($response->failed()) {
            Log::error('[Shipay] getPaymentStatus erro', [
                'order_id' => $transactionId,
                'status'   => $response->status(),
            ]);
            throw new \RuntimeException('[Shipay] getPaymentStatus: ' . $response->body());
        }

        $shipayStatus = $response->json('status');

        return match ($shipayStatus) {
            'approved'                   => 'paid',
            'pending'                    => 'pending',
            'cancelled', 'expired'       => 'expired',
            'refund_pending', 'refunded' => 'refunded',
            default                      => 'pending',
        };
    }

    // -------------------------------------------------------------------------
    // refundPayment — nao suportado
    // -------------------------------------------------------------------------

    /**
     * Shipay NAO suporta estorno via API.
     * O fornecedor deve realizar o estorno manualmente.
     */
    public function refundPayment(string $externalId, float $amount, string $reason): bool
    {
        Log::warning('[Shipay] refundPayment nao suportado via API — estorno manual necessario', [
            'order_id' => $externalId,
            'amount'   => $amount,
            'reason'   => $reason,
        ]);

        return false;
    }


    // -------------------------------------------------------------------------
    // createWalletQrCode
    // -------------------------------------------------------------------------

    /**
     * Gera QR Code PIX para recarga de carteira (wallet topup).
     * Nao requer Order -- usa dados raw do cliente e valor.
     *
     * @param string $ref       Chave de idempotencia
     * @param float  $amount    Valor total a cobrar (ja com taxa)
     * @param string $buyerName Nome do comprador
     * @param string $cpfCnpj  CPF/CNPJ limpo (so digitos)
     * @return array
     */
    public function createWalletQrCode(string $ref, float $amount, string $buyerName, string $cpfCnpj): array
    {
        $payload = [
            'order_ref'    => $ref,
            'wallet'       => 'pix',
            'total'        => $amount,
            'postback_url' => $this->webhookUrl(),
            'items'        => [
                [
                    'item_title' => 'Deposito carteira HubAI',
                    'quantity'   => 1,
                    'unit_price' => $amount,
                ],
            ],
            'buyer' => [
                'name'     => $buyerName,
                'cpf_cnpj' => $cpfCnpj,
            ],
        ];

        $response = $this->http()->post("{$this->baseUrl}/order", $payload);

        if ($response->failed()) {
            Log::error('[Shipay] createWalletQrCode erro', [
                'status' => $response->status(),
                'body'   => $response->body(),
                'ref'    => $ref,
            ]);
            throw new \RuntimeException('[Shipay] createWalletQrCode: ' . $response->body());
        }

        $data = $response->json();

        return [
            'external_id'  => $data['order_id'] ?? '',
            'qr_code'      => $data['qr_code'] ?? $data['qr_code_url'] ?? '',
            'qr_code_text' => $data['qr_code_text'] ?? $data['pix_qr_code'] ?? $data['pix_dict_key'] ?? '',
        ];
    }

    // -------------------------------------------------------------------------
    // cancelOrder
    // -------------------------------------------------------------------------

    /**
     * Cancela um PIX pendente no Shipay.
     * DELETE /order/{orderId}
     */
    public function cancelOrder(string $orderId): bool
    {
        try {
            $response = $this->http()->delete("{$this->baseUrl}/order/{$orderId}");

            if ($response->failed()) {
                Log::error('[Shipay] cancelOrder erro', [
                    'order_id' => $orderId,
                    'status'   => $response->status(),
                ]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('[Shipay] Excecao ao cancelar order', [
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
            ]);
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // createSubscription / cancelSubscription — nao suportado
    // -------------------------------------------------------------------------

    public function createSubscription(Client $client, int $planId, array $paymentDetails): Subscription
    {
        throw new \RuntimeException('Shipay nao suporta assinaturas recorrentes. Use Asaas ou Pagarme.');
    }

    public function cancelSubscription(Subscription $subscription): bool
    {
        throw new \RuntimeException('Shipay nao suporta assinaturas recorrentes.');
    }

    // -------------------------------------------------------------------------
    // verifyWebhookSignature
    // -------------------------------------------------------------------------

    /**
     * Shipay envia order_id e status no body.
     * Verifica usando HMAC-SHA256 do body inteiro com o webhook_secret.
     */

    private function webhookUrl(): string
    {
        $base = rtrim(config('app.url', env('APP_URL', 'https://api.fornecefy.io')), '/');
        return $base . '/api/webhooks/shipay/wallet';
    }

    private function sanitizeDocument(?string $document): ?string
    {
        $doc = preg_replace('/[^0-9]/', '', $document ?? '');
        if (strlen($doc) !== 11 && strlen($doc) !== 14) {
            return null;
        }
        if (count(array_unique(str_split($doc))) === 1) {
            return null;
        }
        return $doc;
    }

    public function verifyWebhookSignature(string $payload, string $signature, string $secret): bool
    {
        $expected = hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $signature);
    }
}
