<?php

namespace App\Http\Controllers\Drop;

use App\Http\Controllers\Controller;
use App\Jobs\Drop\CreateSupplierOrderJob;
use App\Models\Drop\DropOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Drop Internacional - Fase 3
 * Webhook do Pagar.me para atualizar pagamento de pedidos nativos.
 *
 * Rota: POST /api/webhooks/drop/pagarme
 * Sem autenticacao (Pagar.me envia diretamente) — validado via HMAC.
 */
class PagarmeDropWebhookController extends Controller
{
    /**
     * Recebe notificacoes de pagamento do Pagar.me.
     * Sempre retorna 200 para evitar reentregas desnecessarias.
     */
    public function handle(Request $request): JsonResponse
    {
        try {
            $rawPayload = $request->getContent();

            // Validar assinatura HMAC-SHA256 do Pagar.me
            $signature = $request->header('X-Hub-Signature', '');
            if ($signature && !$this->validateSignature($rawPayload, $signature)) {
                Log::warning('PagarmeDropWebhook: assinatura invalida', [
                    'ip' => $request->ip(),
                ]);
                // Retorna 200 para nao revelar que rejeitamos
                return response()->json(['ok' => false], 200);
            }

            $payload = $request->json()->all();
            $type    = $payload['type'] ?? $payload['event'] ?? null;

            Log::info('PagarmeDropWebhook: evento recebido', [
                'type' => $type,
            ]);

            match ($type) {
                'charge.paid',
                'order.paid'              => $this->handlePaymentConfirmed($payload),
                'charge.payment_failed',
                'order.payment_failed'    => $this->handlePaymentFailed($payload),
                'charge.refunded',
                'order.refunded'          => $this->handleRefunded($payload),
                default                   => Log::debug('PagarmeDropWebhook: evento ignorado', ['type' => $type]),
            };

        } catch (\Throwable $e) {
            Log::error('PagarmeDropWebhook: erro ao processar', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return response()->json(['ok' => true], 200);
    }

    // -------------------------------------------------------------------------
    // Handlers de eventos
    // -------------------------------------------------------------------------

    private function handlePaymentConfirmed(array $payload): void
    {
        $orderKey = $this->extractOrderKey($payload);

        if (!$orderKey) {
            Log::warning('PagarmeDropWebhook: handlePaymentConfirmed sem order_key', [
                'payload_keys' => array_keys($payload),
            ]);
            return;
        }

        $order = DropOrder::where('order_key', $orderKey)->first();

        if (!$order) {
            Log::warning('PagarmeDropWebhook: DropOrder nao encontrado', [
                'order_key' => $orderKey,
            ]);
            return;
        }

        // Idempotencia: so atualizar se ainda pendente
        if (in_array($order->status, ['payment_received', 'awaiting_supplier', 'processing', 'shipped', 'delivered', 'completed'], true)) {
            Log::info('PagarmeDropWebhook: pedido ja esta em status avanado, ignorando', [
                'order_key' => $orderKey,
                'status'    => $order->status,
            ]);
            return;
        }

        $order->update(['status' => 'payment_received']);

        Log::info('PagarmeDropWebhook: pagamento confirmado', [
            'drop_order_id' => $order->id,
            'order_key'     => $orderKey,
        ]);

        // Disparar job para criar pedido automatico no fornecedor
        CreateSupplierOrderJob::dispatch($order->id)->onQueue('drop');

        Log::info('PagarmeDropWebhook: CreateSupplierOrderJob despachado', [
            'drop_order_id' => $order->id,
        ]);
    }

    private function handlePaymentFailed(array $payload): void
    {
        $orderKey = $this->extractOrderKey($payload);
        if (!$orderKey) return;

        $order = DropOrder::where('order_key', $orderKey)->first();
        if (!$order) return;

        if ($order->status === 'pending_payment') {
            $order->update(['status' => 'payment_failed']);

            Log::info('PagarmeDropWebhook: pagamento falhou', [
                'drop_order_id' => $order->id,
                'order_key'     => $orderKey,
            ]);
        }
    }

    private function handleRefunded(array $payload): void
    {
        $orderKey = $this->extractOrderKey($payload);
        if (!$orderKey) return;

        $order = DropOrder::where('order_key', $orderKey)->first();
        if (!$order) return;

        $order->update(['status' => 'refunded']);

        Log::info('PagarmeDropWebhook: reembolso processado', [
            'drop_order_id' => $order->id,
            'order_key'     => $orderKey,
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Extrair order_key do payload do Pagar.me.
     * Pode estar em diferentes locais dependendo do evento.
     */
    private function extractOrderKey(array $payload): ?string
    {
        // Estrutura v5 do Pagar.me: data.order.metadata.order_key
        $orderKey = $payload['data']['order']['metadata']['order_key']
            ?? $payload['data']['charge']['metadata']['order_key']
            ?? $payload['metadata']['order_key']
            ?? null;

        return $orderKey ? (string) $orderKey : null;
    }

    /**
     * Validar assinatura HMAC-SHA256 do Pagar.me.
     * Header: X-Hub-Signature: sha256=<hash>
     */
    private function validateSignature(string $payload, string $signatureHeader): bool
    {
        $secret = config('services.pagarme.webhook_secret', '');
        if (!$secret) {
            // Se nao configurado, aceitar (nao valida)
            return true;
        }

        $computed = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        return hash_equals($computed, $signatureHeader);
    }
}
