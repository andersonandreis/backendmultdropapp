<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Models\MarketplaceAccount;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderStatusHistory;
use App\Services\MercadoLivreService;
use App\Services\Orders\DraftOrderPromoter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * INF-036 B — Enriquecimento de pedido-rascunho Mercado Livre.
 *
 * Espelha EnrichShopeeOrderJob (MUL-197):
 *  - GET https://api.mercadolibre.com/orders/{external_order_id}
 *  - aplica campos ausentes SEM sobrescrever (regra decisao Ruan)
 *  - chama DraftOrderPromoter::promote
 *
 * Retry:
 *  - token invalido: refresh via MercadoLivreService::getValidToken + retry na hora
 *  - api 5xx / timeout: release com backoff 5/15/60 min, tries=4
 *  - order not found: para de tentar (draft_reason=order_not_found_in_api)
 */
class EnrichMercadoLivreOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 4;
    public int $timeout = 90;

    public function __construct(public readonly int $orderId)
    {
        // Fila default sofre backlog de DispatchWebhookJob (400k+ em 09/07)
        $this->onQueue('high-priority');
    }

    public function backoff(): array
    {
        return [300, 900, 3600];
    }

    public function handle(MercadoLivreService $ml, DraftOrderPromoter $promoter): void
    {
        $order = Order::find($this->orderId);
        if (! $order || ! $order->is_draft) {
            return;
        }
        if ($order->source !== 'mercadolivre' || ! $order->external_order_id) {
            return;
        }

        $account = $order->marketplace_account_id
            ? MarketplaceAccount::find($order->marketplace_account_id)
            : null;
        if (! $account || $account->platform !== 'mercadolivre') {
            $this->markDraft($order, 'no_ml_account');
            return;
        }

        $order->enrich_attempts  = (int) $order->enrich_attempts + 1;
        $order->last_enriched_at = now();
        $order->saveQuietly();

        try {
            $token = $ml->getValidToken($account);
        } catch (\Throwable $e) {
            Log::channel('marketplace')->warning('[INF-036] getValidToken ML falhou', [
                'order_id'   => $this->orderId,
                'account_id' => $account->id,
                'error'      => $e->getMessage(),
            ]);
            $this->markDraft($order, 'token_unavailable');
            return;
        }

        if (! $token) {
            $this->markDraft($order, 'token_unavailable');
            return;
        }

        $resp = Http::withToken($token)
            ->timeout(20)
            ->acceptJson()
            ->get("https://api.mercadolibre.com/orders/{$order->external_order_id}");

        if ($resp->status() === 404) {
            $this->markNotFound($order, $resp->status(), $resp->json() ?? ['raw' => mb_substr($resp->body(), 0, 2000)]);
            return;
        }
        if ($resp->status() === 401 || $resp->status() === 403) {
            $this->markDraft($order, 'invalid_access_token');
            $this->releaseWithBackoff();
            return;
        }
        if (! $resp->successful()) {
            $this->markDraft($order, 'ml_api_error: HTTP ' . $resp->status());
            $this->releaseWithBackoff();
            return;
        }

        $detail = $resp->json();
        if (! is_array($detail) || empty($detail['id'])) {
            $this->markDraft($order, 'empty_response');
            $this->releaseWithBackoff();
            return;
        }

        $this->applyDetail($order, $detail);

        // INF-037: sem upsert de itens o promoter nunca fecha "items" e o
        // rascunho fica orfao pra sempre (enricher Shopee ja fazia isso).
        try {
            \App\Services\WebhookOrderService::upsertMLItemsFromPayload($order, $detail, $account);
        } catch (\Throwable $e) {
            Log::channel('marketplace')->warning('[INF-037] upsert de itens ML falhou no enricher', [
                'order_id' => $this->orderId,
                'error'    => $e->getMessage(),
            ]);
        }

        [$promoted, $missing] = $promoter->promote($order->fresh(), 'ml_enricher');
        if (! $promoted) {
            $reason = 'incomplete: ' . implode(',', $missing);
            $this->markDraft($order, mb_substr($reason, 0, 100));
        }
    }

    /**
     * Aplica dados da resposta ML no Order SEM sobrescrever valores ja preenchidos.
     * Idempotente: chamar 2x com mesma resposta nao muda nada.
     */
    private function applyDetail(Order $order, array $detail): void
    {
        $updates = [];

        // Cliente
        $buyer = $detail['buyer'] ?? [];
        $name  = trim(($buyer['first_name'] ?? '') . ' ' . ($buyer['last_name'] ?? ''));
        if ($name !== '' && trim((string) $order->customer_name) === '') {
            $updates['customer_name'] = $name;
        }
        if (empty($order->buyer_username) && ! empty($buyer['nickname'])) {
            $updates['buyer_username'] = $buyer['nickname'];
        }
        if (empty($order->buyer_id) && ! empty($buyer['id'])) {
            $updates['buyer_id'] = (string) $buyer['id'];
        }

        // Financeiro
        $total = (float) ($detail['total_amount'] ?? 0);
        if ($total > 0 && (float) $order->total <= 0) {
            $updates['total']    = $total;
            $updates['subtotal'] = $total;
        }

        // Envio e data da venda (MUL-423): o detalhe traz shipping.id e date_created.
        // Sem o shipping.id o agendamento de etiqueta (MUL-205) nao tem o que consultar.
        if (empty($order->external_shipping_id) && ! empty($detail['shipping']['id'])) {
            $updates['external_shipping_id'] = (string) $detail['shipping']['id'];
        }
        if (! $order->marketplace_created_at && ! empty($detail['date_created'])) {
            try {
                $updates['marketplace_created_at'] = Carbon::parse($detail['date_created'])
                    ->setTimezone(config('app.timezone'));
            } catch (\Throwable) { /* ignora */ }
        }

        // Pagamento
        if (! $order->paid_at && ! empty($detail['date_closed'])) {
            try {
                $updates['paid_at'] = Carbon::parse($detail['date_closed'])
                    ->setTimezone(config('app.timezone'));
            } catch (\Throwable) { /* ignora */ }
        }

        if (! empty($updates)) {
            $order->forceFill($updates)->saveQuietly();
        }
    }

    private function markDraft(Order $order, string $reason): void
    {
        $order->forceFill([
            'is_draft'     => true,
            'draft_reason' => mb_substr($reason, 0, 100),
        ])->saveQuietly();
    }

    /**
     * INF-036: 404 na API ML — status vira not_found (para de retentar)
     * e o corpo completo da resposta fica em order_events.
     */
    private function markNotFound(Order $order, int $httpStatus, mixed $body): void
    {
        $from = (string) $order->status;
        if ($from === OrderStatus::NOT_FOUND->value) {
            return;
        }

        $order->forceFill([
            'status'           => OrderStatus::NOT_FOUND->value,
            'canonical_status' => OrderStatus::NOT_FOUND->value,
            'is_draft'         => true,
            'draft_reason'     => 'order_not_found_in_api',
        ])->saveQuietly();

        OrderEvent::create([
            'order_id'    => $order->id,
            'event_type'  => 'marketplace_not_found',
            'description' => 'Mercado Livre nao reconhece o pedido (GET /orders/{id} HTTP ' . $httpStatus . ') — status alterado para not_found',
            'metadata'    => [
                'api'         => 'mercadolivre:/orders/' . $order->external_order_id,
                'http_status' => $httpStatus,
                'response'    => $body,
                'checked_at'  => now()->toDateTimeString(),
            ],
        ]);

        OrderStatusHistory::record($order, 'status', $from, OrderStatus::NOT_FOUND->value, 'enricher', [
            'reason' => 'order_not_found_in_api',
        ]);
    }

    private function releaseWithBackoff(): void
    {
        if ($this->attempts() < $this->tries) {
            $delay = $this->backoff()[$this->attempts() - 1] ?? 3600;
            $this->release($delay);
        }
    }
}
