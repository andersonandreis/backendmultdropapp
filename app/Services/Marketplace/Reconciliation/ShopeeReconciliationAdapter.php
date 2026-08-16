<?php

namespace App\Services\Marketplace\Reconciliation;

use App\Models\MarketplaceAccount;
use App\Services\Integrations\Marketplaces\ShopeeService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Adapter de reconciliacao para a Shopee.
 *
 * Reutiliza ShopeeService (HMAC-SHA256, callApi, bridge vs direct).
 * Acessa callApi() via Closure binding — nao modifica a classe original.
 *
 * Statuses incluidos: READY_TO_SHIP, PROCESSED, SHIPPED, COMPLETED, IN_CANCEL
 * Excluidos: CANCELLED, UNPAID, TO_RETURN
 *
 * Rate limit: ~5 req/s em get_order_list. Max 2 calls por execucao.
 * Em 429, callDirect retorna ['error'=>'direct_http_error','message'=>'...429...'].
 * Capturamos e lancamos RuntimeException para abort graceful no Job.
 */
class ShopeeReconciliationAdapter implements ReconciliationAdapter
{
    private const VALID_STATUSES = [
        'READY_TO_SHIP',
        'PROCESSED',
        'SHIPPED',
        'COMPLETED',
        'IN_CANCEL',
    ];

    public function __construct(
        private readonly ShopeeService $shopeeService,
    ) {}

    public function fetchRecentOrders(MarketplaceAccount $account, Carbon $since): Collection
    {
        $results     = collect();
        $shopId      = $account->shop_id;
        $accessToken = $this->resolveToken($account);

        if (! $shopId || ! $accessToken) {
            Log::warning('[ShopeeReconciliationAdapter] shop_id ou token ausente', [
                'account_id' => $account->id,
            ]);
            return $results;
        }

        $listResponse = $this->callShopeeApi($account, '/api/v2/order/get_order_list', [
            'time_range_field' => 'create_time',
            'time_from'        => $since->getTimestamp(),
            'time_to'          => now()->getTimestamp(),
            'page_size'        => 50,
            'cursor'           => '',
            'shop_id'          => $shopId,
            'access_token'     => $accessToken,
        ], 'GET');

        if ($this->isRateLimited($listResponse)) {
            throw new \RuntimeException(
                "[ShopeeReconciliationAdapter] Rate limit em get_order_list para account #{$account->id}."
            );
        }

        $orderSnList = collect($listResponse['response']['order_list'] ?? [])
            ->pluck('order_sn')
            ->filter()
            ->values()
            ->toArray();

        if (empty($orderSnList)) {
            return $results;
        }

        foreach (array_chunk($orderSnList, 50) as $chunk) {
            $detailResponse = $this->callShopeeApi($account, '/api/v2/order/get_order_detail', [
                'order_sn_list'            => implode(',', $chunk),
                'response_optional_fields' => 'buyer_user_id,buyer_username,item_list,recipient_address,total_amount',
                'shop_id'                  => $shopId,
                'access_token'             => $accessToken,
            ], 'GET');

            if ($this->isRateLimited($detailResponse)) {
                Log::warning('[ShopeeReconciliationAdapter] Rate limit em get_order_detail — processando parcial', [
                    'account_id' => $account->id,
                ]);
                break;
            }

            foreach ($detailResponse['response']['order_list'] ?? [] as $raw) {
                try {
                    if (! in_array(strtoupper($raw['order_status'] ?? ''), self::VALID_STATUSES)) {
                        continue;
                    }
                    $results->push($this->parseOrder($raw));
                } catch (\Throwable $e) {
                    Log::warning('[ShopeeReconciliationAdapter] Parse error — skipping', [
                        'account_id' => $account->id,
                        'order_sn'   => $raw['order_sn'] ?? 'unknown',
                        'error'      => $e->getMessage(),
                    ]);
                }
            }
        }

        Log::info('[ShopeeReconciliationAdapter] Concluido', [
            'account_id' => $account->id,
            'total'      => $results->count(),
        ]);

        return $results;
    }

    // ---------------------------------------------------------------
    // Private helpers
    // ---------------------------------------------------------------

    /**
     * MUL-339: usa getValidAccessToken em vez de ler access_token direto do model.
     *
     * O access_token e gravado com encrypt() e o model nao tem cast de descriptografia. Ler
     * $account->access_token devolvia o texto CIFRADO (400 chars) no lugar do token (108), e a
     * Shopee respondia invalid_acceess_token com HTTP 403 — 8 contas, uma vez por hora, todas
     * as horas.
     *
     * getValidAccessToken ja resolve tudo: descriptografa, checa validade com margem de 5 min,
     * usa lock distribuido contra renovacao simultanea (NOV-061) e respeita is_token_broken.
     * O refreshToken interno respeita a trava do bridge (NOV-181), entao a WL nao renova conta
     * gerida pelo hub.
     */
    private function resolveToken(MarketplaceAccount $account): ?string
    {
        return $this->shopeeService->getValidAccessToken($account);
    }

    /**
     * Acessa ShopeeService::callApi() (protected) via Closure binding.
     * Zero side effects na classe original.
     */
    private function callShopeeApi(MarketplaceAccount $account, string $endpoint, array $params, string $method): array
    {
        $caller = \Closure::bind(
            fn ($ep, $p, $m) => $this->callApi($ep, $p, $m),
            $this->shopeeService,
            ShopeeService::class
        );
        return $caller($endpoint, $params, $method);
    }

    private function isRateLimited(array $response): bool
    {
        return str_contains((string) ($response['message'] ?? ''), '429')
            || ($response['error'] ?? '') === 'rate_limit';
    }

    private function parseOrder(array $raw): ReconciliationOrderDto
    {
        $orderSn     = $raw['order_sn'] ?? throw new \InvalidArgumentException('order_sn ausente');
        $amountCents = (int) round((float) ($raw['total_amount'] ?? 0) * 100);

        $products = [];
        foreach ($raw['item_list'] ?? [] as $item) {
            $products[] = [
                'sku'        => (string) ($item['item_sku'] ?? $item['item_id'] ?? ''),
                'qty'        => (int) ($item['model_quantity_purchased'] ?? $item['quantity'] ?? 1),
                'unit_price' => (int) round((float) ($item['model_discounted_price'] ?? $item['item_price'] ?? 0) * 100),
            ];
        }

        $createdAt = isset($raw['create_time'])
            ? Carbon::createFromTimestamp((int) $raw['create_time'])
            : Carbon::now();

        return new ReconciliationOrderDto(
            marketplace:        'shopee',
            marketplaceOrderId: $orderSn,
            buyerName:          $raw['buyer_username'] ?? null,
            buyerDoc:           null,
            amountCents:        $amountCents,
            currency:           'BRL',
            products:           $products,
            createdAt:          $createdAt,
            rawPayload:         $raw,
        );
    }
}
