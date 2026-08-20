<?php

namespace App\Services;

use App\Jobs\FetchShippingLabelJob;
use App\Models\MarketplaceAccount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Integrations\Marketplaces\ShopeeService;
use App\Services\KitExplosionService;
use App\Services\MercadoLivreService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WebhookOrderService — NOV-150-B
 *
 * Pipeline zero-latencia: quando um webhook ML/Shopee chega em api.hubai.io,
 * este service cria o Order DIRETAMENTE no novo sistema sem depender do
 * ImportLegacyOrdersJob (que tem latencia de 5min-horas).
 *
 * Responsabilidades:
 *  1. Receber payload normalizado do webhook controller
 *  2. Encontrar a MarketplaceAccount correspondente (sem TenantSupplierScope)
 *  3. Buscar detalhes do pedido na API do marketplace (ML ou Shopee)
 *  4. Criar ou atualizar Order + OrderItems de forma idempotente (updateOrCreate)
 *  5. Disparar FetchShippingLabelJob imediatamente apos criacao (zero-latencia)
 *  6. Disparar RelayOrderToLegacyJob assincrono para backward compatibility
 *
 * Idempotencia: webhook pode chegar duplicado (retry do marketplace).
 * O updateOrCreate por marketplace_order_id garante que o Order nao e duplicado.
 * FetchShippingLabel SO dispara em criacao nova — updates de status nao redisparam.
 *
 * TenantSupplierScope: este service roda fora de contexto HTTP autenticado.
 * Usa withoutGlobalScopes() ao buscar MarketplaceAccount e Order para evitar
 * filtro de tenant que nao existe nesse contexto.
 */
class WebhookOrderService
{
    // Status ML que representam pagamento confirmado — dispara FetchLabel
    private const ML_PAID_STATUSES = ['paid'];

    private const SHOPEE_PAID_STATUSES = [
        'READY_TO_SHIP',
        'PROCESSED',
        'SHIPPED',
        'COMPLETED',
    ];

    // Mapa status ML -> canonical interno
    private const ML_STATUS_MAP = [
        'payment_required'   => 'pending_payment',
        'payment_in_process' => 'pending_payment',
        'paid'               => 'paid',
        'cancelled'          => 'cancelled',
        'invalid'            => 'cancelled',
    ];

    // Mapa status Shopee -> canonical interno
    private const SHOPEE_STATUS_MAP = [
        'UNPAID'        => 'pending_payment',
        'READY_TO_SHIP' => 'awaiting_shipment',
        'PROCESSED'     => 'processing',
        'SHIPPED'       => 'shipped',
        'COMPLETED'     => 'delivered',
        'IN_CANCEL'     => 'cancellation_requested',
        'CANCELLED'     => 'cancelled',
    ];

    public function __construct(
        private readonly MercadoLivreService $mlService,
        private readonly ShopeeService       $shopeeService,
        private readonly KitExplosionService $kitExplosion,
    ) {}

    // =========================================================================
    // ENTRYPOINT UNIFICADO
    // =========================================================================

    /**
     * Processa um evento de webhook para criacao/atualizacao de pedido.
     *
     * @param  string $marketplace  'mercadolivre' | 'shopee'
     * @param  array  $payload      Payload bruto do webhook (fields variam por plataforma)
     * @param  mixed  $accountKey   ml_user_id (ML) ou shop_id (Shopee)
     * @return Order|null           O Order criado/atualizado, ou null se nao aplicavel
     */
    public function processWebhookOrder(string $marketplace, array $payload, mixed $accountKey): ?Order
    {
        try {
            return match ($marketplace) {
                'mercadolivre' => $this->processMercadoLivre($payload, $accountKey),
                'shopee'       => $this->processShopee($payload, $accountKey),
                default        => null,
            };
        } catch (\Throwable $e) {
            Log::error('[WebhookOrderService] Erro ao processar pedido', [
                'marketplace' => $marketplace,
                'account_key' => $accountKey,
                'error'       => $e->getMessage(),
                'trace'       => mb_substr($e->getTraceAsString(), 0, 500),
            ]);
            return null;
        }
    }

    // =========================================================================
    // MERCADO LIVRE
    // =========================================================================

    /**
     * Processa evento de pedido do Mercado Livre.
     *
     * @param  array       $payload  Payload do webhook (topic, resource, user_id, ...)
     * @param  string|null $mlUserId ml_user_id do vendedor
     */
    private function processMercadoLivre(array $payload, ?string $mlUserId): ?Order
    {
        if (! $mlUserId) {
            Log::warning('[WebhookOrderService][ML] ml_user_id ausente no payload');
            return null;
        }

        // Extrair ml_order_id do resource (/orders/1234567890)
        $resource  = $payload['resource'] ?? '';
        $parts     = explode('/', trim($resource, '/'));
        $mlOrderId = end($parts) ?: null;

        if (! $mlOrderId || ! is_numeric($mlOrderId)) {
            Log::info('[WebhookOrderService][ML] resource nao e de pedido, ignorando', [
                'resource' => $resource,
            ]);
            return null;
        }

        // Encontrar a MarketplaceAccount — sem TenantSupplierScope (contexto job)
        $account = MarketplaceAccount::withoutGlobalScopes()
            ->where('ml_user_id', (string) $mlUserId)
            ->whereIn('platform', ['mercadolivre', 'mercado_livre'])
            ->first();

        if (! $account) {
            Log::info('[WebhookOrderService][ML] Conta nao encontrada no NovoHubAI', [
                'ml_user_id' => $mlUserId,
            ]);
            return null;
        }

        // MUL-313: mesma regra do MUL-212 F2, agora tambem no webhook direto.
        // A WL nunca cria pedido de conta gerida pelo hub - o hub puxa e entrega
        // via fanout. Sem isto, um push da Shopee/ML na WL recria o pedido local
        // sem vinculo com o hub (foi assim que nasceram 26 pedidos entre 01/07 e 10/07).
        if (app(\App\Services\InstallationConfig::class)->skipsCentralAccountPull((bool) $account->centrally_managed)) {
            Log::info('[MUL-313][ML] webhook direto ignorado: conta gerida pelo hub', [
                'account_id' => $account->id,
            ]);

            return null;
        }

        // Buscar detalhes do pedido na API ML
        try {
            $token    = $this->mlService->getValidToken($account);
            $response = Http::withToken($token)
                ->timeout(10)
                ->get("https://api.mercadolibre.com/orders/{$mlOrderId}");

            if ($response->failed()) {
                Log::error('[WebhookOrderService][ML] Falha ao buscar pedido na API ML', [
                    'ml_order_id' => $mlOrderId,
                    'status'      => $response->status(),
                ]);
                return null;
            }

            $mlOrder = $response->json();
        } catch (\Throwable $e) {
            Log::error('[WebhookOrderService][ML] Excecao ao buscar pedido ML', [
                'ml_order_id' => $mlOrderId,
                'error'       => $e->getMessage(),
            ]);
            return null;
        }

        // Apenas criar Order para pedidos com status reconhecido
        $mlStatus    = $mlOrder['status'] ?? '';
        $localStatus = self::ML_STATUS_MAP[$mlStatus] ?? 'pending';
        $isPaid      = in_array($mlStatus, self::ML_PAID_STATUSES, true);

        $buyer = $mlOrder['buyer'] ?? [];
        $ship  = $mlOrder['shipping'] ?? [];

        // Verificar se e criacao nova ANTES do updateOrCreate
        $existing = Order::withoutGlobalScopes()
            ->where(function ($q) use ($mlOrderId) {
                $q->where('marketplace_order_id', (string) $mlOrderId)
                  ->orWhere('external_order_id', (string) $mlOrderId);
            })
            ->exists();

        $isNew = ! $existing;

        // FOR-131: modo sombra — so registra o que o ponto unico decidiria. Nao altera nada.
        $this->sombraEntradaDePedido(
            'mercadolivre',
            $account,
            collect($mlOrder['order_items'] ?? [])->map(fn ($it) => [
                'sku'     => $it['item']['seller_sku'] ?? $it['item']['seller_custom_field'] ?? null,
                'anuncio' => $it['item']['id'] ?? null,
            ])->all(),
            self::temVinculoDeFornecedor($mlOrder['order_items'] ?? [], $account),
            (string) $mlOrderId
        );

        // FOR-103: sem vinculo de fornecedor em nenhum item, o pedido nao e nosso.
        // So descarta CRIACAO — pedido que ja existe continua recebendo update,
        // senao o historico congela no meio do caminho.
        if ($isNew && config('imports.require_supplier_link')
            && ! self::temVinculoDeFornecedor($mlOrder['order_items'] ?? [], $account)) {
            Log::info('[FOR-103] pedido nao importado: nenhum item vinculado a fornecedor', [
                'ml_order_id' => $mlOrderId,
                'account_id'  => $account->id,
                'client_id'   => $account->client_id,
            ]);
            return null;
        }

        // FOR-107: o pedido do Mercado Livre nascia SEM tenant. Sem tenant, o fan-out
        // so o entregava ao WL do fornecedor (rota do supplier) e nunca ao WL onde a
        // loja foi autenticada — o seller do Fornecefy nao via o proprio pedido.
        // Medido em 12/08/2026: 1.563 pedidos ML sem tenant, 260 so naquele dia.
        // Shopee (MUL-207) e Bling (HUB-113) ja resolviam; este caminho ficou de fora.
        // resolveTenantSlug decide primeiro pelo service da conta, que e a origem da
        // autenticacao: loja conectada no Fornecefy gera pedido do tenant fornecefy.
        $tenantSlug = $this->resolveTenantSlug($account->supplier_id, $account);

        // FOR-133: a busca de existencia (acima) considera marketplace_order_id OU
        // external_order_id, mas a gravacao procurava SO por marketplace_order_id. O
        // ProcessMLOrderJob cria a linha preenchendo external_order_id e deixando
        // marketplace_order_id NULL -- entao a chave nao casava e nascia um SEGUNDO pedido
        // para a mesma venda. Medido em 14/08: 56 duplicatas de ML no Fornecefy em 30 dias
        // e 82 pedidos com so uma das colunas, cada um uma duplicata esperando acontecer.
        //
        // Agora a gravacao usa a MESMA chave da busca: adota a linha existente e a completa
        // (conta, seller, tenant) em vez de duplicar.
        $atributosDoPedido = [
                'client_id'              => $account->client_id,
                'supplier_id'            => $account->supplier_id,
                'marketplace_account_id' => $account->id,
                'source'                 => 'mercadolivre',
                'tenant_slug'            => $tenantSlug,
                'origin_tenant_slug'     => $tenantSlug,
                'status'                 => $isPaid ? 'paid' : $localStatus,
                'canonical_status'       => $localStatus,
                'external_order_id'      => (string) $mlOrderId,
                'buyer_id'               => (string) ($buyer['id'] ?? ''),
                'buyer_nickname'         => $buyer['nickname'] ?? '',
                'buyer_username'         => $buyer['nickname'] ?? '',
                'customer_name'          => trim(($buyer['first_name'] ?? '') . ' ' . ($buyer['last_name'] ?? '')),
                'subtotal'               => $mlOrder['total_amount'] ?? 0,
                'total'                  => $mlOrder['total_amount'] ?? 0,
                'currency'               => $mlOrder['currency_id'] ?? 'BRL',
                'external_shipping_id'   => ! empty($ship['id']) ? (string) $ship['id'] : null,
                'paid_at'                => $isPaid ? now() : null,
                // MUL-237: date_created do ML (ISO8601) -> marketplace_created_at
                // FOR-119: era ->utc(), e o resto do sistema fala America/Sao_Paulo.
                // Resultado: a data do pedido ficava 3h a frente de tudo, e o painel
                // mostrava paid_at ANTES de marketplace_created_at — pedido pago antes
                // de existir. Medido em 13/08/2026 no jtdrop: 288 de 356 pedidos de ML
                // com exatamente 3h de diferenca, 304 com criacao depois do pagamento.
                // Mesma correcao que a MUL-329 ja tinha feito no caminho da Shopee.
                "marketplace_created_at" => ! empty($mlOrder["date_created"])
                    ? \Carbon\Carbon::parse($mlOrder["date_created"])->setTimezone(config("app.timezone"))
                    : null,
                'raw_payload'            => $mlOrder,
            ];
        $atributosDoPedido['marketplace_order_id'] = (string) $mlOrderId;

        $order = Order::withoutGlobalScopes()
            ->where(function ($q) use ($mlOrderId) {
                $q->where('marketplace_order_id', (string) $mlOrderId)
                  ->orWhere('external_order_id', (string) $mlOrderId);
            })
            ->first();

        if ($order) {
            $order->fill($atributosDoPedido)->save();
        } else {
            $order = Order::withoutGlobalScopes()->create($atributosDoPedido);
        }

        // Sincronizar itens do pedido
        $this->syncMLOrderItems($order, $mlOrder['order_items'] ?? [], $account);

        // Atualizar supplier_total com soma dos custos
        $supplierTotal = $order->items()->sum('supplier_total_cost');
        $order->update(['supplier_total' => $supplierTotal]);

        // MUL-339: avisar o WL SO DEPOIS de o pedido estar completo. Antes o dispatch saia logo
        // apos o cabecalho, e o WL podia ser avisado de um pedido sem itens e sem supplier_total.
        \App\Jobs\DispatchTenantOrderWebhookJob::dispatch(
            $order->id,
            $order->wasRecentlyCreated ? 'order.created' : 'order.updated'
        );

        Log::info('[WebhookOrderService][ML] Order ' . ($isNew ? 'criado' : 'atualizado') . ' via webhook', [
            'order_id'    => $order->id,
            'ml_order_id' => $mlOrderId,
            'status'      => $localStatus,
            'is_new'      => $isNew,
        ]);

        // Disparar jobs somente em criacao nova paga
        if ($isNew && $isPaid) {
            $this->dispatchPostOrderJobs($order, 'mercadolivre', $account);
        }

        return $order;
    }

    /**
     * Sincroniza os itens de um pedido ML com OrderItem.
     */
    /**
     * MUL-216: custom_sku "shopee-<id>"/"ml-<id>" e placeholder gerado no import
     * de anuncio sem SKU — nunca deve vencer o SKU real da variacao no item.
     */
    private static function realCustomSku(?\App\Models\ClientProduct $clientProduct): ?string
    {
        $sku = trim((string) ($clientProduct?->custom_sku ?? ''));
        if ($sku === '' || preg_match('/^(shopee-\d+|ml-MLB\d+)$/i', $sku)) {
            return null;
        }
        return $sku;
    }

    /**
     * MUL-273: SKU do pedido = fonte de verdade. Resolve o Product pelo SKU
     * vindo do payload do marketplace (preferencia: supplier da conta, depois
     * price>0). O vinculo do anuncio (client_products) vira fallback — anuncio
     * pode estar desatualizado/errado (caso 90451/MUL-272).
     */
    public static function productFromOrderSku(?string $sku, MarketplaceAccount $account): ?\App\Models\Product
    {
        $sku = trim((string) $sku);
        if ($sku === '') {
            return null;
        }
        $cands = \App\Models\Product::where('sku', $sku)->get();
        $direto = $cands->firstWhere('supplier_id', $account->supplier_id)
            ?? $cands->first(fn ($p) => (float) $p->price > 0)
            ?? $cands->first();

        if ($direto) {
            return $direto;
        }

        // JT-022c: formato novo e REVERSIVEL — o sufixo e o id do anuncio.
        // Decodifica com verificacao de ida-e-volta e chega ao pai sem depender
        // de match textual. E a via que faltava: anuncio novo sem esta rota so
        // resolvia se o custom_sku batesse por texto.
        $anuncio = \App\Support\SkuDoAnuncio::anuncioDoSku($sku);
        if ($anuncio?->product) {
            return $anuncio->product;
        }

        // FOR-111: mapa SKU FILHO -> SKU PAI.
        //
        // A partir de 13/08/2026 o anuncio passa a levar SEU proprio SKU ao marketplace
        // (SkuDoAnuncio: {sku_do_catalogo}-{id em base32}). Entao o pedido volta com o SKU
        // do ANUNCIO — 'D53-CINTASWEAT-318N' —, que nao existe em `products`; la mora o
        // SKU do CATALOGO, 'D53-CINTASWEAT'. Sem este trecho o SKU do pedido, que e o
        // identificador mais confiavel que temos, seria ignorado e a resolucao dependeria
        // so do vinculo do anuncio (que falta em 82% dos casos medidos na FOR-110).
        //
        // Primeiro dentro da conta, que e o casamento preciso; depois global, que so e
        // seguro porque o formato novo e unico por construcao (id da linha em base32).
        $cp = \App\Models\ClientProduct::where('custom_sku', $sku)
                ->where('marketplace_account_id', $account->id)
                ->whereNotNull('product_id')
                ->first()
            ?? \App\Models\ClientProduct::where('custom_sku', $sku)
                ->whereNotNull('product_id')
                ->first();

        if ($cp?->product) {
            return $cp->product;
        }

        // JT-022e (SO NO HUB): o anuncio pode viver apenas no banco do WL de origem —
        // caso 156599: MLB7401384910 vinculado ao produto SO no fornecefy; o hub criava
        // o pedido cru e o fanout espalhava o erro para todos. Consulta o anuncio nas
        // conexoes de WL (external_listing_id quando o "sku" e o id do anuncio ML, ou
        // custom_sku) e volta ao catalogo LOCAL pelo SKU do pai — ids de produto NAO
        // atravessam bancos, SKU sim.
        return self::produtoViaAnuncioDeWl($sku);
    }

    /** JT-022e: resolve via client_products dos WLs (cross-DB). Hub apenas. */
    public static function produtoViaAnuncioDeWl(?string $sku): ?\App\Models\Product
    {
        $sku = trim((string) $sku);
        if ($sku === '' || config('app.tenant') !== 'hubai') {
            return null;
        }
        $ehListingId = (bool) preg_match('/^(MLB|MLA|MLM)\d+$/i', $sku);

        foreach (['fornecefy', 'multdrop'] as $conn) {
            try {
                $q = \Illuminate\Support\Facades\DB::connection($conn)
                    ->table('client_products as cp')
                    ->join('products as p', 'p.id', '=', 'cp.product_id')
                    ->whereNotNull('cp.product_id');
                $q = $ehListingId
                    ? $q->where('cp.external_listing_id', $sku)
                    : $q->where('cp.custom_sku', $sku);
                $paiSku = $q->value('p.sku');
            } catch (\Throwable) {
                continue; // conexao indisponivel nao pode derrubar a entrada de pedido
            }
            if (! $paiSku) {
                continue;
            }
            $local = \App\Models\Product::where('sku', $paiSku)->get();
            $p = $local->first(fn ($x) => (float) $x->price > 0) ?? $local->first();
            if ($p) {
                return $p;
            }
        }

        return null;
    }

    /**
     * FOR-103: o pedido tem ao menos um item que resolve para produto de fornecedor?
     *
     * Espelha a mesma cascata do syncMLOrderItems (SKU do pedido -> vinculo do
     * anuncio), de proposito: se mudar la, muda aqui. Nao usa o titulo como
     * ultimo recurso — casar por nome e chute, e chute nao pode decidir descarte.
     */
    public static function temVinculoDeFornecedor(array $mlItems, MarketplaceAccount $account): bool
    {
        foreach ($mlItems as $item) {
            $mlItemId = $item['item']['id'] ?? null;
            $sku      = $item['item']['seller_sku'] ?? $item['item']['seller_custom_field'] ?? null;

            $skuReal = $sku && $sku !== $mlItemId && ! str_starts_with((string) $sku, 'ml-');
            if ($skuReal && self::productFromOrderSku($sku, $account)) {
                return true;
            }

            if ($mlItemId) {
                $cp = \App\Models\ClientProduct::where('external_listing_id', $mlItemId)
                    ->where('marketplace_account_id', $account->id)
                    ->first();
                if ($cp?->product_id) {
                    return true;
                }
            }
        }

        return false;
    }

    private function syncMLOrderItems(Order $order, array $mlItems, MarketplaceAccount $account): void
    {
        foreach ($mlItems as $item) {
            $mlItemId    = $item['item']['id'] ?? null;
            // FOR-135: o ML manda variation_id em parte dos pedidos e a coluna ficava
            // sempre nula. Sem ela, duas variacoes do mesmo anuncio no mesmo pedido colidem
            // na chave e uma some. Os dois caminhos da Shopee ja gravam assim desde a
            // MUL-243; o ML nunca recebeu a correcao.
            $mlVariationId = $item['item']['variation_id'] ?? null;
            $mlVariationId = ($mlVariationId === null || $mlVariationId === '') ? null : (string) $mlVariationId;
            $itemTitle   = $item['item']['title'] ?? 'Produto';
            $qty         = $item['quantity'] ?? 1;
            $price       = $item['unit_price'] ?? 0;
            $sku         = $item['item']['seller_sku']
                        ?? $item['item']['seller_custom_field']
                        ?? $mlItemId
                        ?? 'N/A';
            $saleFee     = $item['sale_fee'] ?? null;
            $listingType = $item['listing_type_id'] ?? null;

            $clientProduct = $mlItemId
                ? \App\Models\ClientProduct::where('external_listing_id', $mlItemId)
                    ->where('marketplace_account_id', $account->id)
                    ->first()
                : null;

            // MUL-273: SKU do pedido = fonte de verdade; vinculo do anuncio e
            // fallback. FOR-044: name como ultimo recurso.
            $skuReal = $sku && $sku !== $mlItemId && ! str_starts_with((string) $sku, 'ml-');
            $product = ($skuReal ? self::productFromOrderSku($sku, $account) : null)
                ?? $clientProduct?->product;
            if (! $product && $itemTitle && $itemTitle !== 'Produto') {
                $product = \App\Models\Product::where('name', $itemTitle)
                    ->where('supplier_id', $account->supplier_id)
                    ->where('sku', 'NOT LIKE', 'ml-%')
                    ->first();
            }
            $coverImg = null;
            if ($product) {
                $cover = \App\Models\ProductMedia::where('product_id', $product->id)
                    ->orderByDesc('is_cover')->orderBy('position')->first();
                $coverImg = $cover?->url ?: $cover?->original_url;
            }

            self::upsertPreservandoTrocaManual($order, 
                [
                    'order_id'              => $order->id,
                    'external_item_id'      => $mlItemId,
                    'external_variation_id' => $mlVariationId,
                ],
                [
                    'client_product_id'   => $clientProduct?->id,
                    'product_id'          => $product?->id,
                    // JT-022c (regra do Ruan): pedido processado pelo SKU PAI; filho em variation_sku
                    'sku'                 => $product?->sku ?? ($skuReal ? $sku : null) ?? self::realCustomSku($clientProduct) ?? $sku,
                    'variation_sku'       => ($product && $skuReal && $sku !== $product->sku) ? $sku : null,
                    'name'                => $itemTitle,
                    'quantity'            => $qty,
                    'unit_price'          => $price,
                    'total'               => $qty * $price,
                    // MUL-318 P2: custo so de produto confiavel (Product::custoConfiavel)
                    'supplier_unit_cost'  => $mlCost = $product?->custoConfiavel(),
                    'supplier_total_cost' => $mlCost !== null ? round($mlCost * $qty, 2) : null,
                    'sale_fee'            => $saleFee,
                    'listing_type_id'     => $listingType,
                    'product_image'       => $coverImg,
                ]
            );
        }
    }

    // =========================================================================
    // SHOPEE
    // =========================================================================

    /**
     * Processa evento de pedido da Shopee (code=3 ORDER_STATUS_UPDATE ou similar).
     *
     * @param  array $payload  Payload do webhook Shopee (code, shop_id, data)
     * @param  int   $shopId   ID da loja Shopee
     */
    private function processShopee(array $payload, int $shopId): ?Order
    {
        $data    = $payload['data'] ?? $payload;
        $orderSn = $data['ordersn'] ?? $data['order_sn'] ?? '';

        if (! $orderSn) {
            Log::info('[WebhookOrderService][Shopee] order_sn ausente no payload, ignorando');
            return null;
        }

        // Encontrar a MarketplaceAccount — sem TenantSupplierScope
        $account = MarketplaceAccount::withoutGlobalScopes()
            ->where('shop_id', (string) $shopId)
            ->where('platform', 'shopee')
            ->where('status', 'active')
            ->first();

        if (! $account) {
            Log::info('[WebhookOrderService][Shopee] Conta nao encontrada ou inativa', [
                'shop_id' => $shopId,
            ]);
            return null;
        }

        // MUL-313: mesma regra do MUL-212 F2, agora tambem no webhook direto.
        // A WL nunca cria pedido de conta gerida pelo hub - o hub puxa e entrega
        // via fanout. Sem isto, um push da Shopee/ML na WL recria o pedido local
        // sem vinculo com o hub (foi assim que nasceram 26 pedidos entre 01/07 e 10/07).
        if (app(\App\Services\InstallationConfig::class)->skipsCentralAccountPull((bool) $account->centrally_managed)) {
            Log::info('[MUL-313][Shopee] webhook direto ignorado: conta gerida pelo hub', [
                'account_id' => $account->id,
            ]);

            return null;
        }

        // Buscar detalhes do pedido na API Shopee
        try {
            $accessToken = $this->shopeeService->getValidAccessToken($account);
            $shopIdInt   = $this->shopeeService->getShopId($account);

            if (! $accessToken || ! $shopIdInt) {
                Log::warning('[WebhookOrderService][Shopee] Token ou shop_id indisponivel', [
                    'account_id' => $account->id,
                ]);
                return null;
            }

            $detailResponse = $this->shopeeService->getOrderDetail($shopIdInt, $accessToken, [$orderSn]);
            $orderList      = $detailResponse['response']['order_list'] ?? [];
            $shopeeOrder    = $orderList[0] ?? null;

            if (! $shopeeOrder) {
                Log::warning('[WebhookOrderService][Shopee] Detalhe do pedido nao retornado pela API', [
                    'order_sn' => $orderSn,
                ]);
                return null;
            }
        } catch (\Throwable $e) {
            Log::error('[WebhookOrderService][Shopee] Excecao ao buscar detalhe do pedido', [
                'order_sn' => $orderSn,
                'error'    => $e->getMessage(),
            ]);
            return null;
        }

        $shopeeStatus  = $shopeeOrder['order_status'] ?? '';
        $canonicalStat = self::SHOPEE_STATUS_MAP[$shopeeStatus] ?? strtolower($shopeeStatus);
        $isPaid        = in_array($shopeeStatus, self::SHOPEE_PAID_STATUSES, true);
        $totalAmount   = $shopeeOrder['total_amount'] ?? 0;

        $buyerUserId   = $shopeeOrder['buyer_user_id'] ?? '';
        $buyerUsername = $shopeeOrder['buyer_username'] ?? '';
        $recipient     = $shopeeOrder['recipient_address'] ?? [];

        // Verificar se e criacao nova ANTES do updateOrCreate
        $existingOrder = Order::withoutGlobalScopes()
            ->where('marketplace_order_id', $orderSn)
            ->first();

        $isNew = ! $existingOrder;

        // MUL-181: Shopee mascara o nome em pedido concluido — preferir buyer_username real
        // e nunca rebaixar um nome real ja gravado pra versao mascarada.
        $customerName = trim((string) ($recipient['name'] ?? ''));
        if ($customerName === '' || str_contains($customerName, '*')) {
            $customerName = $buyerUsername !== '' ? $buyerUsername : ($customerName !== '' ? $customerName : null);
        }
        if ($existingOrder && $existingOrder->customer_name && ! str_contains($existingOrder->customer_name, '*')) {
            $customerName = $existingOrder->customer_name;
        }

        // Idempotencia por marketplace_order_id (order_sn)
        // MUL-206: Pre-calcular items ANTES de criar Order — evita OrderObserver
        // safety_net disparar com supplier_total=NULL. Padrao: computar tudo em
        // memoria, criar Order com supplier_total + tenant_slug ja preenchidos,
        // depois persistir OrderItems.
        $itemsData = $this->buildShopeeItemsData($shopeeOrder['item_list'] ?? [], $account);

        // FOR-103 (Shopee) — a trava existia so no caminho do Mercado Livre. Medido em
        // 13/08/2026, 7 dias de pedidos de contas fornecefy: ML 430 pedidos com 243 sem
        // vinculo, Shopee 28 com 18 sem vinculo. Ligar a regra cobrindo so o ML deixaria
        // ~18 pedidos por semana entrando por aqui.
        //
        // Aqui o teste e direto: buildShopeeItemsData ja resolveu product_id de cada item.
        // FOR-131: modo sombra — ver comentario no caminho do ML.
        $this->sombraEntradaDePedido(
            'shopee',
            $account,
            collect($itemsData)->map(fn ($iv) => [
                'sku'     => $iv['sku'] ?? null,
                'anuncio' => $iv['_external_item_id'] ?? null,
            ])->all(),
            collect($itemsData)->contains(fn ($iv) => ! empty($iv['product_id'])),
            (string) $orderSn
        );

        // Sem NENHUM item com produto, o pedido nao e do catalogo de fornecedor nenhum.
        // So descarta CRIACAO — pedido que ja existe continua recebendo update, senao o
        // historico congela no meio do caminho.
        if ($isNew && config('imports.require_supplier_link')
            && ! collect($itemsData)->contains(fn ($iv) => ! empty($iv['product_id']))) {
            Log::info('[FOR-103][Shopee] pedido nao importado: nenhum item vinculado a fornecedor', [
                'order_sn'   => $orderSn,
                'account_id' => $account->id,
                'client_id'  => $account->client_id,
            ]);

            return null;
        }
        $supplierTotal = 0.0;
        foreach ($itemsData as $iv) {
            $supplierTotal += (float) ($iv['supplier_total_cost'] ?? 0);
        }

        // MUL-207: origin_tenant_slug — webhook roda anonimo, TenantSupplierScope nao
        // aplica; setar manualmente via supplier_id do account. Espelha BlingOrderSync.
        // MUL-315: a conta manda; se ela nao souber, a mercadoria sabe.
        $supplierId = $account->supplier_id ?: $this->supplierFromItemsData($itemsData);

        if (! $account->supplier_id) {
            Log::warning($supplierId
                ? '[MUL-315] conta sem supplier_id — derivado do produto do item'
                : '[MUL-315] conta sem supplier_id e o produto nao resolve — pedido NAO vai espelhar', [
                'account_id'  => $account->id,
                'shop_id'     => $shopId,
                'order_sn'    => $orderSn,
                'supplier_id' => $supplierId,
            ]);
        }

        // MUL-207: origin_tenant_slug — webhook roda anonimo, TenantSupplierScope nao
        // aplica; setar manualmente. MUL-315: usa o supplier ja resolvido.
        $tenantSlug = $this->resolveTenantSlug($supplierId, $account);

        // MUL-208: popular customer_address (string completa) e external_pack_id
        $customerAddress = $this->buildAddressString($recipient);
        $externalPackId  = $shopeeOrder['package_list'][0]['package_number'] ?? null;

        $order = Order::withoutGlobalScopes()->updateOrCreate(
            ['marketplace_order_id' => $orderSn],
            [
                'client_id'              => $account->client_id,
                'supplier_id'            => $supplierId,
                'marketplace_account_id' => $account->id,
                'shop_id'                => $shopId,
                'source'                 => 'shopee',
                'status'                 => $isPaid ? 'paid' : $canonicalStat,
                'canonical_status'       => $canonicalStat,
                'external_order_id'      => $orderSn,
                'external_pack_id'       => $externalPackId,
                'buyer_id'               => (string) $buyerUserId,
                'buyer_username'         => $buyerUsername,
                'customer_name'          => $customerName,
                'customer_phone'         => $recipient['phone'] ?? null,
                'customer_address'       => $customerAddress,
                'subtotal'               => $totalAmount,
                'total'                  => $totalAmount,
                'supplier_total'         => $supplierTotal > 0 ? round($supplierTotal, 2) : null,
                'tenant_slug'            => $tenantSlug,
                'origin_tenant_slug'     => $tenantSlug,
                'currency'               => 'BRL',
                'paid_at'                => $isPaid ? now() : null,
                // MUL-329: create_time da Shopee (epoch) -> horario LOCAL da aplicacao.
                // Estava com ->utc(); Bling, painel e seller falam America/Sao_Paulo,
                // entao venda depois das 21h aparecia no dia seguinte. Conferido contra
                // a API da Shopee em 04/08/2026: 10 divergencias de 10 eram este defeito.
                "marketplace_created_at" => ! empty($shopeeOrder["create_time"])
                    ? \Carbon\Carbon::createFromTimestamp((int) $shopeeOrder["create_time"])->setTimezone(config('app.timezone'))
                    : null,
                'raw_payload'            => $shopeeOrder,
            ]
        );

        // MUL-352: snapshot COMPLETO do pedido a cada evento.
        // orders.raw_payload guarda so o ULTIMO estado — sobrescreve a cada push.
        // Sem o historico, corrigir dado antigo exige voltar na API do marketplace
        // (3.179 chamadas no backfill da MUL-343). Aqui fica um registro por evento.
        // Nunca pode derrubar o processamento: falha e engolida.
        try {
            \DB::table('order_events')->insert([
                'order_id'    => $order->id,
                'event_type'  => 'snapshot_evento',
                'description' => 'shopee: ' . ($shopeeOrder['order_status'] ?? '?'),
                'metadata'    => json_encode($shopeeOrder, JSON_UNESCAPED_UNICODE),
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('[MUL-352] falha ao gravar snapshot do evento', [
                'order_id' => $order->id, 'erro' => $e->getMessage(),
            ]);
        }

        // Persistir itens pre-computados
        $this->persistShopeeOrderItems($order, $itemsData);

        Log::info('[WebhookOrderService][Shopee] Order ' . ($isNew ? 'criado' : 'atualizado') . ' via webhook', [
            'order_id' => $order->id,
            'order_sn' => $orderSn,
            'status'   => $shopeeStatus,
            'is_new'   => $isNew,
        ]);

        // MUL-147-B: Explodir itens de kit do lojista em componentes para o fornecedor
        try {
            $this->kitExplosion->explodeOrder($order);
        } catch (\Throwable $e) {
            Log::warning('[WebhookOrderService][Shopee] Falha na explosao de kit (nao critico)', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
        }

        // MUL-339: avisar o WL SO DEPOIS de itens gravados E kit explodido. Antes o dispatch saia
        // logo apos o cabecalho — o WL podia ser avisado de um pedido sem itens e sem componentes.
        \App\Jobs\DispatchTenantOrderWebhookJob::dispatch(
            $order->id,
            $order->wasRecentlyCreated ? "order.created" : "order.updated"
        );

        // Disparar jobs somente em criacao nova paga
        if ($isNew && $isPaid) {
            $this->dispatchPostOrderJobs($order, 'shopee', $account);
        }

        return $order;
    }

    /**
     * MUL-206: constroi array de itens (sem persistir) para poder somar
     * supplier_total ANTES de criar o Order (evita OrderObserver safety_net).
     *
     * MUL-205: padrao de mapping do legado — usa trim()!='' no lugar de ??
     * porque payload Shopee traz campos vazios ("") em vez de null (variacao
     * ausente vem model_sku="" e model_discounted_price=0, nao null).
     */
    private function buildShopeeItemsData(array $items, MarketplaceAccount $account): array
    {
        $result = [];
        foreach ($items as $item) {
            $itemId    = $item['item_id'] ?? null;
            $modelId   = $item['model_id'] ?? null;
            $itemName  = $item['item_name'] ?? '';
            $modelName = $item['model_name'] ?? '';
            $qty       = (int) ($item['model_quantity_purchased'] ?? $item['quantity'] ?? 1);

            // Preco: padrao legado — model_discounted_price se truthy, senao model_original_price
            $price = 0.0;
            if (!empty($item['model_discounted_price'])) {
                $price = (float) $item['model_discounted_price'];
            } elseif (!empty($item['model_original_price'])) {
                $price = (float) $item['model_original_price'];
            }

            // SKU: item_sku eh o principal; model_sku so sobrescreve quando != ''
            $itemSku  = trim((string) ($item['item_sku'] ?? ''));
            $modelSku = trim((string) ($item['model_sku'] ?? ''));
            if ($modelSku !== '') {
                $sku = $modelSku;
            } elseif ($itemSku !== '') {
                $sku = $itemSku;
            } else {
                $sku = (string) $itemId;
            }

            // Nome: item_name primario, model_name como sufixo se preenchido
            $itemName  = trim($itemName);
            $modelName = trim($modelName);
            $displayName = $itemName !== '' ? $itemName : 'Produto';
            if ($modelName !== '' && $modelName !== $displayName) {
                $displayName = "{$displayName} — {$modelName}";
            }

            $clientProduct = $itemId
                ? \App\Models\ClientProduct::where('external_listing_id', (string) $itemId)
                    ->where('marketplace_account_id', $account->id)
                    ->first()
                : null;

            // MUL-273: SKU do pedido = fonte de verdade; vinculo do anuncio e
            // fallback (anuncio pode estar desatualizado — caso 90451/MUL-272).
            $skuReal = $sku !== '' && $sku !== (string) $itemId;
            $product = ($skuReal ? self::productFromOrderSku($sku, $account) : null)
                ?? $clientProduct?->product;

            // MUL-318 P2: custo so de produto confiavel; fantasma vira null
            $unitCost  = $product?->custoConfiavel();
            $totalCost = $unitCost !== null ? round($unitCost * $qty, 2) : null;

            $values = [
                'client_product_id'   => $clientProduct?->id,
                'product_id'          => $product?->id,
                // JT-022c: pai no sku; filho (sku do anuncio) em variation_sku
                'sku'                 => $product?->sku ?? ($skuReal ? $sku : (self::realCustomSku($clientProduct) ?: $sku)),
                'variation_sku'       => ($product && $skuReal && $sku !== $product->sku) ? $sku : null,
                'name'                => $displayName,
                'quantity'            => $qty,
                'unit_price'          => $price,
                'total'               => round($qty * $price, 2),
                'supplier_unit_cost'  => $unitCost,
                'supplier_total_cost' => $totalCost,
                '_external_item_id'      => (string) $itemId,
                '_external_variation_id' => $modelId ? (string) $modelId : null,
            ];

            // MUL-220: foto padronizada = cover do SKU pai; anuncio so como fallback
            $image = $item['image_info']['image_url'] ?? null;
            if ($product) {
                $cover = \App\Models\ProductMedia::where('product_id', $product->id)
                    ->orderByDesc('is_cover')->orderBy('position')->first();
                $image = ($cover?->url ?: $cover?->original_url) ?: $image;
            }
            if ($image) {
                $values['product_image'] = $image;
            }

            $result[] = $values;
        }
        return $result;
    }

    /**
     * MUL-206: persiste OrderItems previamente computados por buildShopeeItemsData.
     */
    private function persistShopeeOrderItems(Order $order, array $itemsData): void
    {
        foreach ($itemsData as $values) {
            $externalItemId = $values['_external_item_id'] ?? null;
            $externalVariationId = $values['_external_variation_id'] ?? null;
            unset($values['_external_item_id'], $values['_external_variation_id']);

            // MUL-291: o item vindo do sync do Bling da seller nasce SEM external_item_id
            // (BlingOrderSync nao grava esse campo). A chave do updateOrCreate abaixo casa
            // por external_item_id, e em SQL nada casa com NULL — entao inseria uma segunda
            // linha do mesmo SKU, com preco/custo do Bling da seller. 209 pedidos afetados.
            // Antes de criar, ADOTA o orfao do mesmo SKU: atualiza e carimba o id do marketplace.
            $skuAtual = trim((string) ($values['sku'] ?? ''));
            // JT-022c: orfao do Bling pode ter gravado o FILHO — casar pelos dois (MUL-291)
            $skusDoItem = array_values(array_filter([$skuAtual, trim((string) ($values['variation_sku'] ?? ''))]));
            if ($skuAtual !== '' && $externalItemId) {
                $orfao = OrderItem::where('order_id', $order->id)
                    ->whereIn('sku', $skusDoItem)
                    ->whereNull('external_item_id')
                    ->orderBy('id')
                    ->first();
                if ($orfao) {
                    if (self::pedidoTemTrocaManual((int) $order->id)) {
                        $values = array_diff_key($values, array_flip(self::CAMPOS_PROTEGIDOS_POS_SWAP));
                    }
                    $orfao->update($values + [
                        'external_item_id'      => (string) $externalItemId,
                        'external_variation_id' => $externalVariationId,
                    ]);
                    continue;
                }
            }

            self::upsertPreservandoTrocaManual($order, 
                [
                    'order_id'              => $order->id,
                    'external_item_id'      => (string) $externalItemId,
                    'external_variation_id' => $externalVariationId,
                ],
                $values
            );
        }
    }

    /**
     * MUL-315: de quem e a mercadoria, quando o cadastro da conta nao sabe.
     *
     * O supplier do pedido vinha SEMPRE de $account->supplier_id, que e anulavel e
     * sem validacao — conta cadastrada sem o campo gerava pedido sem dono, e
     * FanoutOrderWebhookJob:45 descarta pedido sem supplier, entao ele nunca chegava
     * na WL. Foram 689 pedidos assim, ~10/dia, ate 02/08/2026.
     *
     * products.supplier_id e NOT NULL: a mercadoria SEMPRE sabe de quem e. Medido em
     * 1.377 pedidos: 1.060 resolvem para exatamente 1 supplier e ZERO para dois.
     * So devolve valor quando ha unanimidade — pedido com itens de suppliers
     * diferentes fica nulo de proposito, porque o modelo atual nao sabe representa-lo.
     */
    private function supplierFromItemsData(array $itemsData): ?int
    {
        $ids = array_values(array_filter(array_map(
            static fn ($i) => $i['product_id'] ?? null,
            $itemsData
        )));

        if (! $ids) {
            return null;
        }

        // MUL-318 (03/08/2026): so confia em produto REAL. O catalogo do supplier 30 tem
        // 86% de produtos inativos e 82% com cost=0 — placeholders da importacao de 30/06.
        // Derivar deles inventava dono: 5 pedidos cairam no painel do MultDrop em 03/08,
        // um deles (hub 153845) com supplier_total MAIOR que o total do pedido.
        // Sem produto confiavel devolve null — o pedido nao espelha, que e o desejado.
        $suppliers = \Illuminate\Support\Facades\DB::table('products')
            ->whereIn('id', $ids)
            ->whereNotNull('supplier_id')
            ->where('is_active', 1)
            ->where('cost', '>', 0)
            ->distinct()
            ->pluck('supplier_id');

        return $suppliers->count() === 1 ? (int) $suppliers->first() : null;
    }

    /**
     * MUL-207: resolve tenant_slug do supplier via tenant_supplier + tenants.
     * Espelha BlingOrderSync::resolveTenantSlug. Preferencia por tenants != fornecefy
     * quando ha multiplos (fornecefy eh o "generico" de supplier compartilhado).
     */
    private function resolveTenantSlug(?int $supplierId, ?\App\Models\MarketplaceAccount $account = null): ?string
    {
        // INF-039: se a marketplace_account tem service marker (nao 'hubai'), usa direto
        // Bypass da regra "esconder fornecefy" para WLs em suppliers multi-tenant.
        if ($account && $account->service && $account->service !== 'hubai') {
            return $account->service;
        }

        if (!$supplierId) {
            return null;
        }

        // MUL-330: com dois tenants no mesmo supplier (ex: 'multdrop' e 'multdrop.app'),
        // o desempate era a ordem que o MySQL devolvesse — pedido nascia carimbado ora com
        // um, ora com outro, e o carimbado com o tenant sem endpoint de pedido nunca recebia
        // update. Agora prefere quem tem endpoint ATIVO assinando evento de pedido.
        $slug = \Illuminate\Support\Facades\DB::table('tenant_supplier as ts')
            ->join('tenants as t', 't.id', '=', 'ts.tenant_id')
            ->leftJoin('tenant_webhook_endpoints as e', function ($j) {
                $j->on('e.tenant_id', '=', 't.id')->where('e.active', '=', true);
            })
            ->where('ts.supplier_id', $supplierId)
            ->where('t.status', 'active')
            ->groupBy('t.slug')
            ->orderByRaw("CASE WHEN t.slug = 'fornecefy' THEN 1 ELSE 0 END ASC")
            ->orderByRaw("MAX(CASE WHEN e.events LIKE '%\"order.%' OR e.events LIKE '%\"*\"%' THEN 1 ELSE 0 END) DESC")
            ->orderBy('t.slug')
            ->value('t.slug');

        return $slug ?: null;
    }

    /**
     * MUL-208: monta endereco textual a partir do recipient_address do Shopee.
     */
    private function buildAddressString(array $recipient): ?string
    {
        if (empty($recipient)) {
            return null;
        }
        $full = trim((string) ($recipient['full_address'] ?? ''));
        if ($full !== '') {
            return $full;
        }
        $parts = array_filter([
            $recipient['district'] ?? null,
            $recipient['city']     ?? null,
            $recipient['state']    ?? null,
            $recipient['zipcode']  ?? null,
        ], fn ($v) => trim((string) $v) !== '');
        return $parts ? implode(', ', $parts) : null;
    }

    // =========================================================================
    // JOBS POS-CRIACAO
    // =========================================================================

    /**
     * Dispara FetchShippingLabelJob + RelayOrderToLegacyJob apos criacao de pedido novo pago.
     * Chamado apenas em ordens NOVAS e pagas para evitar loops.
     */
    private function dispatchPostOrderJobs(Order $order, string $marketplace, MarketplaceAccount $account): void
    {
        // FetchShippingLabel: zero-latencia — dispara imediatamente na fila label-fetch
        // O job ja tem lock interno (Cache::lock "fetch-label-{orderId}") para dedup
        FetchShippingLabelJob::dispatch($order->id, 'webhook')
            ->onQueue('label-fetch');

        Log::info('[WebhookOrderService] FetchShippingLabelJob despachado', [
            'order_id'    => $order->id,
            'marketplace' => $marketplace,
        ]);

        // MUL-363: autopay agora dispara SO no evento "ficou pagavel" (OrderObserver)

        // HUB-425: relay para o legado so acontece com o sync legado ligado.
        // Medido em 18/08/2026: goolhub.io responde "No route to host" (cURL 7) --
        // 100% dos relays falhavam, 292 erros em 7 dias e 37 jobs em failed_jobs,
        // cada pedido novo gastando 3 tentativas na fila antes de morrer. O gate
        // usado e o mesmo dos jobs agendados (LEGACY_SYNC_ENABLED, hoje false),
        // entao religar o legado religa este caminho junto -- sem flag nova.
        if (config('app.legacy_sync_enabled')) {
            // NOV-199: fila legacy dedicada — carga do legado nao compete com a default
            \App\Jobs\RelayOrderToLegacyJob::dispatch($order->id, $marketplace)
                ->onQueue('legacy');
        }

        Log::info('[WebhookOrderService] RelayOrderToLegacyJob despachado', [
            'order_id'    => $order->id,
            'marketplace' => $marketplace,
        ]);
    }

    // =========================================================================
    // METODOS PUBLICOS ESTATICOS — SYNC DE ITENS (MES-043)
    // Reutilizavel por SyncShopeeOrdersJob, ImportMarketplaceAccountDataJob
    // e comandos de backfill. Idempotente via updateOrCreate.
    // =========================================================================

    /**
     * Sincroniza itens de pedido Shopee a partir do payload bruto (item_list).
     * Idempotente: updateOrCreate por (order_id, external_item_id, external_variation_id).
     */
    public static function upsertShopeeItemsFromPayload(
        Order $order,
        array $rawOrder,
        MarketplaceAccount $account
    ): int {
        $items = $rawOrder['item_list'] ?? [];
        if (empty($items)) {
            return 0;
        }

        $count = 0;
        foreach ($items as $item) {
            $itemId    = $item['item_id'] ?? null;
            $modelId   = $item['model_id'] ?? null;
            $qty       = (int) ($item['model_quantity_purchased'] ?? $item['quantity'] ?? 1);

            // MUL-205: padrao legado — trim/empty no lugar de ?? (payload Shopee traz "" em vez de null)
            $itemName  = trim((string) ($item['item_name']  ?? ''));
            $modelName = trim((string) ($item['model_name'] ?? ''));
            $displayName = $itemName !== '' ? $itemName : 'Produto';
            if ($modelName !== '' && $modelName !== $displayName) {
                $displayName = "{$displayName} — {$modelName}";
            }

            $price = 0.0;
            if (!empty($item['model_discounted_price'])) {
                $price = (float) $item['model_discounted_price'];
            } elseif (!empty($item['model_original_price'])) {
                $price = (float) $item['model_original_price'];
            }

            $itemSku  = trim((string) ($item['item_sku']  ?? ''));
            $modelSku = trim((string) ($item['model_sku'] ?? ''));
            if ($modelSku !== '') {
                $sku = $modelSku;
            } elseif ($itemSku !== '') {
                $sku = $itemSku;
            } else {
                $sku = (string) $itemId;
            }

            $clientProduct = $itemId
                ? \App\Models\ClientProduct::where('external_listing_id', (string) $itemId)
                    ->where('marketplace_account_id', $account->id)
                    ->first()
                : null;

            // MUL-273: SKU do pedido = fonte de verdade; vinculo do anuncio e
            // fallback (anuncio pode estar desatualizado — caso 90451/MUL-272).
            $skuReal = $sku !== '' && $sku !== (string) $itemId;
            $product = ($skuReal ? self::productFromOrderSku($sku, $account) : null)
                ?? $clientProduct?->product;

            // MUL-318 P2: meia-trava virou trava inteira (faltava is_active)
            $unitCost  = $product?->custoConfiavel();
            $totalCost = $unitCost !== null ? round($unitCost * $qty, 2) : null;

            $values = [
                'client_product_id'   => $clientProduct?->id,
                'product_id'          => $product?->id,
                // JT-022c: pai no sku; filho (sku do anuncio) em variation_sku
                'sku'                 => $product?->sku ?? ($skuReal ? $sku : (self::realCustomSku($clientProduct) ?: $sku)),
                'variation_sku'       => ($product && $skuReal && $sku !== $product->sku) ? $sku : null,
                'name'                => $displayName,
                'quantity'            => $qty,
                'unit_price'          => $price,
                'total'               => round($qty * $price, 2),
                // MUL-198: custo = PRECO do catalogo (products.price); nunca cost.
                'supplier_unit_cost'  => $unitCost,
                'supplier_total_cost' => $totalCost,
            ];

            // MUL-220: foto padronizada = cover do SKU pai; anuncio so como fallback
            $image = $item['image_info']['image_url'] ?? null;
            if ($product) {
                $cover = \App\Models\ProductMedia::where('product_id', $product->id)
                    ->orderByDesc('is_cover')->orderBy('position')->first();
                $image = ($cover?->url ?: $cover?->original_url) ?: $image;
            }
            if ($image) {
                $values['product_image'] = $image;
            }

            self::upsertPreservandoTrocaManual($order, 
                [
                    'order_id'              => $order->id,
                    'external_item_id'      => (string) $itemId,
                    'external_variation_id' => $modelId ? (string) $modelId : null,
                ],
                $values
            );
            $count++;
        }

        return $count;
    }

    /**
     * Sincroniza itens de pedido ML a partir do payload bruto (order_items).
     * Idempotente: updateOrCreate por (order_id, external_item_id).
     */
    public static function upsertMLItemsFromPayload(
        Order $order,
        array $rawOrder,
        MarketplaceAccount $account
    ): int {
        $mlItems = $rawOrder['order_items'] ?? [];
        if (empty($mlItems)) {
            return 0;
        }

        $count = 0;
        foreach ($mlItems as $item) {
            $mlItemId    = $item['item']['id'] ?? null;
            // FOR-135: o ML manda variation_id em parte dos pedidos e a coluna ficava
            // sempre nula. Sem ela, duas variacoes do mesmo anuncio no mesmo pedido colidem
            // na chave e uma some. Os dois caminhos da Shopee ja gravam assim desde a
            // MUL-243; o ML nunca recebeu a correcao.
            $mlVariationId = $item['item']['variation_id'] ?? null;
            $mlVariationId = ($mlVariationId === null || $mlVariationId === '') ? null : (string) $mlVariationId;
            $itemTitle   = $item['item']['title'] ?? 'Produto';
            $qty         = (int) ($item['quantity'] ?? 1);
            $price       = (float) ($item['unit_price'] ?? 0);
            $sku         = $item['item']['seller_sku']
                        ?? $item['item']['seller_custom_field']
                        ?? $mlItemId
                        ?? 'N/A';
            $saleFee     = $item['sale_fee'] ?? null;
            $listingType = $item['listing_type_id'] ?? null;

            $clientProduct = $mlItemId
                ? \App\Models\ClientProduct::where('external_listing_id', $mlItemId)
                    ->where('marketplace_account_id', $account->id)
                    ->first()
                : null;

            // MUL-273: SKU do pedido = fonte de verdade; vinculo do anuncio e
            // fallback. FOR-044: name como ultimo recurso.
            $skuReal = $sku && $sku !== $mlItemId && ! str_starts_with((string) $sku, 'ml-');
            $product = ($skuReal ? self::productFromOrderSku($sku, $account) : null)
                ?? $clientProduct?->product;
            if (! $product && $itemTitle && $itemTitle !== 'Produto') {
                $product = \App\Models\Product::where('name', $itemTitle)
                    ->where('supplier_id', $account->supplier_id)
                    ->where('sku', 'NOT LIKE', 'ml-%')
                    ->first();
            }
            $coverImg = null;
            if ($product) {
                $cover = \App\Models\ProductMedia::where('product_id', $product->id)
                    ->orderByDesc('is_cover')->orderBy('position')->first();
                $coverImg = $cover?->url ?: $cover?->original_url;
            }

            self::upsertPreservandoTrocaManual($order, 
                [
                    'order_id'              => $order->id,
                    'external_item_id'      => $mlItemId,
                    'external_variation_id' => $mlVariationId,
                ],
                [
                    'client_product_id'   => $clientProduct?->id,
                    'product_id'          => $product?->id,
                    // JT-022c (regra do Ruan): pedido processado pelo SKU PAI; filho em variation_sku
                    'sku'                 => $product?->sku ?? ($skuReal ? $sku : null) ?? self::realCustomSku($clientProduct) ?? $sku,
                    'variation_sku'       => ($product && $skuReal && $sku !== $product->sku) ? $sku : null,
                    'name'                => $itemTitle,
                    'quantity'            => $qty,
                    'unit_price'          => $price,
                    'total'               => round($qty * $price, 2),
                    // MUL-318 P2: custo so de produto confiavel
                    'supplier_unit_cost'  => $mlCost = $product?->custoConfiavel(),
                    'supplier_total_cost' => $mlCost !== null ? round($mlCost * $qty, 2) : null,
                    'sale_fee'            => $saleFee,
                    'listing_type_id'     => $listingType,
                    'product_image'       => $coverImg,
                ]
            );
            $count++;
        }

        return $count;
    }

    /**
     * FOR-131 — MODO SOMBRA. Consulta o ponto unico de entrada e apenas REGISTRA a
     * decisao dele, sem alterar nada. Serve para comparar, com trafego real, a decisao
     * do servico novo contra a logica atual antes de qualquer troca.
     *
     * Envolvido em try/catch de proposito: em modo sombra este metodo NUNCA pode
     * derrubar uma importacao de pedido. Se ele falhar, o pedido segue como sempre.
     */
    private function sombraEntradaDePedido(
        string $canal,
        MarketplaceAccount $account,
        array $itens,
        bool $decisaoAtualImporta,
        string $referencia
    ): void {
        try {
            $svc = app(\App\Services\Pedidos\EntradaDePedido::class);
            $d   = $svc->decidir($account, $itens);

            $novaImporta = $d['decisao'] !== \App\Services\Pedidos\EntradaDePedido::DESCARTAR;
            $concorda    = $novaImporta === $decisaoAtualImporta;

            Log::channel('marketplace')->info('[FOR-131][sombra] ' . ($concorda ? 'concorda' : 'DIVERGE'), [
                'canal'          => $canal,
                'referencia'     => $referencia,
                'conta'          => $account->id,
                'supplier_conta' => $account->supplier_id,
                'atual_importa'  => $decisaoAtualImporta,
                'nova_decisao'   => $d['decisao'],
                'nova_motivo'    => $d['motivo'],
                'origens'        => collect($d['resolvidos'])->pluck('origem')->unique()->values()->all(),
                'skus'           => collect($itens)->pluck('sku')->filter()->take(5)->values()->all(),
            ]);
        } catch (\Throwable $e) {
            Log::channel('marketplace')->warning('[FOR-131][sombra] falhou (ignorado)', [
                'canal' => $canal, 'referencia' => $referencia, 'erro' => $e->getMessage(),
            ]);
        }
    }
    // ========================================================================
    // MUL-422: troca manual de SKU vence o mapeamento do marketplace.
    // O snapshot/webhook/enricher regravava o item pelo anuncio e desfazia o
    // item_product_swapped feito pelo painel (caso 157788: swap 12:38:51,
    // snapshot Shopee 12:41:07 reverteu). Em pedido com troca manual registrada,
    // o UPDATE vindo do sync preserva os campos de produto; criacao de item novo
    // e pedidos sem troca seguem exatamente como antes.
    // ========================================================================

    private const CAMPOS_PROTEGIDOS_POS_SWAP = [
        'sku', 'variation_sku', 'name', 'product_id', 'client_product_id',
        'supplier_unit_cost', 'supplier_total_cost', 'legacy_sku_pai_id', 'product_image',
    ];

    private static function pedidoTemTrocaManual(int $orderId): bool
    {
        return \Illuminate\Support\Facades\DB::table('order_events')
            ->where('order_id', $orderId)
            ->where('event_type', 'item_product_swapped')
            ->exists();
    }

    private static function upsertPreservandoTrocaManual(Order $order, array $keys, array $values): void
    {
        $q = OrderItem::query();
        foreach ($keys as $k => $v) {
            $v === null ? $q->whereNull($k) : $q->where($k, $v);
        }
        $existente = $q->first();

        if (self::pedidoTemTrocaManual((int) $order->id)) {
            // MUL-422b: em pedido com troca manual o sync tambem NAO cria linha nova —
            // recriar a linha-mae do kit fazia o explodeOrder do fanout deletar o
            // componente trocado e ressuscitar o SKU errado. Atualiza so os campos
            // nao-protegidos dos itens que ja existem.
            if ($existente) {
                $existente->update(array_diff_key($values, array_flip(self::CAMPOS_PROTEGIDOS_POS_SWAP)));
            }
            return;
        }

        OrderItem::updateOrCreate($keys, $values);
    }
}
