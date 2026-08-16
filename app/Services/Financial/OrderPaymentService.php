<?php

namespace App\Services\Financial;

use App\Jobs\DispatchEventNotificationJob;
use App\Jobs\FetchShippingLabelJob;
use App\Models\Order;
use App\Models\ClientSupplierTransaction;
use App\Models\Payment;
use App\Models\PixTransaction;
use App\Models\Supplier;
use App\Services\CatalogBonusService;
use App\Services\ClientWalletService;
use App\Services\Events\EventDispatcherService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orquestrador central de pagamento de pedidos no HubAI.
 *
 * Estrategia automatica:
 *   1. Wallet tem saldo suficiente  → paga 100% com wallet
 *   2. Wallet tem saldo parcial     → debita wallet + gera PIX para o restante
 *   3. Wallet sem saldo / desabilitada → gera PIX para o total
 *
 * Todos os metodos que envolvem multiplas escritas sao envolvidos em DB::transaction().
 */
class OrderPaymentService
{
    public function __construct(
        private ClientWalletService $walletService,
        private PixService $pixService,
        private ReconciliationService $reconciliationService,
        private EventDispatcherService $eventDispatcher,
        private CatalogBonusService $catalogBonusService,
    ) {}

    // -------------------------------------------------------------------------
    // payOrder — ponto de entrada principal
    // -------------------------------------------------------------------------

    /**
     * Decide a estrategia de pagamento e executa.
     *
     * @return array{
     *   status: 'paid'|'pix_required',
     *   wallet_used: float,
     *   pix_needed: float,
     *   pix_transaction: PixTransaction|null,
     *   payment: Payment,
     * }
     */
    public function payOrder(Order $order, Supplier $supplier, ?string $method = null): array
    {
        $settings = $supplier->paymentSetting;
        $clientId = $order->client_id;
        // FOR-045: cobrar o CUSTO do fornecedor (supplier_total), NAO o preco de venda ML.
        // Bug anterior: cliente pagava R$19,41 (venda) em vez de R$4,90 (custo) por
        // pedido — 4x mais caro. Fallback para order->total caso supplier_total NULL
        // (pedido antigo/legacy sem order_items processados).
        $total    = (float) ($order->supplier_total !== null ? $order->supplier_total : $order->total);

        // FOR-046 GUARDS DE BLINDAGEM FINANCEIRA:
        // (1) Idempotencia: nao cobrar o mesmo pedido 2x.
        if ($order->wallet_paid_at !== null) {
            throw new \RuntimeException(
                "Pedido #{$order->id} ja foi pago em {$order->wallet_paid_at}. Cobranca duplicada bloqueada."
            );
        }
        // (2) Valor invalido: bloqueia pay com valor <= 0 (protege de burla).
        if ($total <= 0.0) {
            throw new \RuntimeException(
                "Pedido #{$order->id} com valor invalido (R$ {$total}). " .
                "supplier_total deve estar preenchido — verifique se order_items foram processados."
            );
        }
        // (3) Pedido cancelado nao pode ser cobrado.
        if ($order->canonical_status === 'cancelled') {
            throw new \RuntimeException(
                "Pedido #{$order->id} esta cancelado. Cobranca bloqueada."
            );
        }

        // (4) NOV-207 Etapa 3: exige etiqueta disponivel antes de liberar pagamento.
        // Excecoes:
        //  - pedido ja enviado/entregue (seller resolveu por fora)
        //  - MUL-280: Amazon Fulfillment (FBA) — canal do Bling em que a
        //    etiqueta e emitida pela Amazon e nao chega ao sistema. DBA nao
        //    entra: DBA tem etiqueta acessivel pelo sistema.
        $temEtiqueta        = ! empty($order->label_url) || ! empty($order->manual_label_path);
        $jaEnviado          = in_array($order->canonical_status, ['shipped', 'delivered'], true);
        $isAmazonSemLabel   = self::isAmazonFulfillment($order);
        if (! $temEtiqueta && ! $jaEnviado && ! $isAmazonSemLabel) {
            throw new \RuntimeException(
                "[label_required] Pedido #{$order->id} sem etiqueta disponivel. " .
                "Resolva o problema no marketplace antes de pagar."
            );
        }

        // Consultar saldo da wallet apenas se o fornecedor aceitar
        $walletBalance = 0;
        if ($settings && $settings->allows_wallet_payment) {
            $walletBalance = $this->walletService->getBalance($clientId, $supplier->id);
        }

        // MUL-366: escolha explicita do seller no painel. 'balance' = so debito na
        // wallet (nunca gera PIX); 'pix' = PIX do valor TOTAL mesmo com saldo
        // (confirmacao registra par credito+debito no nucleo). Sem method, mantem
        // a decisao automatica abaixo (compat com autopay, lote e chamadas antigas).
        if ($method === 'balance') {
            if (! $settings || ! $settings->allows_wallet_payment) {
                throw new \RuntimeException('Este fornecedor não aceita pagamento com saldo da carteira.');
            }
            if ($walletBalance < $total) {
                throw new \RuntimeException(sprintf(
                    '[insufficient_balance] Saldo insuficiente: R$ %.2f disponível, o pedido custa R$ %.2f. Deposite ou pague com PIX.',
                    $walletBalance,
                    $total
                ));
            }
            return $this->payWithWalletOnly($order, $supplier, $total);
        }

        if ($method === 'pix') {
            return $this->payWithPixOnly($order, $supplier, $total);
        }

        if ($walletBalance >= $total) {
            return $this->payWithWalletOnly($order, $supplier, $total);
        }

        if ($walletBalance > 0 && $settings && $settings->allows_wallet_payment) {
            return $this->payWithPartialWalletAndPix($order, $supplier, $walletBalance, $total);
        }

        return $this->payWithPixOnly($order, $supplier, $total);
    }

    // -------------------------------------------------------------------------
    // payWithWalletOnly
    // -------------------------------------------------------------------------

    /**
     * Paga o pedido integralmente com o saldo da wallet.
     *
     * @return array{status: 'paid', wallet_used: float, pix_needed: 0, pix_transaction: null, payment: Payment}
     */
    public function payWithWalletOnly(Order $order, Supplier $supplier, float $total): array
    {
        return DB::transaction(function () use ($order, $supplier, $total) {
            // 1. Debitar wallet — debitAvailable garante lock e retorna o que realmente debitou.
            //    MUL-366: chave idempotente — duplo-clique/replay devolve a mesma tx.
            $debited = $this->walletService->debitForOrder(
                $order->client_id,
                $supplier->id,
                $total,
                $order,
                "seller_pay:order:{$order->id}",
            );

            // 1b. FOR-043: Marcar pedido como pago pela wallet + amarrar TX + disparar etiqueta
            //     Antes esse passo estava faltando: cliente pagava mas orders.wallet_paid_at
            //     ficava NULL e o FetchShippingLabelJob nunca era disparado (66 casos identificados).
            $walletTx = ClientSupplierTransaction::where('order_id', $order->id)
                ->where('client_id', $order->client_id)
                ->where('supplier_id', $supplier->id)
                ->where('type', 'debit')
                ->latest('id')
                ->first();
            $order->update([
                'wallet_paid_at'        => now(),
                'wallet_transaction_id' => $walletTx?->id,
                // FOR-130: o fornecedor precisa auditar o recebimento. Pagamento por saldo
                // NAO gera transacao no gateway -- o dinheiro entrou antes, na recarga --
                // entao marca o metodo e deixa o id nulo, em vez de exibir id de outra coisa.
                'payment_method'        => 'saldo',
            ]);
            FetchShippingLabelJob::dispatch($order->id, 'wallet_paid')->onQueue('default');
            // MUL-363: Bling sync dispara SO no evento "pedido pago" (OrderObserver)

            // 2. Criar registro de pagamento
            $payment = Payment::create([
                'order_id'        => $order->id,
                'client_id'       => $order->client_id,
                'supplier_id'     => $supplier->id,
                'gateway'         => 'wallet',
                'method'          => 'wallet',
                'amount'          => $total,
                'wallet_amount'   => $debited,
                'pix_amount'      => 0,
                'fee_amount'      => 0,
                'status'          => 'paid',
                'paid_at'         => now(),
            ]);

            // 3. Creditar fornecedor no ledger de reconciliacao
            $this->reconciliationService->creditSale($order);

            // SEL-171: registra subsidio se pedido tem bonus catalogo (silencioso).
            $this->catalogBonusService->tryRecordFromOrder($order->fresh());

            // 4. Disparar evento
            $this->eventDispatcher->dispatch('payment.confirmed', [
                'order_id'  => $order->id,
                'amount'    => $total,
                'method'    => 'wallet',
                'payment_id' => $payment->id,
            ], $supplier->id);

            return [
                'status'          => 'paid',
                'wallet_used'     => $debited,
                'pix_needed'      => 0,
                'pix_transaction' => null,
                'payment'         => $payment,
            ];
        });
    }

    // -------------------------------------------------------------------------
    // payWithPartialWalletAndPix
    // -------------------------------------------------------------------------

    /**
     * Usa o saldo disponivel da wallet e gera PIX apenas para o valor restante.
     *
     * O Payment fica com status 'pending' ate o PIX ser confirmado.
     *
     * @return array{status: 'pix_required', wallet_used: float, pix_needed: float, pix_transaction: PixTransaction, payment: Payment}
     */
    public function payWithPartialWalletAndPix(
        Order $order,
        Supplier $supplier,
        float $walletBalance,
        float $total
    ): array {
        $pixAmount = round($total - $walletBalance, 2);

        return DB::transaction(function () use ($order, $supplier, $walletBalance, $pixAmount, $total) {
            // 1. Debitar o que tem na wallet (MUL-366: chave idempotente da perna saldo)
            $debited = $this->walletService->debitForOrder(
                $order->client_id,
                $supplier->id,
                $walletBalance,
                $order,
                "seller_pay_partial:order:{$order->id}",
            );

            // 2. Gerar PIX apenas para o valor restante
            $pixTransaction = $this->pixService->createOrderPix($order, $supplier, $pixAmount);

            // 3. Criar Payment em estado pendente (aguardando PIX)
            $payment = Payment::create([
                'order_id'           => $order->id,
                'client_id'          => $order->client_id,
                'supplier_id'        => $supplier->id,
                'gateway'            => $supplier->paymentSetting->gateway ?? 'asaas',
                'method'             => 'wallet_pix',
                'amount'             => $total,
                'wallet_amount'      => $debited,
                'pix_amount'         => $pixAmount,
                'fee_amount'         => 0,
                'status'             => 'pending',
                'pix_transaction_id' => $pixTransaction->id,
            ]);

            return [
                'status'          => 'pix_required',
                'wallet_used'     => $debited,
                'pix_needed'      => $pixAmount,
                'pix_transaction' => $pixTransaction,
                'payment'         => $payment,
            ];
        });
    }

    // -------------------------------------------------------------------------
    // payWithPixOnly
    // -------------------------------------------------------------------------

    /**
     * Gera PIX para o valor total do pedido (sem uso de wallet).
     *
     * @return array{status: 'pix_required', wallet_used: 0, pix_needed: float, pix_transaction: PixTransaction, payment: Payment}
     */
    public function payWithPixOnly(Order $order, Supplier $supplier, float $total): array
    {
        $pixTransaction = $this->pixService->createOrderPix($order, $supplier);

        $payment = Payment::create([
            'order_id'           => $order->id,
            'client_id'          => $order->client_id,
            'supplier_id'        => $supplier->id,
            'gateway'            => $supplier->paymentSetting->gateway ?? 'asaas',
            'method'             => 'pix',
            'amount'             => $total,
            'wallet_amount'      => 0,
            'pix_amount'         => $total,
            'fee_amount'         => 0,
            'status'             => 'pending',
            'pix_transaction_id' => $pixTransaction->id,
        ]);

        return [
            'status'          => 'pix_required',
            'wallet_used'     => 0,
            'pix_needed'      => $total,
            'pix_transaction' => $pixTransaction,
            'payment'         => $payment,
        ];
    }

    // -------------------------------------------------------------------------
    // confirmOrderPix — chamado pelo webhook handler
    // -------------------------------------------------------------------------

    /**
     * Finaliza o pagamento apos confirmacao do PIX pelo gateway.
     *
     * Responsabilidades:
     *  - Marca o PixTransaction como pago
     *  - Atualiza o Payment correspondente
     *  - Credita o fornecedor no ledger (creditSale)
     *  - Se for topup de wallet, credita o saldo do cliente
     *  - Dispara evento payment.confirmed ou wallet.topup
     */
    public function confirmOrderPix(PixTransaction $pixTransaction): void
    {
        DB::transaction(function () use ($pixTransaction) {
            $pixTransaction->markAsPaid();

            $payment = Payment::where('pix_transaction_id', $pixTransaction->id)->first();

            if ($payment) {
                $payment->update([
                    'status'     => 'paid',
                    'paid_at'    => now(),
                    'fee_amount' => $pixTransaction->fee_amount,
                ]);

                $order = $payment->order;
                if ($order) {
                    // FOR-053: PIX puro confirmado — setar wallet_paid_at para liberar
                    // o gate 2a do FetchShippingLabelJob (que bloqueia se NULL).
                    if ($order->wallet_paid_at === null) {
                        $order->update([
                            'wallet_paid_at'        => now(),
                            'wallet_transaction_id' => null, // PIX puro, nao via wallet
                            // FOR-130: id da transacao no gateway, para o fornecedor
                            // conferir o recebimento no extrato dele.
                            'payment_external_id'   => $pixTransaction->external_id,
                            'payment_method'        => 'pix',
                            'payment_gateway'       => $pixTransaction->gateway,
                        ]);
                        FetchShippingLabelJob::dispatch($order->id, 'wallet_paid')->onQueue('default');
                    }
                    // MUL-363: Bling sync dispara SO no evento "pedido pago" (OrderObserver)
                    $this->reconciliationService->creditSale($order);

                    // MUL-363 Fase 3 (era FOR-053-B): PIX puro no extrato como PAR liquido-zero
                    // via nucleo (entrada do PIX + debito do pedido). O registro antigo criava
                    // debito sem mexer no saldo — linha orfa que violava saldo == SUM(ledger).
                    // Idempotente por (pix, pedido): reentrega nao duplica.
                    try {
                        $ledger = app(\App\Services\Financial\Ledger\WalletLedger::class);
                        $amountPix = round((float) $pixTransaction->amount, 2);
                        if ($amountPix > 0) {
                            $ledger->credit($order->client_id, $order->supplier_id, $amountPix,
                                new \App\Services\Financial\Ledger\LedgerEntryMeta(
                                    type: 'pix_payment_in',
                                    description: "PIX recebido pedido #{$order->order_number}",
                                    orderId: $order->id,
                                    actor: 'system:OrderPaymentService',
                                    idempotencyKey: "pix_in:{$pixTransaction->id}:{$order->id}",
                                    pixTransactionId: $pixTransaction->id,
                                    reference: 'pix_direct_' . $pixTransaction->id,
                                    extra: ['payment_method' => 'pix', 'gateway' => $pixTransaction->gateway ?? 'shipay'],
                                ));
                            $ledger->debit($order->client_id, $order->supplier_id, $amountPix,
                                new \App\Services\Financial\Ledger\LedgerEntryMeta(
                                    type: 'order_pix_payment',
                                    description: "Pagamento pedido #{$order->order_number} via PIX Shipay",
                                    orderId: $order->id,
                                    actor: 'system:OrderPaymentService',
                                    idempotencyKey: "pix_charge:{$pixTransaction->id}:{$order->id}",
                                    pixTransactionId: $pixTransaction->id,
                                    reference: 'pix_direct_' . $pixTransaction->id,
                                    extra: ['payment_method' => 'pix', 'gateway' => $pixTransaction->gateway ?? 'shipay'],
                                ));
                        }
                    } catch (\Throwable $ePix) {
                        Log::warning('[OrderPayment] registro PIX no ledger falhou (pagamento preservado)', [
                            'order_id' => $order->id, 'error' => $ePix->getMessage(),
                        ]);
                    }

                    // SEL-171: registra subsidio se pedido tem bonus catalogo (silencioso).
                    $this->catalogBonusService->tryRecordFromOrder($order->fresh());
                }
            }

            // Topup de wallet: creditar saldo do cliente
            if ($pixTransaction->type === 'wallet_topup') {
                $this->walletService->creditTopup(
                    $pixTransaction->client_id,
                    $pixTransaction->supplier_id,
                    (float) $pixTransaction->net_amount,
                    $pixTransaction,
                );

                $this->eventDispatcher->dispatch('wallet.topup', [
                    'client_id'          => $pixTransaction->client_id,
                    'amount'             => $pixTransaction->net_amount,
                    'pix_transaction_id' => $pixTransaction->id,
                ], $pixTransaction->supplier_id);

                return;
            }

            // Pagamento de pedido
            $this->eventDispatcher->dispatch('payment.confirmed', [
                'order_id'           => $pixTransaction->order_id,
                'pix_transaction_id' => $pixTransaction->id,
                'amount'             => $pixTransaction->amount,
            ], $pixTransaction->supplier_id);
        });
    }

    // -------------------------------------------------------------------------
    // payOrdersBatch — batch de pedidos
    // -------------------------------------------------------------------------

    /**
     * Paga multiplos pedidos de uma vez.
     *
     * Cada pedido e tratado independentemente — um erro nao cancela os demais.
     *
     * @param  int[]  $orderIds
     * @return array{paid: int[], pix_required: array[], errors: array[]}
     */
    public function payOrdersBatch(array $orderIds, int $supplierId, int $clientId): array
    {
        $supplier = Supplier::with('paymentSetting')->findOrFail($supplierId);
        $results  = ['paid' => [], 'pix_required' => [], 'errors' => []];

        foreach ($orderIds as $orderId) {
            try {
                $order = Order::findOrFail($orderId);

                if ((int) $order->client_id !== $clientId) {
                    $results['errors'][] = [
                        'order_id' => $orderId,
                        'error'    => 'Pedido nao pertence ao cliente informado',
                    ];
                    continue;
                }

                $result = $this->payOrder($order, $supplier);

                if ($result['status'] === 'paid') {
                    $results['paid'][] = $orderId;
                } else {
                    $results['pix_required'][] = [
                        'order_id'        => $orderId,
                        'pix_needed'      => $result['pix_needed'],
                        'pix_transaction' => $result['pix_transaction'],
                    ];
                }
            } catch (\Throwable $e) {
                $results['errors'][] = [
                    'order_id' => $orderId,
                    'error'    => $e->getMessage(),
                ];

                Log::error('[OrderPaymentService] Erro no batch payment', [
                    'order_id'   => $orderId,
                    'client_id'  => $clientId,
                    'supplier_id'=> $supplierId,
                    'exception'  => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * MUL-280: identifica pedidos cujo canal de envio e Amazon Fulfillment
     * (FBA). Nesse canal a etiqueta e emitida pela Amazon e nao chega ao
     * sistema — o pagamento deve ser liberado assim mesmo para o pedido
     * conseguir sincronizar com o Bling. Amazon DBA NAO entra: DBA tem
     * etiqueta acessivel via marketplace.
     *
     * Reconhece as variantes que o Bling manda em carrier_name/shipping_mode/
     * channel_name: "Amazon Fulfillment", "amazon_fba", "amazon fba".
     */
    public static function isAmazonFulfillment(Order $order): bool
    {
        $needles = [
            'amazon fulfillment', 'amazon_fulfillment',
            'amazon fba', 'amazon_fba', 'amazonfba',
        ];
        foreach (['carrier_name', 'shipping_mode', 'channel_name'] as $col) {
            $val = strtolower((string) ($order->{$col} ?? ''));
            if ($val === '') {
                continue;
            }
            foreach ($needles as $n) {
                if (str_contains($val, $n)) {
                    return true;
                }
            }
        }
        return false;
    }
}
