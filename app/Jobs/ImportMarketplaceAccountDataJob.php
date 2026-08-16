<?php

namespace App\Jobs;

use App\Jobs\AutoLinkMarketplaceProductsJob;
use App\Models\ClientProduct;
use App\Models\MarketplaceAccount;
use App\Models\Order;
use App\Models\Product;
use App\Services\Integrations\Marketplaces\MercadoLivreService;
use App\Services\Integrations\Erps\Bling\BlingOrderSync;
use App\Services\Integrations\Erps\Bling\BlingProductSync;
use App\Services\Integrations\Marketplaces\ShopeeService;
use App\Services\WebhookOrderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ImportMarketplaceAccountDataJob
 *
 * Roda quando uma nova conta marketplace e conectada (status -> active).
 * Importa todos os produtos e pedidos dos ultimos 7 dias da conta recem conectada.
 *
 * Fluxo Shopee:
 *   1. get_item_list (todos os itens ativos, paginando por cursor)
 *   2. get_item_base_info (detalhes em lotes de 50)
 *   3. Cria/atualiza client_products vinculando ao products existente por SKU
 *   4. fetchOrders ultimos 7 dias
 *
 * Fluxo ML:
 *   1. GET /users/{seller_id}/items/search?status=active (paginando por offset)
 *   2. GET /items/{id} para cada item
 *   3. Cria/atualiza client_products vinculando ao products existente por SKU
 *   4. fetchOrders ultimos 7 dias
 */
class ImportMarketplaceAccountDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 600;

    /**
     * HUB-113: queue dedicada legacy-import (ver SyncLegacyOrdersJob para contexto).
     * Este job e o que estava criando 12.793 orders bling com tenant_slug NULL nas 24h
     * antes do fix (via BlingOrderSync — agora com guard de supplier/tenant).
     * Aplicado via __construct para evitar conflito de tipo com a trait Queueable.
     */
    public function __construct(
        public readonly int $accountId,
        public readonly bool $force = false
    ) {
        $this->onQueue('legacy-import');
    }

    public function handle(ShopeeService $shopee, MercadoLivreService $ml, BlingProductSync $blingProductSync, BlingOrderSync $blingOrderSync): void
    {
        $account = MarketplaceAccount::find($this->accountId);

        if (! $account) {
            Log::channel('marketplace')->warning('[ImportMarketplaceAccountDataJob] Conta nao encontrada', [
                'account_id' => $this->accountId,
            ]);
            return;
        }

        if ($account->status !== 'active') {
            Log::channel('marketplace')->info('[ImportMarketplaceAccountDataJob] Conta nao esta active, ignorando', [
                'account_id' => $this->accountId,
                'status'     => $account->status,
            ]);
            return;
        }

        Log::channel('marketplace')->info('[ImportMarketplaceAccountDataJob] Iniciando importacao historica', [
            'account_id' => $this->accountId,
            'platform'   => $account->platform,
            'client_id'  => $account->client_id,
        ]);

        try {
            if ($account->platform === 'shopee') {
                $this->importShopee($account, $shopee);
            } elseif ($account->platform === 'mercadolivre') {
                $this->importML($account, $ml);
            } elseif ($account->platform === 'bling') {
                // MUL-183: guard de RE-conexao — se o supplier ja tem catalogo, um full import
                // e quase sempre indesejado (reconexao OAuth recriou 2.524 products no sup errado
                // em 04/07). Full import em supplier populado exige force=true explicito.
                $existingProducts = \App\Models\Product::withoutGlobalScopes()
                    ->where('supplier_id', $account->supplier_id)
                    ->count();
                if ($existingProducts > 0 && ! $this->force) {
                    Log::channel('marketplace')->warning('[ImportMarketplaceAccountDataJob] Bling full import BLOQUEADO — supplier ja tem catalogo (MUL-183 guard). Use force=true se for intencional.', [
                        'account_id'        => $account->id,
                        'supplier_id'       => $account->supplier_id,
                        'existing_products' => $existingProducts,
                    ]);
                    return;
                }
                $productStats = $blingProductSync->syncAll($account);
                // MUL-212 F2 / MUL-214 item 35: pedidos Bling seguem o mesmo guard —
                // WL nao puxa pedido de conta central; hub entrega via fanout
                $cfgBling = app(\App\Services\InstallationConfig::class);
                $orderStats = ['skipped' => 'pull de pedidos desativado nesta instalacao (MUL-212 F2)'];
                // MUL-311: importacao historica de pedidos desligada por decisao do Ruan (31/07/2026).
                if (! config('imports.auto_orders_on_connect', false)) {
                    $orderStats = ['skipped' => 'MUL-311: importacao automatica de pedidos desligada'];
                } elseif ($cfgBling->pullsOrders('bling') && ! $cfgBling->skipsCentralAccountPull((bool) $account->centrally_managed)) {
                    $orderStats = $blingOrderSync->syncAll($account);
                }
                Log::info('[ImportMarketplaceAccountDataJob] Bling sync', [
                    'account_id' => $account->id,
                    'products'   => $productStats,
                    'orders'     => $orderStats,
                ]);
            } else {
                Log::channel('marketplace')->info('[ImportMarketplaceAccountDataJob] Plataforma nao suportada neste job', [
                    'platform' => $account->platform,
                ]);
            }
            AutoLinkMarketplaceProductsJob::dispatch($account->id)->delay(now()->addSeconds(5));
        } catch (\Throwable $e) {
            Log::channel('marketplace')->error('[ImportMarketplaceAccountDataJob] Erro durante importacao', [
                'account_id' => $this->accountId,
                'platform'   => $account->platform,
                'error'      => $e->getMessage(),
            ]);
            $this->fail($e);
        }
    }

    // =========================================================================
    // SHOPEE
    // =========================================================================

    private function importShopee(MarketplaceAccount $account, ShopeeService $shopee): void
    {
        $shopId      = $account->shop_id;
        $accessToken = $shopee->getValidAccessToken($account);

        if (! $shopId || ! $accessToken) {
            Log::channel('marketplace')->warning('[ImportMarketplaceAccountDataJob][Shopee] shop_id ou token ausente', [
                'account_id' => $account->id,
            ]);
            return;
        }

        // 1. Listar todos os item_ids ativos via cursor pagination
        $itemIds  = [];
        $cursor   = '';
        $pageSize = 100;
        $maxPages = 50;
        $page     = 0;

        do {
            $params = [
                'page_size'    => $pageSize,
                'item_status'  => 'NORMAL',
                'offset'       => 0,
                'shop_id'      => $shopId,
                'access_token' => $accessToken,
            ];

            if ($cursor !== '') {
                $params['cursor'] = $cursor;
            }

            $response = $this->callShopeeApi($account, '/api/v2/product/get_item_list', $params, 'GET', $shopee);
            $items    = $response['response']['item'] ?? [];
            $hasMore  = (bool) ($response['response']['has_next_page'] ?? false);
            $cursor   = $response['response']['next_cursor'] ?? '';

            foreach ($items as $item) {
                $itemIds[] = $item['item_id'];
            }

            $page++;
        } while ($hasMore && $cursor && $page < $maxPages);

        Log::channel('marketplace')->info('[ImportMarketplaceAccountDataJob][Shopee] item_ids obtidos', [
            'account_id' => $account->id,
            'total'      => count($itemIds),
        ]);

        $productsCreated = 0;
        $productsLinked  = 0;
        $productsSkipped = 0;

        if (! empty($itemIds)) {
            // 2. Buscar detalhes em lotes de 50
            foreach (array_chunk($itemIds, 50) as $chunk) {
                $detailParams = [
                    'item_id_list'          => implode(',', $chunk),
                    'need_tax_info'         => false,
                    'need_complaint_policy' => false,
                    'shop_id'               => $shopId,
                    'access_token'          => $accessToken,
                ];

                $detailResponse = $this->callShopeeApi($account, '/api/v2/product/get_item_base_info', $detailParams, 'GET', $shopee);
                $itemDetails    = $detailResponse['response']['item_list'] ?? [];

                foreach ($itemDetails as $item) {
                    $result = $this->upsertShopeeClientProduct($account, $item);
                    if ($result === 'created') {
                        $productsCreated++;
                    } elseif ($result === 'linked') {
                        $productsLinked++;
                    } else {
                        $productsSkipped++;
                    }
                }
            }
        }

        Log::channel('marketplace')->info('[ImportMarketplaceAccountDataJob][Shopee] Produtos processados', [
            'account_id'  => $account->id,
            'total_items' => count($itemIds),
            'created'     => $productsCreated,
            'linked'      => $productsLinked,
            'skipped'     => $productsSkipped,
        ]);

        // 3. Importar pedidos dos ultimos 7 dias
        $this->importShopeeOrders($account, $shopee);
    }

    private function upsertShopeeClientProduct(MarketplaceAccount $account, array $item): string
    {
        $itemId   = $item['item_id'] ?? null;
        $itemSku  = $item['item_sku'] ?? null;
        // Normaliza SKU vazio para evitar Duplicate entry em (client_id, custom_sku)
        if (empty($itemSku)) {
            $itemSku = "shopee-" . $itemId;
        }
        $itemName = $item['item_name'] ?? 'Produto Shopee';
        $price    = $item['price_info'][0]['current_price'] ?? null;
        $itemUrl  = 'https://shopee.com.br/product/' . $account->shop_id . '/' . $itemId;
        $imageUrl = $item['image']['image_url_list'][0] ?? $item['image']['image_url'] ?? null;

        if (! $itemId) {
            return 'skipped';
        }

        $existing = ClientProduct::where('marketplace_account_id', $account->id)
            ->where('external_listing_id', (string) $itemId)
            ->first();

        if ($existing) {
            return 'skipped';
        }

        // FOR-055: so busca por SKU real (nao "shopee-xxx" gerado automaticamente)
        $product = null;
        if ($itemSku && ! str_starts_with($itemSku, 'shopee-')) {
            $product = Product::where('sku', $itemSku)
                ->where('supplier_id', $account->supplier_id)
                ->first();

            if (! $product) {
                $product = Product::where('sku', $itemSku)->first();
            }
        }

        // FOR-055: igual FOR-044 (ML) — NAO criar Product fake com sku=shopee-<id>
        // Isso quebrava: price=preco venda Shopee em vez de custo real,
        // name nao batia catalogo fornecedor, order_items sem produto real.
        // Fallback: tenta match por name exato + supplier_id; se nao achar, product_id=NULL.
        if (! $product && $itemName && $itemName !== 'Produto Shopee') {
            $product = Product::where('name', $itemName)
                ->where('supplier_id', $account->supplier_id)
                ->where('sku', 'NOT LIKE', 'shopee-%')
                ->first();
            if (! $product) {
                $product = Product::where('name', $itemName)
                    ->where('sku', 'NOT LIKE', 'shopee-%')
                    ->first();
            }
        }
        // Deliberadamente NAO cria Product fake. Fornecedor mapeia via painel.

        try {
            ClientProduct::create([
                'client_id'              => $account->client_id,
                'product_id'             => $product ? $product->id : null,
                'marketplace_account_id' => $account->id,
                'external_listing_id'    => (string) $itemId,
                'external_listing_url'   => $itemUrl,
                'sync_status'            => 'synced',
                'listing_status'         => 'active',
                'custom_title'           => $itemName,
                'custom_sku'             => $itemSku,
                'custom_price'           => $price,
                'is_active'              => true,
                'last_sync_at'           => now(),
                'image_url'              => $imageUrl,
            ]);
        } catch (\Throwable $e) {
            Log::channel('marketplace')->warning('[ImportMarketplaceAccountDataJob][Shopee] Falha ao criar client_product', [
                'item_id' => $itemId,
                'error'   => $e->getMessage(),
            ]);
            return 'skipped';
        }

        if ($product && $product->wasRecentlyCreated) {
            return 'created';
        }
        return 'linked';
    }

    private function importShopeeOrders(MarketplaceAccount $account, ShopeeService $shopee): void
    {
        // MUL-311: importacao historica de PEDIDOS desligada por decisao do Ruan (31/07/2026).
        // Produto continua importando; pedido novo continua entrando por webhook.
        if (! config('imports.auto_orders_on_connect', false)) {
            \Illuminate\Support\Facades\Log::channel('marketplace')->info('[MUL-311] importacao automatica de pedidos DESLIGADA — pulando', [
                'account_id' => $account->id,
                'platform'   => $account->platform,
            ]);
            return;
        }

        // MUL-212 F2 / MUL-214 item 35: WL nunca puxa PEDIDOS de conta gerida
        // centralmente — o hub puxa e entrega via fanout (produtos seguem normais).
        $cfgOrders = app(\App\Services\InstallationConfig::class);
        if (! $cfgOrders->pullsOrders('shopee') || $cfgOrders->skipsCentralAccountPull((bool) $account->centrally_managed)) {
            Log::channel('marketplace')->info('[ImportMarketplaceAccountDataJob] Pull de pedidos desativado nesta instalacao (MUL-212 F2) — skip orders', [
                'account_id' => $account->id,
                'platform'   => $account->platform,
            ]);
            return;
        }

        try {
            $orders = $shopee->fetchOrders($account, now()->subDays(7)->toDateTimeString());
        } catch (\Throwable $e) {
            Log::channel('marketplace')->error('[ImportMarketplaceAccountDataJob][Shopee] fetchOrders exception', [
                'account_id' => $account->id,
                'error'      => $e->getMessage(),
            ]);
            return;
        }

        $created = 0;
        $skipped = 0;

        foreach ($orders as $rawOrder) {
            $orderSn = $rawOrder['order_sn'] ?? null;
            if (! $orderSn) {
                continue;
            }

            // MUL-082: importar SOMENTE pedidos com status 'A Enviar' (READY_TO_SHIP).
            // Motivo: evitar duplicar com pedidos ja no banco antigo. Depois que o cliente
            // conecta, so importar o que ainda precisa ser processado (a enviar).
            $shopeeStatus = strtolower($rawOrder['order_status'] ?? '');
            if ($shopeeStatus !== 'ready_to_ship') {
                $skipped++;
                continue;
            }

            // MUL-082: dedup robusto — considera marketplace_order_id (novos OAuth) E
            // external_order_id (pedidos legados importados do banco antigo).
            // Sem isso, um seller ja com pedidos Shopee no legado viraria duplicatas
            // apos reconectar OAuth (marketplace_order_id NULL no legado).
                        // MUL-187: sem filtro de source — pedido pode ja existir via Bling/legado
            // com o mesmo marketplace_order_id; filtrar por source criava duplicata zerada.
$exists = Order::where('client_id', $account->client_id)
                ->where(function ($q) use ($orderSn) {
                    $q->where('marketplace_order_id', $orderSn)
                      ->orWhere('external_order_id', $orderSn);
                })
                ->exists();

            if ($exists) {
                $skipped++;
                continue;
            }


            try {
                // MUL-197: pedido do import inicial nasce RASCUNHO — so vira pedido
                // normal na promocao (completo). Sem fanout/efeitos/AutoPay no draft.
                $payTime = ! empty($rawOrder['pay_time'])
                    ? \Carbon\Carbon::createFromTimestamp((int) $rawOrder['pay_time'])->setTimezone(config('app.timezone'))
                    : null;
                $recipientName = trim((string) ($rawOrder['recipient_address']['name'] ?? ''));
                if ($recipientName === '' || ! preg_match('/[\p{L}0-9]/u', $recipientName)) {
                    $recipientName = null; // mascara "*****" da Shopee nao vale como nome
                }

                $newOrder = Order::create([
                    'client_id'              => $account->client_id,
                    'supplier_id'            => $account->supplier_id ?? 1,
                    'marketplace_account_id' => $account->id,
                    'source'                 => 'shopee',
                    'marketplace_order_id'   => $orderSn,
                    'order_number'           => $orderSn,
                    'shop_id'                => (string) $account->shop_id,
                    'status'                 => $this->mapShopeeStatus($shopeeStatus),
                    'canonical_status'       => $this->mapShopeeStatus($shopeeStatus),
                    'total'                  => $rawOrder['total_amount'] ?? 0,
                    'customer_name'          => $recipientName,
                    'paid_at'                => $payTime,
                    'buyer_username'         => $rawOrder['buyer_username'] ?? null,
                    'tracking_number'        => $rawOrder['tracking_no'] ?? null,
                    'carrier_name'           => $rawOrder['package_list'][0]['shipping_carrier'] ?? $rawOrder['shipping_carrier'] ?? null,
                    'raw_payload'            => json_encode($rawOrder),
                    'is_draft'               => true,
                    'draft_reason'           => 'awaiting_validation',
                ]);

                // MES-043: sincronizar itens do pedido a partir do item_list no payload
                try {
                    WebhookOrderService::upsertShopeeItemsFromPayload($newOrder, $rawOrder, $account);
                } catch (\Throwable $e) {
                    Log::channel('marketplace')->warning('[ImportMarketplaceAccountDataJob][Shopee] upsertItems falhou', [
                        'order_sn' => $orderSn,
                        'error'    => $e->getMessage(),
                    ]);
                }

                // MUL-197: promove na hora se completo; senao enriquece via API com retry
                try {
                    [$promoted] = app(\App\Services\Orders\DraftOrderPromoter::class)->promote($newOrder, 'import_shopee');
                    if (! $promoted) {
                        \App\Jobs\EnrichShopeeOrderJob::dispatch($newOrder->id)->delay(now()->addSeconds(60));
                    }
                } catch (\Throwable $e) {
                    Log::channel('marketplace')->warning('[ImportMarketplaceAccountDataJob][Shopee] promocao/enrich falhou', [
                        'order_sn' => $orderSn,
                        'error'    => $e->getMessage(),
                    ]);
                }

                $created++;
            } catch (\Throwable $e) {
                Log::channel('marketplace')->warning('[ImportMarketplaceAccountDataJob][Shopee] Falha ao criar pedido', [
                    'order_sn' => $orderSn,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        Log::channel('marketplace')->info('[ImportMarketplaceAccountDataJob][Shopee] Pedidos importados 7d', [
            'account_id' => $account->id,
            'total_api'  => count($orders),
            'created'    => $created,
            'skipped'    => $skipped,
        ]);
    }

    // =========================================================================
    // MERCADO LIVRE
    // =========================================================================

    private function importML(MarketplaceAccount $account, MercadoLivreService $ml): void
    {
        $token    = $this->resolveMLToken($account, $ml);
        $sellerId = $account->ml_user_id;

        if (! $token || ! $sellerId) {
            Log::channel('marketplace')->warning('[ImportMarketplaceAccountDataJob][ML] token ou seller_id ausente', [
                'account_id' => $account->id,
                'seller_id'  => $sellerId,
            ]);
            return;
        }

        // 1. Listar todos os item_ids ativos (offset pagination)
        $itemIds  = [];
        $offset   = 0;
        $limit    = 100;
        $maxItems = 5000;

        do {
            $response = Http::withToken($token)->get('https://api.mercadolibre.com/users/' . $sellerId . '/items/search', [
                'status' => 'active',
                'offset' => $offset,
                'limit'  => $limit,
            ]);

            if ($response->failed()) {
                Log::channel('marketplace')->error('[ImportMarketplaceAccountDataJob][ML] items/search falhou', [
                    'account_id' => $account->id,
                    'status'     => $response->status(),
                ]);
                break;
            }

            $data    = $response->json();
            $results = $data['results'] ?? [];
            $total   = $data['paging']['total'] ?? 0;

            foreach ($results as $id) {
                $itemIds[] = $id;
            }

            $offset += $limit;
        } while ($offset < $total && count($itemIds) < $maxItems && ! empty($results));

        Log::channel('marketplace')->info('[ImportMarketplaceAccountDataJob][ML] item_ids obtidos', [
            'account_id' => $account->id,
            'total'      => count($itemIds),
        ]);

        $productsCreated = 0;
        $productsLinked  = 0;
        $productsSkipped = 0;

        // 2. Buscar detalhes de cada item
        foreach (array_chunk($itemIds, 20) as $chunk) {
            foreach ($chunk as $itemId) {
                $itemResponse = Http::withToken($token)->get('https://api.mercadolibre.com/items/' . $itemId);

                if ($itemResponse->failed()) {
                    Log::channel('marketplace')->warning('[ImportMarketplaceAccountDataJob][ML] /items/{id} falhou', [
                        'item_id' => $itemId,
                        'status'  => $itemResponse->status(),
                    ]);
                    continue;
                }

                $itemData = $itemResponse->json();
                $result   = $this->upsertMLClientProduct($account, $itemData);

                if ($result === 'created') {
                    $productsCreated++;
                } elseif ($result === 'linked') {
                    $productsLinked++;
                } else {
                    $productsSkipped++;
                }
            }

            usleep(200000);
        }

        Log::channel('marketplace')->info('[ImportMarketplaceAccountDataJob][ML] Produtos processados', [
            'account_id'  => $account->id,
            'total_items' => count($itemIds),
            'created'     => $productsCreated,
            'linked'      => $productsLinked,
            'skipped'     => $productsSkipped,
        ]);

        // 3. Importar pedidos dos ultimos 7 dias
        $this->importMLOrders($account, $ml);
    }

    private function upsertMLClientProduct(MarketplaceAccount $account, array $item): string
    {
        $itemId   = $item['id'] ?? null;
        $itemSku  = $item['seller_sku'] ?? ($item['seller_custom_field'] ?? null);
        // Normaliza SKU vazio para evitar Duplicate entry em (client_id, custom_sku)
        if (empty($itemSku)) {
            $itemSku = "ml-" . $itemId;
        }
        $itemName = $item['title'] ?? 'Produto ML';
        $price    = $item['price'] ?? null;
        $itemUrl  = $item['permalink'] ?? ('https://www.mercadolivre.com.br/item/' . $itemId);
        $status   = $item['status'] ?? 'active';
        $imageUrl = $item['thumbnail'] ?? $item['pictures'][0]['url'] ?? null;

        if (! $itemId) {
            return 'skipped';
        }

        $existing = ClientProduct::where('marketplace_account_id', $account->id)
            ->where('external_listing_id', (string) $itemId)
            ->first();

        if ($existing) {
            return 'skipped';
        }

        // FOR-044: mapeia pra produto real do catalogo do fornecedor
        // Prioridade: 1) SKU explicito com supplier_id; 2) SKU global (compartilhado);
        //             3) name exato + supplier_id.
        //   NAO criar Product fake com sku=ml-<mlbid> — isso quebrava:
        //   - price virava preco de venda ML em vez de custo real
        //   - name nao batia com catalogo do fornecedor
        //   - order_items ficavam sem produto real / imagem / SKU
        //   Se nao encontrar match, deixa product_id NULL (fornecedor mapeia depois).
        $product = null;
        if ($itemSku && ! str_starts_with($itemSku, 'ml-')) {
            $product = Product::where('sku', $itemSku)
                ->where('supplier_id', $account->supplier_id)
                ->first();
            if (! $product) {
                $product = Product::where('sku', $itemSku)->first();
            }
        }

        if (! $product && $itemName && $itemName !== 'Produto ML') {
            $product = Product::where('name', $itemName)
                ->where('supplier_id', $account->supplier_id)
                ->where('sku', 'NOT LIKE', 'ml-%')
                ->first();
            if (! $product) {
                $product = Product::where('name', $itemName)
                    ->where('sku', 'NOT LIKE', 'ml-%')
                    ->first();
            }
        }
        // Deliberadamente NAO cria Product fake. Fornecedor mapeia via painel.

        try {
            ClientProduct::create([
                'client_id'              => $account->client_id,
                'product_id'             => $product ? $product->id : null,
                'marketplace_account_id' => $account->id,
                'external_listing_id'    => (string) $itemId,
                'external_listing_url'   => $itemUrl,
                'sync_status'            => 'synced',
                'listing_status'         => $status === 'active' ? 'active' : 'inactive',
                'custom_title'           => $itemName,
                'custom_sku'             => $itemSku,
                'custom_price'           => $price,
                'is_active'              => $status === 'active',
                'last_sync_at'           => now(),
                'image_url'              => $imageUrl,
            ]);
        } catch (\Throwable $e) {
            Log::channel('marketplace')->warning('[ImportMarketplaceAccountDataJob][ML] Falha ao criar client_product', [
                'item_id' => $itemId,
                'error'   => $e->getMessage(),
            ]);
            return 'skipped';
        }

        if ($product && $product->wasRecentlyCreated) {
            return 'created';
        }
        return 'linked';
    }

    private function importMLOrders(MarketplaceAccount $account, MercadoLivreService $ml): void
    {
        // MUL-311: importacao historica de PEDIDOS desligada por decisao do Ruan (31/07/2026).
        // Produto continua importando; pedido novo continua entrando por webhook.
        if (! config('imports.auto_orders_on_connect', false)) {
            \Illuminate\Support\Facades\Log::channel('marketplace')->info('[MUL-311] importacao automatica de pedidos DESLIGADA — pulando', [
                'account_id' => $account->id,
                'platform'   => $account->platform,
            ]);
            return;
        }

        // MUL-212 F2 / MUL-214 item 35: WL nunca puxa PEDIDOS de conta gerida
        // centralmente — o hub puxa e entrega via fanout (produtos seguem normais).
        $cfgOrders = app(\App\Services\InstallationConfig::class);
        if (! $cfgOrders->pullsOrders('mercadolivre') || $cfgOrders->skipsCentralAccountPull((bool) $account->centrally_managed)) {
            Log::channel('marketplace')->info('[ImportMarketplaceAccountDataJob] Pull de pedidos desativado nesta instalacao (MUL-212 F2) — skip orders', [
                'account_id' => $account->id,
                'platform'   => $account->platform,
            ]);
            return;
        }

        $sinceDate = now()->subDays(7)->toIso8601String();

        try {
            $orders = $ml->fetchOrders($account, $sinceDate);
        } catch (\Throwable $e) {
            Log::channel('marketplace')->error('[ImportMarketplaceAccountDataJob][ML] fetchOrders exception', [
                'account_id' => $account->id,
                'error'      => $e->getMessage(),
            ]);
            return;
        }

        $created = 0;
        $skipped = 0;

        foreach ($orders as $rawOrder) {
            $mlOrderId = (string) ($rawOrder['id'] ?? '');
            if (! $mlOrderId) {
                continue;
            }

            // NOV-158: apenas pedidos nao enviados/cancelados
            $mlStatus       = strtolower($rawOrder['status'] ?? '');
            $shippingStatus = strtolower(($rawOrder['shipping'] ?? [])['status'] ?? '');
            $mlDoneStatuses = ['shipped', 'delivered', 'cancelled'];
            if ($mlStatus === 'cancelled' || in_array($shippingStatus, $mlDoneStatuses)) {
                $skipped++;
                continue;
            }

            $exists = Order::where('marketplace_order_id', $mlOrderId)
                ->where('source', 'mercadolivre')
                ->exists();

            if ($exists) {
                $skipped++;
                continue;
            }

            $buyer    = $rawOrder['buyer'] ?? [];

            try {
                // MUL-197: import ML tambem nasce RASCUNHO quando incompleto — casca
                // sem total/customer/itens nao pode subir pro front. O scheduler
                // orders:enrich-drafts re-checa e promove quando webhook/sync ML
                // completar os dados (enriquecimento via API e so Shopee nesta fase).
                $buyerName = trim((string) (($buyer['first_name'] ?? '') . ' ' . ($buyer['last_name'] ?? ''))) ?: null;

                $newOrder = Order::create([
                    'client_id'              => $account->client_id,
                    'supplier_id'            => $account->supplier_id ?? 1,
                    'marketplace_account_id' => $account->id,
                    'source'                 => 'mercadolivre',
                    'marketplace_order_id'   => $mlOrderId,
                    'order_number'           => $mlOrderId,
                    'status'                 => $this->mapMLStatus($mlStatus),
                    'canonical_status'       => $this->mapMLStatus($mlStatus),
                    'total'                  => $rawOrder['total_amount'] ?? 0,
                    'customer_name'          => $buyerName,
                    'buyer_id'               => (string) ($buyer['id'] ?? ''),
                    'buyer_nickname'         => $buyer['nickname'] ?? null,
                    'raw_payload'            => json_encode($rawOrder),
                    'is_draft'               => true,
                    'draft_reason'           => 'awaiting_validation',
                ]);

                // MUL-197: gravar itens do payload (order_items) — antes o import ML nao
                // criava itens nenhum, garantindo casca. Idempotente por external_item_id.
                try {
                    WebhookOrderService::upsertMLItemsFromPayload($newOrder, $rawOrder, $account);
                } catch (\Throwable $e) {
                    Log::channel('marketplace')->warning('[ImportMarketplaceAccountDataJob][ML] upsertItems falhou', [
                        'order_id' => $mlOrderId,
                        'error'    => $e->getMessage(),
                    ]);
                }

                try {
                    app(\App\Services\Orders\DraftOrderPromoter::class)->promote($newOrder, 'import_ml');
                } catch (\Throwable $e) {
                    Log::channel('marketplace')->warning('[ImportMarketplaceAccountDataJob][ML] promocao falhou', [
                        'order_id' => $mlOrderId,
                        'error'    => $e->getMessage(),
                    ]);
                }

                $created++;
            } catch (\Throwable $e) {
                Log::channel('marketplace')->warning('[ImportMarketplaceAccountDataJob][ML] Falha ao criar pedido', [
                    'order_id' => $mlOrderId,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        Log::channel('marketplace')->info('[ImportMarketplaceAccountDataJob][ML] Pedidos importados 7d', [
            'account_id' => $account->id,
            'total_api'  => count($orders),
            'created'    => $created,
            'skipped'    => $skipped,
        ]);
    }

    // =========================================================================
    // Helpers privados
    // =========================================================================

    private function callShopeeApi(MarketplaceAccount $account, string $endpoint, array $params, string $method, ShopeeService $shopee): array
    {
        try {
            $ref = new \ReflectionMethod($shopee, 'callApi');
            $ref->setAccessible(true);
            return $ref->invoke($shopee, $endpoint, $params, $method);
        } catch (\Throwable $e) {
            Log::channel('marketplace')->error('[ImportMarketplaceAccountDataJob][Shopee] callApi error', [
                'endpoint' => $endpoint,
                'error'    => $e->getMessage(),
            ]);
            return [];
        }
    }

    private function resolveShopeeToken(MarketplaceAccount $account): ?string
    {
        if (! $account->access_token) {
            return null;
        }

        try {
            return decrypt($account->access_token);
        } catch (\Throwable $e) {
            return $account->access_token;
        }
    }

    private function resolveMLToken(MarketplaceAccount $account, MercadoLivreService $ml): ?string
    {
        try {
            $ref = new \ReflectionMethod($ml, 'getValidAccessToken');
            $ref->setAccessible(true);
            return $ref->invoke($ml, $account);
        } catch (\Throwable $e) {
            Log::channel('marketplace')->warning('[ImportMarketplaceAccountDataJob][ML] resolveMLToken falhou', [
                'account_id' => $account->id,
                'error'      => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function mapShopeeStatus(string $status): string
    {
        $map = [
            'unpaid'        => 'pending',
            'ready_to_ship' => 'processing',
            'processed'     => 'processing',
            'shipped'       => 'shipped',
            'in_cancel'     => 'cancelled',
            'cancelled'     => 'cancelled',
            'to_return'     => 'returned',
            'completed'     => 'completed',
        ];
        return $map[$status] ?? 'pending';
    }

    private function mapMLStatus(string $status): string
    {
        $map = [
            'confirmed'          => 'pending',
            'payment_in_process' => 'pending',
            'payment_required'   => 'pending',
            'paid'               => 'paid',
            'cancelled'          => 'cancelled',
        ];
        return $map[$status] ?? 'pending';
    }
}
