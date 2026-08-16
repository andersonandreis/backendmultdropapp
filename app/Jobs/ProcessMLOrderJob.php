<?php

namespace App\Jobs;

use App\Models\MarketplaceAccount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\MercadoLivreService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class ProcessMLOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 60;

    public function __construct(
        public readonly string  $mlOrderId,
        public readonly ?int    $mlUserId
    ) {}

    public function handle(MercadoLivreService $mlService): void
    {
        // Encontra a MarketplaceAccount pelo ml_user_id
        $account = MarketplaceAccount::where('ml_user_id', (string) $this->mlUserId)
            ->whereIn('platform', ['mercadolivre', 'mercado_livre'])
            ->first();

        if (! $account) {
            Log::warning("[ProcessMLOrderJob] Conta ML não encontrada para user_id={$this->mlUserId}");
            return;
        }

        $token = $mlService->getValidToken($account);

        // Busca pedido na API ML
        $response = Http::withToken($token)
            ->get("https://api.mercadolibre.com/orders/{$this->mlOrderId}");

        if ($response->failed()) {
            // HUB-182: 403 (PolicyAgent/moderacao) e 404 (pedido inexistente) nao sao
            // recuperaveis por retry — descartar sem ERROR (webhook novo re-dispara se mudar).
            if (in_array($response->status(), [403, 404], true)) {
                Log::warning("[ProcessMLOrderJob] Pedido ML #{$this->mlOrderId} HTTP {$response->status()} — descartando sem retry");
                return;
            }

            Log::error("[ProcessMLOrderJob] Falha ao buscar pedido ML #{$this->mlOrderId}", [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            $this->release(60); // Tenta novamente em 60s
            return;
        }

        $mlOrder = $response->json();

        // Mapeia status ML para status HubAI
        $statusMap = [
            'payment_required'  => 'pending',
            'payment_in_process' => 'pending',
            'paid'              => 'paid',
            'cancelled'         => 'cancelled',
            'invalid'           => 'cancelled',
        ];
        $localStatus = $statusMap[$mlOrder['status'] ?? ''] ?? 'pending';

        // Cria ou atualiza pedido local
        // FOR-124: sem withoutTenantSupplierScope o escopo esconde pedido com supplier_id
        // NULL (whereIn nunca casa com NULL) e a deduplicacao cria um pedido duplicado.
        $isNew = ! Order::withoutTenantSupplierScope()->where('external_order_id', (string) $this->mlOrderId)->exists();

        $buyer  = $mlOrder['buyer'] ?? [];
        $ship   = $mlOrder['shipping'] ?? [];

        $order = Order::withoutTenantSupplierScope()->updateOrCreate(
            ['external_order_id' => (string) $this->mlOrderId],
            [
                'client_id'          => $account->client_id,
                'supplier_id'        => $account->supplier_id,
                // FOR-133: este job criava a linha SEM conta e SEM marketplace_order_id.
                // Sem conta nao ha loja, sem loja nao ha fornecedor nem SKU do seller -- o
                // pedido nascia orfao, com item sem produto e sem custo. E a coluna vazia
                // fazia o webhook nao reconhecer a linha e criar uma segunda para a mesma
                // venda. Os dois valores ja estao em maos aqui.
                'marketplace_account_id' => $account->id,
                'marketplace_order_id'   => (string) $this->mlOrderId,
                'source'             => 'mercadolivre',
                'status'             => $localStatus,
                'buyer_id'           => (string) ($buyer['id'] ?? ''),
                'buyer_nickname'     => $buyer['nickname'] ?? '',
                'customer_name'      => ($buyer['first_name'] ?? '') . ' ' . ($buyer['last_name'] ?? ''),
                'subtotal'           => $mlOrder['total_amount'] ?? 0,
                'total'              => $mlOrder['total_amount'] ?? 0,
                'currency'           => $mlOrder['currency_id'] ?? 'BRL',
                'external_shipping_id' => !empty($ship['id']) ? (string) $ship['id'] : null,
                'paid_at'            => $localStatus === 'paid' ? now() : null,
            ]
        );

        // Sincroniza itens do pedido
        foreach ($mlOrder['order_items'] ?? [] as $item) {
            $mlItemId  = $item['item']['id'] ?? null;
            $itemTitle = $item['item']['title'] ?? 'Produto';
            $qty       = $item['quantity'] ?? 1;
            $price     = $item['unit_price'] ?? 0;
            $sku       = $item['item']['seller_sku'] ?? $item['item']['seller_custom_field'] ?? $mlItemId ?? 'N/A';
            $varSku    = $item['item']['variation_id'] ?? null;
            $saleFee   = $item['sale_fee'] ?? null;
            $listingType = $item['listing_type_id'] ?? null;

            // Tenta vincular ao ClientProduct pelo external_listing_id
            $clientProduct = $mlItemId
                ? \App\Models\ClientProduct::where('external_listing_id', $mlItemId)
                    ->where('marketplace_account_id', $account->id)
                    ->first()
                : null;

            // MUL-273: SKU do pedido = fonte de verdade; vinculo do anuncio e
            // fallback. FOR-044: name como ultimo recurso.
            $skuReal = $sku && $sku !== $mlItemId && ! str_starts_with((string) $sku, 'ml-');
            $product = ($skuReal ? \App\Services\WebhookOrderService::productFromOrderSku($sku, $account) : null)
                ?? $clientProduct?->product;
            if ((! $product || str_starts_with((string) $product->sku, 'ml-')) && $itemTitle && $itemTitle !== 'Produto') {
                $found = \App\Models\Product::where('name', $itemTitle)
                    ->where('supplier_id', $account->supplier_id)
                    ->where('sku', 'NOT LIKE', 'ml-%')
                    ->first();
                if ($found) $product = $found;
            }
            $coverImg = null;
            if ($product) {
                $cover = \App\Models\ProductMedia::where('product_id', $product->id)
                    ->orderByDesc('is_cover')->orderBy('position')->first();
                $coverImg = $cover?->url ?: $cover?->original_url;
            }

            // FOR-135: ver comentario no WebhookOrderService.
            $mlVariationId = $item['item']['variation_id'] ?? null;
            $mlVariationId = ($mlVariationId === null || $mlVariationId === '') ? null : (string) $mlVariationId;

            OrderItem::updateOrCreate(
                [
                    'order_id'              => $order->id,
                    'external_item_id'      => $mlItemId,
                    'external_variation_id' => $mlVariationId,
                ],
                [
                    'client_product_id' => $clientProduct?->id,
                    'product_id'        => $product?->id,
                    'sku'               => ($skuReal ? $sku : null) ?? $product?->sku ?? $clientProduct?->custom_sku ?? $sku,
                    'name'              => $itemTitle,
                    'quantity'          => $qty,
                    'unit_price'        => $price,
                    'total'             => $qty * $price,
                    'supplier_unit_cost'  => $product?->price ?? 0,
                    'supplier_total_cost' => ($product?->price ?? 0) * $qty,
                    'sale_fee'          => $saleFee,
                    'listing_type_id'   => $listingType,
                    'product_image'     => $coverImg,
                ]
            );
        }

        // Atualiza supplier_total com a soma dos custos dos items
        $supplierTotal = $order->items()->sum('supplier_total_cost');
        $order->update(['supplier_total' => $supplierTotal]);

        // Notifica fornecedor se for pedido novo e pago
        if ($isNew && $localStatus === 'paid') {
            Log::info("[ProcessMLOrderJob] Novo pedido pago criado #{$order->order_number} (ML #{$this->mlOrderId})");
            // Aqui pode disparar Notification para o fornecedor via DB/email/push
            // Notification::send($account->supplier->user, new NewOrderNotification($order));
            // MUL-363: autopay agora dispara SO no evento "ficou pagavel" (OrderObserver)
        }

        Log::info("[ProcessMLOrderJob] Pedido ML #{$this->mlOrderId} processado → status: {$localStatus}");
    }
}
