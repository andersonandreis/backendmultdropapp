<?php

namespace App\Services\Drop\Suppliers\Contracts;

/**
 * Drop Internacional — Interface canônica para conectores de fornecedores.
 *
 * Cada fornecedor suportado (AliExpress, CJ Dropshipping, Zendrop, etc.)
 * deve implementar esta interface, permitindo que o DropOrderService e o
 * DropPricingService trabalhem de forma agnóstica ao fornecedor.
 */
interface SupplierConnectorInterface
{
    /**
     * Buscar produtos por termo de pesquisa e/ou filtros de categoria.
     *
     * @param  array  $filters  Aceita: 'query' (string), 'category' (string), 'page' (int), 'per_page' (int)
     * @return array  Lista de produtos no formato normalizado do conector
     */
    public function searchProducts(array $filters): array;

    /**
     * Buscar o detalhe completo de um produto pelo ID externo do fornecedor.
     *
     * @param  string  $externalId  ID do produto no sistema do fornecedor
     * @return array   Produto no formato normalizado: id, title, price_usd, variants, images, etc.
     */
    public function getProduct(string $externalId): array;

    /**
     * Criar pedido de compra no fornecedor.
     *
     * @param  array  $orderData  Deve conter: product_id, variant_id, quantity, shipping_address, etc.
     * @return array  Resposta do fornecedor com: external_order_id, status, estimated_shipping_usd, etc.
     */
    public function createOrder(array $orderData): array;

    /**
     * Consultar o status de um pedido existente no fornecedor.
     *
     * @param  string  $externalOrderId  ID do pedido no sistema do fornecedor
     * @return array   Status normalizado: status, paid_at, processing_at, etc.
     */
    public function getOrderStatus(string $externalOrderId): array;

    /**
     * Consultar informações de rastreio de um pedido.
     *
     * @param  string  $externalOrderId  ID do pedido no sistema do fornecedor
     * @return array   Dados de rastreio: tracking_code, carrier, events[], estimated_delivery, etc.
     */
    public function getTracking(string $externalOrderId): array;

    /**
     * Retorna o slug identificador único deste conector.
     * Usado como chave de roteamento e armazenamento.
     *
     * @return string  Ex: 'aliexpress', 'cj', 'zendrop'
     */
    public function getSlug(): string;
}
