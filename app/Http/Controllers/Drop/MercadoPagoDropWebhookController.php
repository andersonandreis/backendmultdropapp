<?php

namespace App\Http\Controllers\Drop;

use App\Http\Controllers\Controller;
use App\Models\Drop\DropOrder;
use App\Models\Drop\StorePaymentGateway;
use App\Services\Drop\Payment\MercadoPagoDropService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class MercadoPagoDropWebhookController extends Controller
{
    public function handle(Request $request): Response
    {
        $payload = $request->all();

        Log::info('MercadoPago Drop webhook recebido', ['payload' => $payload]);

        try {
            $action    = $payload['action'] ?? '';
            $paymentId = $payload['data']['id'] ?? null;

            if ($action !== 'payment.updated' && $action !== 'payment.created') {
                return response('OK', 200);
            }

            if (!$paymentId) {
                return response('no payment_id', 200);
            }

            // Encontrar o pedido pelo external_reference (order_key)
            $externalRef = $payload['data']['external_reference'] ?? null;
            if (!$externalRef) {
                $externalRef = $this->findExternalRef($paymentId);
            }

            $order = DropOrder::where('order_key', $externalRef)
                ->where('source', 'native_store')
                ->first();

            if (!$order) {
                Log::warning('MercadoPago Drop webhook: pedido nao encontrado', ['ref' => $externalRef]);
                return response('not found', 200);
            }

            // Buscar gateway MP do store para consultar status
            $gateway = StorePaymentGateway::where('drop_store_id', $order->drop_store_id)
                ->where('gateway_type', 'mercadopago')
                ->where('is_active', true)
                ->first();

            if (!$gateway) {
                return response('no gateway', 200);
            }

            $credentials = $gateway->credentials;
            $accessToken = $credentials['access_token'] ?? null;

            if (!$accessToken) {
                return response('no access_token', 200);
            }

            $mp     = new MercadoPagoDropService($accessToken);
            $status = $mp->getPaymentStatus((string) $paymentId);

            if (($status['status'] ?? '') === 'approved') {
                $order->update([
                    'status'         => 'payment_received',
                    'payment_method' => 'mercadopago',
                ]);

                Log::info('MercadoPago Drop: pagamento aprovado', [
                    'order_key'  => $order->order_key,
                    'payment_id' => $paymentId,
                ]);
            }

            return response('OK', 200);
        } catch (\Throwable $e) {
            Log::error('MercadoPago Drop webhook: excecao', ['error' => $e->getMessage()]);
            return response('error', 200);
        }
    }

    private function findExternalRef(string $paymentId): ?string
    {
        return null;
    }
}
