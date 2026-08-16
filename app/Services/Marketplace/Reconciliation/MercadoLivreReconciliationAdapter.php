<?php

namespace App\Services\Marketplace\Reconciliation;

use App\Models\MarketplaceAccount;
use App\Services\Integrations\Marketplaces\MercadoLivreService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Adapter de reconciliacao para o Mercado Livre.
 *
 * Reutiliza MercadoLivreService::refreshToken() para renovacao de token.
 * Chama GET /orders/search diretamente com paginacao offset.
 *
 * Rate limit: ~60 req/min por token.
 * MAX_PAGES = 3 => max 150 pedidos => max 4 requests por execucao.
 * Em 429: RuntimeException para retry na proxima hora.
 * Em 401: renova token uma vez antes de abortar.
 *
 * Statuses incluidos: paid, partially_paid
 * Excluidos: cancelled, invalid, refunded
 */
class MercadoLivreReconciliationAdapter implements ReconciliationAdapter
{
    private const BASE_URL  = 'https://api.mercadolibre.com';
    private const PAGE_SIZE = 50;
    private const MAX_PAGES = 3;

    private const VALID_STATUSES = ['paid', 'partially_paid'];

    public function __construct(
        private readonly MercadoLivreService $mlService,
    ) {}

    public function fetchRecentOrders(MarketplaceAccount $account, Carbon $since): Collection
    {
        $results  = collect();
        $token    = $this->resolveToken($account);
        $sellerId = $account->ml_user_id;

        if (! $token || ! $sellerId) {
            Log::warning('[MercadoLivreReconciliationAdapter] token ou ml_user_id ausente', [
                'account_id' => $account->id,
            ]);
            return $results;
        }

        $dateFrom = $since->toIso8601String();
        $dateTo   = now()->toIso8601String();
        $offset   = 0;
        $page     = 0;

        do {
            $page++;
            $response = Http::withToken($token)
                ->timeout(30)
                ->get(self::BASE_URL . '/orders/search', [
                    'seller'                  => $sellerId,
                    'order.date_created.from' => $dateFrom,
                    'order.date_created.to'   => $dateTo,
                    'sort'                    => 'date_desc',
                    'offset'                  => $offset,
                    'limit'                   => self::PAGE_SIZE,
                ]);

            if ($response->status() === 429) {
                Log::warning('[MercadoLivreReconciliationAdapter] Rate limit 429', [
                    'account_id' => $account->id,
                    'page'       => $page,
                ]);
                throw new \RuntimeException(
                    "[MercadoLivreReconciliationAdapter] Rate limit para account #{$account->id}. Retry na proxima execucao."
                );
            }

            if ($response->status() === 401) {
                Log::info('[MercadoLivreReconciliationAdapter] 401 — renovando token mid-flight', [
                    'account_id' => $account->id,
                ]);
                $token = $this->mlService->refreshToken($account);
                if (! $token) {
                    throw new \RuntimeException(
                        "[MercadoLivreReconciliationAdapter] Token nao renovavel para account #{$account->id}."
                    );
                }
                continue;
            }

            if ($response->status() === 403) {
                // FOR-087: 403 do ML = token revogado/bloqueado. Marcar needs_reauth e abortar.
                // Repete a mesma semantica de MercadoLivreService::markReauthIfHtmlForbidden().
                $account->update([
                    'needs_reauth'       => 1,
                    'sync_blocked_at'    => now(),
                    'sync_errors_count'  => 99,
                    'last_error_message' => 'reconciliation_403: token revogado/bloqueado no ML. Requer reconexao OAuth.',
                ]);
                Log::warning('[MercadoLivreReconciliationAdapter] 403 — needs_reauth marcado, abortando reconciliacao', [
                    'account_id' => $account->id,
                    'body'       => mb_substr($response->body(), 0, 200),
                ]);
                return $results; // sai limpo, sem logar ERROR desnecessario
            }

            if ($response->failed()) {
                Log::error('[MercadoLivreReconciliationAdapter] Falha HTTP', [
                    'account_id' => $account->id,
                    'status'     => $response->status(),
                    'body'       => mb_substr($response->body(), 0, 500),
                ]);
                break;
            }

            $data   = $response->json();
            $orders = $data['results'] ?? [];
            $total  = $data['paging']['total'] ?? 0;

            foreach ($orders as $raw) {
                try {
                    if (! in_array(strtolower($raw['status'] ?? ''), self::VALID_STATUSES)) {
                        continue;
                    }
                    $results->push($this->parseOrder($raw));
                } catch (\Throwable $e) {
                    Log::warning('[MercadoLivreReconciliationAdapter] Parse error — skipping', [
                        'account_id' => $account->id,
                        'order_id'   => $raw['id'] ?? 'unknown',
                        'error'      => $e->getMessage(),
                    ]);
                }
            }

            $offset += self::PAGE_SIZE;

        } while (count($orders) === self::PAGE_SIZE && $offset < $total && $page < self::MAX_PAGES);

        Log::info('[MercadoLivreReconciliationAdapter] Concluido', [
            'account_id' => $account->id,
            'total'      => $results->count(),
        ]);

        return $results;
    }

    // ---------------------------------------------------------------
    // Private helpers
    // ---------------------------------------------------------------

    private function resolveToken(MarketplaceAccount $account): ?string
    {
        $token = $account->ml_access_token;
        if (! $token) {
            return null;
        }
        if ($account->ml_token_expires_at && now()->gte($account->ml_token_expires_at)) {
            return $this->mlService->refreshToken($account);
        }
        return $token;
    }

    private function parseOrder(array $raw): ReconciliationOrderDto
    {
        $orderId     = (string) ($raw['id'] ?? throw new \InvalidArgumentException('order.id ausente'));
        $amountCents = (int) round((float) ($raw['total_amount'] ?? 0) * 100);
        $currency    = strtoupper($raw['currency_id'] ?? 'BRL');

        $buyer     = $raw['buyer'] ?? [];
        $buyerName = $buyer['nickname'] ?? $buyer['first_name'] ?? null;
        $buyerDoc  = $buyer['billing_info']['doc_number'] ?? null;

        $products = [];
        foreach ($raw['order_items'] ?? [] as $item) {
            $products[] = [
                'sku'        => (string) ($item['item']['seller_sku'] ?? $item['item']['id'] ?? ''),
                'qty'        => (int) ($item['quantity'] ?? 1),
                'unit_price' => (int) round((float) ($item['unit_price'] ?? 0) * 100),
            ];
        }

        $createdAt = isset($raw['date_created'])
            ? Carbon::parse($raw['date_created'])
            : Carbon::now();

        return new ReconciliationOrderDto(
            marketplace:        'ml',
            marketplaceOrderId: $orderId,
            buyerName:          $buyerName,
            buyerDoc:           $buyerDoc,
            amountCents:        $amountCents,
            currency:           $currency,
            products:           $products,
            createdAt:          $createdAt,
            rawPayload:         $raw,
        );
    }
}
