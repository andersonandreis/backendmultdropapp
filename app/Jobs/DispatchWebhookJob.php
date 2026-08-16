<?php

namespace App\Jobs;

use App\Models\TenantWebhookEndpoint;
use App\Models\WebhookDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Supplier Core / Fase 3 / M3 — Dispatcher de webhook pra um tenant.
 *
 * Modo SHADOW (endpoint.shadow=true): envia, mas avisa o consumidor que e teste.
 * Retry exponencial: 1m, 5m, 30m, 2h, 6h. Apos 5 falhas vira "dead".
 */
class DispatchWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const RETRY_DELAYS = [60, 300, 1800, 7200, 21600]; // segundos
    public int $tries = 1; // gerenciamos retry manualmente via next_retry_at

    public function __construct(public readonly string $deliveryId) {}

    /**
     * MUL-212: catalogo (volume alto, ~61 jobs/s) vai pra fila propria 'catalog';
     * todo o resto (pedidos, status) vai pra 'webhooks' (prioridade no worker).
     * Nunca mais despachar este job na fila default.
     */
    public static function queueFor(WebhookDelivery $d): string
    {
        return $d->event === 'federation.catalog.sync' ? 'catalog' : 'webhooks';
    }

    public function handle(): void
    {
        /** @var WebhookDelivery|null $d */
        $d = WebhookDelivery::find($this->deliveryId);
        if (!$d || $d->status === WebhookDelivery::STATUS_SUCCESS || $d->status === WebhookDelivery::STATUS_DEAD) {
            return;
        }

        /** @var TenantWebhookEndpoint|null $ep */
        $ep = TenantWebhookEndpoint::find($d->endpoint_id);
        if (!$ep || !$ep->active) {
            $d->status = WebhookDelivery::STATUS_DEAD;
            $d->response_body = 'endpoint missing or inactive';
            $d->save();
            return;
        }

        $body = is_array($d->payload) ? $d->payload : (array) $d->payload;
        $bodyJson = json_encode($body);
        $signature = 'sha256=' . hash_hmac('sha256', $bodyJson, $ep->secret);

        $headers = [
            'Content-Type'              => 'application/json',
            'Accept'                    => 'application/json',
            'X-HubAI-Event'             => $d->event,
            'X-HubAI-Signature'         => $signature,
            'X-HubAI-Idempotency-Key'   => $d->idempotency_key,
            'X-HubAI-Delivery-Attempt'  => (string) ($d->attempt + 1),
        ];
        if ($ep->shadow) {
            $headers['X-HubAI-Shadow'] = 'true';
        }

        $d->attempt += 1;

        try {
            $resp = Http::timeout(10)
                ->withHeaders($headers)
                ->withBody($bodyJson, 'application/json')
                ->post($ep->url);

            $d->response_code = $resp->status();
            $d->response_body = substr((string) $resp->body(), 0, 2000);

            if ($resp->successful()) {
                $d->status = WebhookDelivery::STATUS_SUCCESS;
                $d->next_retry_at = null;
                $d->save();
                return;
            }

            // 4xx (exceto 408/429) = nao retentar
            $code = $resp->status();
            $retryable = $code >= 500 || in_array($code, [408, 429], true);
            if (!$retryable) {
                $d->status = WebhookDelivery::STATUS_DEAD;
                $d->save();
                return;
            }
        } catch (\Throwable $e) {
            $d->response_body = substr('[exception] ' . $e->getMessage(), 0, 2000);
        }

        // Agendar proximo retry
        $idx = $d->attempt - 1;
        if (!isset(self::RETRY_DELAYS[$idx])) {
            $d->status = WebhookDelivery::STATUS_DEAD;
            $d->next_retry_at = null;
            $d->save();
            return;
        }
        $d->status = WebhookDelivery::STATUS_FAILED;
        $d->next_retry_at = Carbon::now()->addSeconds(self::RETRY_DELAYS[$idx]);
        $d->save();

        self::dispatch($d->id)->onQueue(self::queueFor($d))->delay($d->next_retry_at);
    }
}
