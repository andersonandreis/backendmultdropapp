<?php

namespace App\Services\Integrations\Contracts;

use App\Models\ErpAccount;
use App\Models\Product;
use App\Models\Order;

interface ErpInterface
{
    /**
     * Inicia o fluxo OAuth e retorna URL (ou response array)
     */
    public function authenticate(ErpAccount $account): string|array;

    /**
     * Sincroniza pedido de venda para o ERP emitir NF-e
     */
    public function syncOrder(ErpAccount $account, Order $order): bool|array;

    /**
     * Busca dados fiscais (XML/URL da NFe processada)
     */
    public function fetchNfe(ErpAccount $account, Order $order): ?array;

    /**
     * Sincroniza produtos (Supplier Catalog) pro ERP ou traz do ERP
     */
    public function syncProduct(ErpAccount $account, Product $product, string $direction = 'export'): bool|array;
}
