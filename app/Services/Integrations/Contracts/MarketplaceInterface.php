<?php

namespace App\Services\Integrations\Contracts;

use App\Models\MarketplaceAccount;
use App\Models\Product;
use App\Models\Order;
use Illuminate\Support\Collection;

interface MarketplaceInterface
{
    /**
     * Autentica e retorna uma mensagem de sucesso ou uma URL de redirecionamento OAUTH.
     */
    public function authenticate(MarketplaceAccount $account): string|array;

    /**
     * Sincroniza (Export/Update) um Produto para o Marketplace
     */
    public function syncProduct(MarketplaceAccount $account, Product $product): bool|array;

    /**
     * Importa novos pedidos da conta do marketplace
     */
    public function fetchOrders(MarketplaceAccount $account, string $sinceDate = null): array;

    /**
     * Sincroniza o estoque / preco (Inventario / Produto) de volta ao Marketplace
     */
    public function syncInventoryAndPrice(MarketplaceAccount $account, string $sku, int $quantity, float $price): bool;

    /**
     * Renova o token de acesso OAuth usando o refresh_token
     */
    public function refreshToken(MarketplaceAccount $account): ?string;

    /**
     * Gera/baixa a etiqueta de envio de um pedido no marketplace.
     * Retorna URL local do PDF salvo ou null se indisponivel.
     */
    public function getShippingLabel(MarketplaceAccount $account, Order $order): ?string;

    /**
     * Gera/baixa etiquetas de envio em lote.
     * Retorna Collection de ['order_id' => url|null].
     */
    public function getShippingLabelBatch(MarketplaceAccount $account, Collection $orders): Collection;
}
