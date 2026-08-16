<?php

namespace App\Services\Webhooks;

use App\Jobs\ProcessMLOrderJob;
use App\Jobs\SyncMLItemJob;
use App\Jobs\FetchShippingLabelJob;
use App\Models\Order;
use App\Models\ProcessedWebhookId;
use App\Services\Integrations\Contracts\WebhookHandlerInterface;
use App\Services\WebhookOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Handler de webhooks para o Mercado Livre.
 *
 * Valida assinatura HMAC-SHA256 conforme documentacao ML (2024+):
 * Header x-signature: "ts={timestamp},v1={hmac_hex}" + Header x-request-id
 * Mensagem assinada: "x-request-id:{xRequestId};x-date:{ts};"
 *
 * HUB-131: deduplicacao via ProcessedWebhookId.
 * O ML reenvia webhooks se nao recebe HTTP 200 em <5s.
 * O campo data.id e o notification_id unico por evento.
 *
 * NOV-150-B: webhook-first order creation.
 * dispatchOrder agora chama WebhookOrderService::processWebhookOrder() para criar
 * o Order diretamente no novo sistema com zero latencia, antes de despachar
 * o ProcessMLOrderJob que garante o enriquecimento de dados e backward compatibility.
 */
class MercadoLivreWebhookHandler implements WebhookHandlerInterface
{
    /**
     * Valida a assinatura HMAC-SHA256 do Mercado Livre.
     *
     * Especificacao ML (2024+):
     *   Header x-signature: "ts={timestamp},v1={hmac_hex}"
     *   Header x-request-id: UUID da requisicao
     *   Mensagem assinada: "x-request-id:{requestId};x-date:{ts};"
     *   HMAC = SHA256(client_secret, mensagem)
     *
     * Comportamento quando ML_SECRET_KEY nao esta configurado:
     *   - Loga WARNING mas ACEITA a request (feature flag — nao quebra producao).
     *   - Configurar ML_SECRET_KEY no .env para ativar a validacao completa.
     *
     * Ref: https://developers.mercadolivre.com.br/pt_br/receba-notificacoes
     */
    public function validateSignature(Request $request): bool
    {
        $secret    = config('services.mercadolivre.secret_key') ?: env('ML_SECRET_KEY');
        $signature = $request->header('x-signature');

        // Se o secret nao esta configurado, nao e possivel validar.
        // Logamos warning e aceitamos para nao bloquear producao.
        if (! $secret) {
            Log::warning('[ML-Webhook] ML_SECRET_KEY nao configurado — validacao de assinatura desativada. Configure ML_SECRET_KEY no .env para habilitar.');
            return true;
        }

        // Se o secret esta configurado mas o header esta ausente, rejeita.
        if (! $signature) {
            Log::warning('[ML-Webhook] Header x-signature ausente mas ML classico NAO assina notificacoes — fail-open (INC-002 02/07: fail-closed derrubou ingestao)', [
                'ip' => $request->ip(),
            ]);
            return true;
        }

        // Parse: "ts=1704067200,v1=abc123..."
        $parts = [];
        foreach (explode(',', $signature) as $part) {
            [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
            $parts[trim($k)] = trim($v);
        }

        $ts   = $parts['ts'] ?? '';
        $v1   = $parts['v1'] ?? '';

        if (! $ts || ! $v1) {
            Log::warning('[ML-Webhook] Header x-signature malformado', ['signature' => $signature]);
            return false;
        }

        // x-request-id: UUID unico da notificacao (pode nao estar presente em notificacoes antigas)
        $xRequestId = $request->header('x-request-id', '');

        // Mensagem conforme especificacao oficial ML (2024+):
        // "x-request-id:{requestId};x-date:{ts};"
        // Nota: se x-request-id estiver ausente, a mensagem fica "x-request-id:;x-date:{ts};"
        // o que e o comportamento correto — o ML inclui o campo vazio quando nao enviado.
        $message  = "x-request-id:{$xRequestId};x-date:{$ts};";
        $expected = hash_hmac('sha256', $message, $secret);

        $valid = hash_equals($expected, $v1);

        if (! $valid) {
            Log::warning('[ML-Webhook] Assinatura HMAC invalida', [
                'ip'          => $request->ip(),
                'ts'          => $ts,
                'x_request_id' => $xRequestId,
                'received_v1' => $v1,
            ]);
        }

        return $valid;
    }

    public function extractTopic(Request $request): string
    {
        return $request->input('topic') ?? $request->input('type') ?? 'unknown';
    }

    public function extractResource(Request $request): string
    {
        return $request->input('resource') ?? '';
    }

    public function extractUserId(Request $request): ?string
    {
        $userId = $request->input('user_id');
        return $userId !== null ? (string) $userId : null;
    }

    public function dispatchJob(string $topic, string $resource, ?string $userId): void
    {
        match ($topic) {
            'orders', 'merchant_orders' => $this->dispatchOrder($resource, $userId),
            'orders_v2'                 => $this->dispatchOrder($resource, $userId),
            'items'                     => $this->dispatchItem($resource, $userId),
            'shipments'                 => $this->dispatchShipment($resource, $userId),
            'questions'                 => Log::info('[ML-Webhook] Question recebida, ignorado por ora', compact('resource')),
            default                     => Log::info('[ML-Webhook] Topico ignorado: ' . $topic, compact('resource')),
        };
    }

    // =========================================================================
    // HUB-131: Deduplicacao por notification_id (id do payload ML)
    //
    // O ML envia um notification_id unico por evento. Se o mesmo ID chegar 2x
    // (retry do marketplace por timeout), retornamos true para que o dispatcher
    // responda HTTP 200 sem processar novamente.
    //
    // Retorna true se o evento JA foi processado (duplicata — descartar).
    // Retorna false se e a primeira vez (novo — processar normalmente).
    // =========================================================================
    public function isDuplicate(Request $request, string $topic): bool
    {
        // ML envia o notification_id no campo raiz "id" (nao dentro de data)
        $notificationId = $request->input('id')
                       ?? $request->input('data.id');

        if (! $notificationId) {
            // Sem ID identificavel — nao podemos deduplicar, processar normalmente
            return false;
        }

        $externalId = (string) $notificationId;
        $isNew      = ProcessedWebhookId::markProcessed('mercadolivre', $externalId, $topic);

        if (! $isNew) {
            Log::info('[ML-Webhook] Evento duplicado descartado (HUB-131)', [
                'notification_id' => $externalId,
                'topic'           => $topic,
            ]);
        }

        return ! $isNew;
    }

    // =========================================================================
    // Helpers internos
    // =========================================================================

    private function dispatchOrder(string $resource, ?string $userId): void
    {
        $orderId = $this->extractIdFromResource($resource);
        if (! $orderId) {
            return;
        }

        // NOV-150-B: webhook-first — criar Order diretamente no novo sistema (zero-latencia).
        // Dispara FetchShippingLabelJob e RelayOrderToLegacyJob internamente.
        // Erros sao logados e nao interrompem o fluxo — ProcessMLOrderJob e o fallback.
        try {
            app(WebhookOrderService::class)->processWebhookOrder(
                'mercadolivre',
                ['resource' => $resource],
                $userId
            );
        } catch (\Throwable $e) {
            Log::warning('[ML-Webhook] WebhookOrderService falhou (nao critico, ProcessMLOrderJob e fallback)', [
                'resource' => $resource,
                'user_id'  => $userId,
                'error'    => $e->getMessage(),
            ]);
        }

        // ProcessMLOrderJob mantido para backward compatibility:
        // enriquece dados adicionais (payment details, endereco completo)
        // e garante que o Order exista mesmo se WebhookOrderService nao encontrar a conta
        ProcessMLOrderJob::dispatch($orderId, $userId ? (int) $userId : null)
            ->onQueue('webhooks');
    }

    private function dispatchShipment(string $resource, ?string $userId): void
    {
        // resource = "/shipments/12345678"
        $shippingId = $this->extractIdFromResource($resource);

        if (!$shippingId) {
            Log::warning('[ML-Webhook] Shipment sem ID', compact('resource'));
            return;
        }

        // Busca o pedido pelo external_shipping_id
        $order = Order::where('external_shipping_id', $shippingId)->first();

        if (!$order) {
            // Pode ser um shipment novo — tenta buscar pelo ml_user_id
            Log::info('[ML-Webhook] Shipment recebido sem pedido vinculado, ignorando', [
                'shipping_id' => $shippingId,
                'user_id'     => $userId,
            ]);
            return;
        }

        // Despacha job de etiqueta com delay de 30s (evitar race condition)
        FetchShippingLabelJob::dispatch($order->id, 'webhook')
            ->onQueue('webhooks')
            ->delay(now()->addSeconds(30));

        Log::info('[ML-Webhook] Shipment → FetchShippingLabelJob despachado', [
            'order_id'    => $order->id,
            'shipping_id' => $shippingId,
        ]);
    }

    private function dispatchItem(string $resource, ?string $userId): void
    {
        // resource = "/items/MLB1234567890"
        $itemId = ltrim(parse_url($resource, PHP_URL_PATH), '/items/');
        if ($itemId) {
            SyncMLItemJob::dispatch($itemId, $userId ? (int) $userId : null)
                ->onQueue('webhooks');
        }
    }

    private function extractIdFromResource(string $resource): ?string
    {
        $parts = explode('/', trim($resource, '/'));
        return end($parts) ?: null;
    }
}
