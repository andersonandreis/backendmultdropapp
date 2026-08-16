<?php

namespace App\Services\Drop\Payment;

use App\Models\Drop\DropOrder;
use App\Models\Drop\DropStore;
use App\Models\Drop\StorePaymentGateway;
use App\Services\PagarmeService;
use Illuminate\Support\Facades\Log;

/**
 * Roteador de pagamento para checkout da Loja Nativa.
 * Cada seller usa suas próprias credenciais — dinheiro vai direto para o seller.
 */
class NativeCheckoutPaymentService
{
    public function processPayment(DropOrder $order, array $paymentData): array
    {
        $store   = DropStore::findOrFail($order->drop_store_id);
        $gateway = $this->resolveGateway($store, $paymentData['gateway_type'] ?? null);

        if (!$gateway) {
            return ['success' => false, 'error' => 'Nenhum gateway de pagamento configurado para esta loja.'];
        }

        return match ($gateway->gateway_type) {
            'pagarme'     => $this->processPagarme($order, $gateway, $paymentData),
            'mercadopago' => $this->processMercadoPago($order, $gateway, $paymentData),
            'pix_manual'  => $this->processPixManual($order, $gateway),
            'stripe'      => $this->processStripe($order, $gateway, $paymentData),
            default       => ['success' => false, 'error' => 'Gateway não suportado: ' . $gateway->gateway_type],
        };
    }

    private function resolveGateway(DropStore $store, ?string $requestedType): ?StorePaymentGateway
    {
        $query = $store->paymentGateways()->where('is_active', true);

        if ($requestedType) {
            $gateway = $query->where('gateway_type', $requestedType)->first();
            if ($gateway) return $gateway;
        }

        return $query->where('is_default', true)->first()
            ?? $query->first();
    }

    private function processPagarme(DropOrder $order, StorePaymentGateway $gateway, array $data): array
    {
        try {
            $credentials = $gateway->credentials;
            $apiKey      = $credentials['api_key'] ?? null;

            if (!$apiKey) {
                return ['success' => false, 'error' => 'Pagar.me: api_key nao configurada.'];
            }

            $method = $data['method'] ?? 'pix';

            // Inicializa PagarmeService com as credenciais do seller
            $service = app(PagarmeService::class, ['apiKey' => $apiKey]);

            if ($method === 'pix') {
                $result = $service->createPixPayment([
                    'amount'      => (int) round($order->total_amount * 100),
                    'description' => 'Pedido #' . $order->order_key,
                    'customer'    => [
                        'name'     => $order->customer_name,
                        'email'    => $order->customer_email,
                        'document' => preg_replace('/\D/', '', $order->customer_cpf ?? ''),
                    ],
                    'metadata' => ['order_key' => $order->order_key],
                ]);
            } else {
                $result = $service->createBoletoPayment([
                    'amount'      => (int) round($order->total_amount * 100),
                    'description' => 'Pedido #' . $order->order_key,
                    'customer'    => [
                        'name'     => $order->customer_name,
                        'email'    => $order->customer_email,
                        'document' => preg_replace('/\D/', '', $order->customer_cpf ?? ''),
                    ],
                ]);
            }

            if ($result['success'] ?? false) {
                $order->update(['payment_method' => 'pagarme_' . $method]);
            }

            return $result;
        } catch (\Throwable $e) {
            Log::error('NativeCheckout Pagar.me erro', ['order' => $order->order_key, 'error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function processMercadoPago(DropOrder $order, StorePaymentGateway $gateway, array $data): array
    {
        try {
            $credentials = $gateway->credentials;
            $accessToken = $credentials['access_token'] ?? null;

            if (!$accessToken) {
                return ['success' => false, 'error' => 'MercadoPago: access_token nao configurado.'];
            }

            $mp     = new MercadoPagoDropService($accessToken);
            $method = $data['method'] ?? 'pix';

            $orderPayload = [
                'total_amount'  => (float) $order->total_amount,
                'order_key'     => $order->order_key,
                'customer_name' => $order->customer_name,
                'customer_email'=> $order->customer_email,
                'customer_cpf'  => $order->customer_cpf,
                'store_slug'    => DropStore::find($order->drop_store_id)?->store_slug,
            ];

            $result = $method === 'pix'
                ? $mp->createPixPayment($orderPayload)
                : $mp->createCardPayment($orderPayload, $data['card_token'] ?? '');

            if ($result['success'] ?? false) {
                $order->update(['payment_method' => 'mercadopago_' . $method]);
            }

            return $result;
        } catch (\Throwable $e) {
            Log::error('NativeCheckout MercadoPago erro', ['order' => $order->order_key, 'error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function processPixManual(DropOrder $order, StorePaymentGateway $gateway): array
    {
        // PIX manual: não processa automaticamente, seller confirma manualmente
        $order->update([
            'payment_method' => 'pix_manual',
            'status'         => 'pending_payment',
        ]);

        return [
            'success'      => true,
            'type'         => 'pix_manual',
            'pix_key'      => $gateway->pix_key,
            'pix_key_type' => $gateway->pix_key_type,
            'amount'       => number_format($order->total_amount, 2, '.', ''),
            'description'  => 'Pedido #' . $order->order_key,
            'message'      => 'Realize o PIX para a chave indicada e aguarde confirmação do vendedor.',
        ];
    }

    private function processStripe(DropOrder $order, StorePaymentGateway $gateway, array $data): array
    {
        try {
            $credentials = $gateway->credentials;
            $secretKey   = $credentials['secret_key'] ?? null;

            if (!$secretKey) {
                return ['success' => false, 'error' => 'Stripe: secret_key nao configurada.'];
            }

            $stripe = new \Stripe\StripeClient($secretKey);

            $intent = $stripe->paymentIntents->create([
                'amount'      => (int) round($order->total_amount * 100),
                'currency'    => 'brl',
                'description' => 'Pedido #' . $order->order_key,
                'metadata'    => ['order_key' => $order->order_key],
            ]);

            $order->update(['payment_method' => 'stripe_card']);

            return [
                'success'       => true,
                'client_secret' => $intent->client_secret,
                'payment_id'    => $intent->id,
            ];
        } catch (\Throwable $e) {
            Log::error('NativeCheckout Stripe erro', ['order' => $order->order_key, 'error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
