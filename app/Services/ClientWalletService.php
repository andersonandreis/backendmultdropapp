<?php

namespace App\Services;

use App\Models\ClientSupplierBalance;
use App\Models\ClientSupplierTransaction;
use App\Models\Order;
use App\Models\PixTransaction;
use App\Services\Financial\Ledger\InsufficientBalanceException;
use App\Services\Financial\Ledger\LedgerEntryMeta;
use App\Services\Financial\Ledger\WalletLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Exception;

/**
 * MUL-363 Fase 0: este service virou CASCA FINA sobre o nucleo canonico
 * (App\Services\Financial\Ledger\WalletLedger — so credit/debit + metadados).
 * Assinaturas preservadas: quem chama nao muda. Semantica nova = metadado
 * novo no LedgerEntryMeta, nunca metodo novo aqui.
 *
 * Ganhos sobre a versao antiga: lock tambem no credito, running_balance gravado
 * no INSERT (nunca UPDATE posterior — append-only), idempotencia dura no topup,
 * actor/origin em toda linha, payment_events logando ate tentativa negada.
 */
class ClientWalletService
{
    public function __construct(
        protected WalletLedger $ledger
    ) {}

    /**
     * Adiciona um crédito (reembolso ou recarga PIX) à carteira do lojista atrelado a um fornecedor.
     *
     * @param string|null $reference Referência externa (ex: ID do pagamento Asaas/Shipay)
     */
    public function credit(int $clientId, int $supplierId, float $amount, string $description, ?int $orderId = null, ?string $reference = null): ClientSupplierTransaction
    {
        if ($amount <= 0) {
            throw new Exception("Credit amount must be greater than zero.");
        }

        return $this->ledger->credit($clientId, $supplierId, $amount, new LedgerEntryMeta(
            type: 'credit',
            description: $description,
            orderId: $orderId,
            reference: $reference,
            actor: self::currentActor(),
        ));
    }

    /**
     * Retorna o saldo atual do lojista com um fornecedor.
     */
    public function getBalance(int $clientId, int $supplierId): float
    {
        return $this->checkBalance($clientId, $supplierId);
    }

    /**
     * Retorna o extrato de transações do lojista com um fornecedor, com filtro opcional de período.
     */
    public function getStatement(int $clientId, int $supplierId, ?Carbon $from = null, ?Carbon $to = null): Collection
    {
        // MUL-362 P6: desempate por id (mesmo motivo do FinancialController@transactions)
        $query = ClientSupplierTransaction::where('client_id', $clientId)
            ->where('supplier_id', $supplierId)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc');

        if ($from) {
            $query->where('created_at', '>=', $from);
        }

        if ($to) {
            $query->where('created_at', '<=', $to);
        }

        return $query->get();
    }

    /**
     * Tenta debitar (usar saldo) para o pagamento ou parte do pagamento de um pedido.
     * Retorna o valor efetivamente debitado (se ele tinha 50, e o pedido é 100, debita 50. Se pediu 30, debita 30).
     */
    public function debitAvailable(int $clientId, int $supplierId, float $requestedAmount, string $description, ?int $orderId = null, ?string $idempotencyKey = null): float
    {
        if ($requestedAmount <= 0) {
            return 0;
        }

        // Semantica historica preservada: debita o MIN(saldo, solicitado).
        // O nucleo e tudo-ou-nada; a parcialidade e responsabilidade desta casca.
        // Corrida entre a leitura e o debito e resolvida com uma re-tentativa.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $available = $this->checkBalance($clientId, $supplierId);
            $toDebit   = round(min($available, $requestedAmount), 2);

            if ($toDebit <= 0) {
                return 0;
            }

            try {
                $this->ledger->debit($clientId, $supplierId, $toDebit, new LedgerEntryMeta(
                    type: 'order_debit',
                    description: $description,
                    orderId: $orderId,
                    actor: self::currentActor(),
                    idempotencyKey: $idempotencyKey, // MUL-366: fecha corrida de duplo-clique no pagar
                ));

                return $toDebit;
            } catch (InsufficientBalanceException) {
                // saldo mudou entre a leitura e o lock — recalcula uma vez
            }
        }

        return 0;
    }

    /**
     * Verifica o saldo disponível de um lojista com um fornecedor
     */
    public function checkBalance(int $clientId, int $supplierId): float
    {
        $balanceRecord = ClientSupplierBalance::where('client_id', $clientId)
            ->where('supplier_id', $supplierId)
            ->first();

        return $balanceRecord ? (float) $balanceRecord->balance : 0;
    }

    // -------------------------------------------------------------------------
    // Metodos semanticos (cascas sobre o nucleo — preenchem so o metadado)
    // -------------------------------------------------------------------------

    /**
     * Credita recarga PIX confirmada.
     *
     * Deve ser chamado apos PixTransaction::markAsPaid() para topups de wallet.
     * Idempotente por PIX: reentrega de webhook nao credita duas vezes.
     */
    public function creditTopup(int $clientId, int $supplierId, float $amount, PixTransaction $pixTx): ClientSupplierTransaction
    {
        return $this->ledger->credit($clientId, $supplierId, $amount, new LedgerEntryMeta(
            type: 'pix_topup',
            description: "Recarga PIX #{$pixTx->id}",
            actor: self::currentActor(),
            idempotencyKey: "pix_topup:{$pixTx->id}",
            pixTransactionId: $pixTx->id,
            reference: $pixTx->external_id,
            extra: ['payment_method' => 'pix', 'gateway' => $pixTx->gateway],
        ));
    }

    /**
     * Debita a wallet para pagamento de um pedido especifico.
     *
     * Retorna o valor efetivamente debitado (min entre saldo e valor).
     *
     * @throws Exception Se o pedido ja foi pago ou o valor for invalido.
     */
    public function debitForOrder(int $clientId, int $supplierId, float $amount, Order $order, ?string $idempotencyKey = null): float
    {
        // FOR-046: guard contra debito zero/negativo (protege de burla).
        if ($amount <= 0.0) {
            throw new \RuntimeException(
                "debitForOrder recusado: valor invalido (R$ {$amount}) para pedido #{$order->id}"
            );
        }
        // FOR-046: idempotencia — pedido ja pago pelo wallet nao pode ser debitado 2x.
        if ($order->wallet_paid_at !== null) {
            throw new \RuntimeException(
                "debitForOrder recusado: pedido #{$order->id} ja pago em {$order->wallet_paid_at}"
            );
        }

        return $this->debitAvailable(
            $clientId,
            $supplierId,
            $amount,
            "Pagamento pedido #{$order->id}",
            $order->id,
            $idempotencyKey,
        );
    }

    /**
     * Credita reembolso na wallet do cliente referente a um pedido.
     *
     * @param int|null    $reversesTransactionId contra-partida: id do debito original que esta sendo corrigido
     * @param string|null $idempotencyKey        chave unica (UNIQUE no banco) — reentrega/observer duplo nao credita 2x
     */
    public function creditRefund(int $clientId, int $supplierId, float $amount, Order $order, string $reason, ?int $reversesTransactionId = null, ?string $idempotencyKey = null): ClientSupplierTransaction
    {
        return $this->ledger->credit($clientId, $supplierId, $amount, new LedgerEntryMeta(
            type: 'refund',
            description: 'Estorno pedido #' . ($order->order_number ?: $order->id) . " ({$reason})",
            orderId: $order->id,
            actor: self::currentActor(),
            reversesTransactionId: $reversesTransactionId,
            idempotencyKey: $idempotencyKey,
        ));
    }

    /** Identifica quem opera: usuario autenticado ou processo de sistema. */
    private static function currentActor(): string
    {
        $user = auth()->user();

        return $user ? "user:{$user->id}" : 'system:' . (app()->runningInConsole() ? 'console' : 'http');
    }
}
