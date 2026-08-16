<?php

namespace App\Services\Financial\Ledger;

/**
 * MUL-363 Fase 0 — metadados de um lancamento no ledger da wallet.
 *
 * Regra de extensao (decisao do Ruan, 11/08/2026): o nucleo tem SO credit/debit.
 * Caso novo (cartao, moeda, gateway, parcelamento...) NUNCA vira metodo novo —
 * vira campo aqui, de preferencia dentro de `extra` (persistido como JSON em
 * client_supplier_transactions.meta), que nao exige migration pra crescer.
 */
final class LedgerEntryMeta
{
    /**
     * @param string      $type                  semantica do lancamento — vai em transaction_type
     *                                           (order_debit|auto_pay|forced_charge|pix_topup|refund|adjustment|...)
     * @param string      $description           texto humano do extrato
     * @param int|null    $orderId               pedido vinculado (obrigatorio pra debito de pedido)
     * @param string|null $actor                 quem operou: "user:54" | "system:AutoPayService"
     * @param string|null $origin                backend que processou (APP_TENANT); default preenchido pelo nucleo
     * @param string|null $idempotencyKey        chave unica da operacao (UNIQUE no banco) — replay devolve a tx original
     * @param int|null    $reversesTransactionId contra-partida: id da transacao que este lancamento corrige
     * @param int|null    $pixTransactionId      vinculo com pix_transactions (topup/pagamento PIX)
     * @param string|null $reference             referencia externa (id gateway, external_id...)
     * @param array       $extra                 extensoes livres (payment_method, currency, gateway...) — JSON
     */
    public function __construct(
        public readonly string $type,
        public readonly string $description,
        public readonly ?int $orderId = null,
        public readonly ?string $actor = null,
        public readonly ?string $origin = null,
        public readonly ?string $idempotencyKey = null,
        public readonly ?int $reversesTransactionId = null,
        public readonly ?int $pixTransactionId = null,
        public readonly ?string $reference = null,
        public readonly array $extra = [],
    ) {}
}
