<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\MarketplaceAccount;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderStatusHistory;
use App\Services\Integrations\Marketplaces\ShopeeService;
use App\Services\WebhookOrderService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * MUL-197 — Enriquecimento de pedido-rascunho Shopee via get_order_detail.
 *
 * Nucleo compartilhado entre o EnrichShopeeOrderJob (retry/backoff automatico),
 * o endpoint manual "puxar da integracao" e o backfill.
 *
 * Token: REUSA a arquitetura NOV-181 — getValidAccessToken() e bridge-aware
 * (contas centrally_managed renovam via hub, PropagateShopeeTokenJob espelha);
 * callApi/callDirect ja faz refresh+retry no typo da Shopee `invalid_acceess_token`
 * (NOV-061). NADA de refresh local novo aqui.
 *
 * Regra: NUNCA sobrescrever valor ja preenchido no pedido.
 */
class ShopeeOrderEnricher
{
    // Codigos retryaveis (token/instabilidade) — job faz release com backoff
    public const RETRYABLE = ['token_unavailable', 'api_error', 'empty_response'];

    public function __construct(
        private readonly ShopeeService $shopee,
        private readonly DraftOrderPromoter $promoter,
    ) {}

    /**
     * Enriquece e tenta promover. Retorna:
     *   ['ok' => bool, 'code' => string, 'missing' => array]
     * Codes: promoted | still_incomplete | not_draft | unsupported_source |
     *        no_shopee_account | token_unavailable | api_error | order_not_found | empty_response
     */
    public function enrich(Order $order): array
    {
        if (! $order->is_draft) {
            return ['ok' => true, 'code' => 'not_draft', 'missing' => []];
        }

        if ($order->source !== 'shopee' || ! $order->marketplace_order_id) {
            // Nao-Shopee: sem API pra puxar — tenta promover com o que ja tem no banco
            [$promoted, $missing] = $this->promoter->promote($order, 'enricher_non_shopee');
            return [
                'ok'      => $promoted,
                'code'    => $promoted ? 'promoted' : 'unsupported_source',
                'missing' => $missing,
            ];
        }

        $account = $order->marketplace_account_id
            ? MarketplaceAccount::find($order->marketplace_account_id)
            : null;

        if (! $account || $account->platform !== 'shopee' || ! $account->shop_id) {
            $this->markDraft($order, 'no_shopee_account');
            return ['ok' => false, 'code' => 'no_shopee_account', 'missing' => []];
        }

        $order->enrich_attempts  = (int) $order->enrich_attempts + 1;
        $order->last_enriched_at = now();
        $order->saveQuietly();

        // NOV-181: caminho central de token (bridge-aware, lock redis, lazy refresh)
        $token = null;
        try {
            $token = $this->shopee->getValidAccessToken($account);
        } catch (\Throwable $e) {
            Log::channel('marketplace')->warning('[MUL-197] getValidAccessToken excecao', [
                'order_id'   => $order->id,
                'account_id' => $account->id,
                'error'      => $e->getMessage(),
            ]);
        }
        if (! $token) {
            $this->markDraft($order, 'token_unavailable');
            return ['ok' => false, 'code' => 'token_unavailable', 'missing' => []];
        }

        $resp = $this->shopee->getOrderDetail((int) $account->shop_id, $token, [(string) $order->marketplace_order_id]);
        $err  = (string) ($resp['error'] ?? '');

        // Token invalido que sobreviveu ao retry interno do callDirect (NOV-061):
        // refresh explicito pelo caminho existente + retry imediato (criterio MUL-197).
        // Typo da Shopee `invalid_acceess_token` (3 c's) tratado igual ao normal.
        if (in_array($err, ['invalid_acceess_token', 'invalid_access_token', 'error_auth'], true)) {
            try {
                if ($this->shopee->refreshToken($account)) {
                    $account->refresh();
                    $resp = $this->shopee->getOrderDetail((int) $account->shop_id, (string) $account->access_token, [(string) $order->marketplace_order_id]);
                    $err  = (string) ($resp['error'] ?? '');
                }
            } catch (\Throwable $e) {
                Log::channel('marketplace')->warning('[MUL-197] refreshToken excecao', [
                    'order_id'   => $order->id,
                    'account_id' => $account->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        if ($err !== '') {
            // error_not_found = pedido nao existe (mais) na Shopee — definitivo, retry inutil
            if (str_contains($err, 'not_found')) {
                $this->markNotFound($order, $resp);
                return ['ok' => false, 'code' => 'order_not_found', 'missing' => []];
            }
            $this->markDraft($order, 'shopee_api_error: ' . mb_substr($err . ' ' . (string) ($resp['message'] ?? ''), 0, 400));
            return ['ok' => false, 'code' => 'api_error', 'missing' => []];
        }

        $detail = $resp['response']['order_list'][0] ?? null;
        if (! $detail) {
            // resposta ok mas sem o pedido — API nao devolve mais (pedido antigo): fica rascunho
            $this->markNotFound($order, $resp);
            return ['ok' => false, 'code' => 'order_not_found', 'missing' => []];
        }

        $this->applyDetail($order, $detail, $account);

        [$promoted, $missing] = $this->promoter->promote($order->fresh(), 'enricher');

        return [
            'ok'      => $promoted,
            'code'    => $promoted ? 'promoted' : 'still_incomplete',
            'missing' => $missing,
        ];
    }

    /**
     * Aplica o get_order_detail no pedido SEM sobrescrever valores ja preenchidos.
     */
    private function applyDetail(Order $order, array $detail, MarketplaceAccount $account): void
    {
        $updates = [];

        $name = $this->extractCustomerName($detail);
        if ($name && trim((string) $order->customer_name) === '') {
            $updates['customer_name'] = $name;
        }

        $total = (float) ($detail['total_amount'] ?? 0);
        if ($total > 0 && (float) $order->total <= 0) {
            $updates['total'] = $total;
        }

        if (! empty($detail['pay_time']) && ! $order->paid_at) {
            $updates['paid_at'] = Carbon::createFromTimestamp((int) $detail['pay_time'])
                ->setTimezone(config('app.timezone'));
        }

        if (empty($order->buyer_username) && ! empty($detail['buyer_username'])) {
            $updates['buyer_username'] = $detail['buyer_username'];
        }
        if (empty($order->tracking_number) && ! empty($detail['tracking_no'])) {
            $updates['tracking_number'] = $detail['tracking_no'];
        }
        $carrier = $detail['package_list'][0]['shipping_carrier'] ?? $detail['shipping_carrier'] ?? null;
        if (empty($order->carrier_name) && $carrier) {
            $updates['carrier_name'] = $carrier;
        }

        // MUL-237: preencher marketplace_created_at se NULL
        if (empty($order->marketplace_created_at) && ! empty($detail[create_time])) {
            $updates[marketplace_created_at] = Carbon::createFromTimestamp((int) $detail[create_time])->setTimezone(config('app.timezone'));
        }

        if (! empty($updates)) {
            $order->forceFill($updates)->saveQuietly();
        }

        // Itens a partir do item_list (idempotente por external_item_id+variation)
        try {
            WebhookOrderService::upsertShopeeItemsFromPayload($order, $detail, $account);
        } catch (\Throwable $e) {
            Log::channel('marketplace')->warning('[MUL-197] upsertItems no enriquecimento falhou', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Nome do comprador do recipient_address, ignorando mascaras da Shopee
     * (pedidos antigos voltam "*****" — sem nenhum caractere alfanumerico).
     */
    private function extractCustomerName(array $detail): ?string
    {
        $name = trim((string) ($detail['recipient_address']['name'] ?? ''));
        if ($name === '' || ! preg_match('/[\p{L}0-9]/u', $name)) {
            return null;
        }
        return $name;
    }

    private function markDraft(Order $order, string $reason): void
    {
        $order->draft_reason = $reason;
        $order->saveQuietly();
    }

    /**
     * INF-036: pedido inexistente na API — status vira not_found (para de retentar)
     * e o corpo COMPLETO da resposta fica gravado em order_events.
     */
    private function markNotFound(Order $order, array $resp): void
    {
        $from = (string) $order->status;
        if ($from === OrderStatus::NOT_FOUND->value) {
            return; // ja marcado — nao duplica evento
        }

        $order->forceFill([
            'status'           => OrderStatus::NOT_FOUND->value,
            'canonical_status' => OrderStatus::NOT_FOUND->value,
            'draft_reason'     => 'order_not_found_in_api',
        ])->saveQuietly();

        OrderEvent::create([
            'order_id'    => $order->id,
            'event_type'  => 'marketplace_not_found',
            'description' => 'Shopee nao reconhece o pedido (get_order_detail) — status alterado para not_found',
            'metadata'    => [
                'api'        => 'shopee:/api/v2/order/get_order_detail',
                'order_sn'   => $order->marketplace_order_id,
                'response'   => $resp,
                'checked_at' => now()->toDateTimeString(),
            ],
        ]);

        OrderStatusHistory::record($order, 'status', $from, OrderStatus::NOT_FOUND->value, 'enricher', [
            'reason' => 'order_not_found_in_api',
        ]);
    }
}
