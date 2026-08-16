<?php

namespace App\Services\Marketplace\Reconciliation;

use Carbon\Carbon;

/**
 * DTO padrao de pedido para reconciliacao.
 *
 * Normaliza as diferentes estruturas de resposta (Shopee, ML, Bling)
 * em um formato unico consumido pelo MissedOrderReconciliationService.
 */
final class ReconciliationOrderDto
{
    /**
     * @param  string       $marketplace          Slug: 'shopee' | 'ml' | 'bling'
     * @param  string       $marketplaceOrderId   ID original do pedido no marketplace
     * @param  string|null  $buyerName            Nome do comprador (nullable)
     * @param  string|null  $buyerDoc             CPF/CNPJ do comprador (nullable, so digitos)
     * @param  int          $amountCents          Valor total em centavos
     * @param  string       $currency             Codigo ISO 4217: 'BRL', 'USD', etc.
     * @param  array        $products             [['sku'=>string, 'qty'=>int, 'unit_price'=>int], ...]
     *                                            unit_price em centavos
     * @param  Carbon       $createdAt            Data/hora de criacao no marketplace (UTC)
     * @param  array        $rawPayload           Payload bruto da API (para marketplace_data JSON)
     */
    public function __construct(
        public readonly string  $marketplace,
        public readonly string  $marketplaceOrderId,
        public readonly ?string $buyerName,
        public readonly ?string $buyerDoc,
        public readonly int     $amountCents,
        public readonly string  $currency,
        public readonly array   $products,
        public readonly Carbon  $createdAt,
        public readonly array   $rawPayload,
    ) {}

    /**
     * Representacao array para persistencia em JSON (marketplace_data).
     */
    public function toArray(): array
    {
        return [
            'marketplace'          => $this->marketplace,
            'marketplace_order_id' => $this->marketplaceOrderId,
            'buyer_name'           => $this->buyerName,
            'buyer_doc'            => $this->buyerDoc,
            'amount_cents'         => $this->amountCents,
            'currency'             => $this->currency,
            'products'             => $this->products,
            'created_at'           => $this->createdAt->toIso8601String(),
        ];
    }
}
