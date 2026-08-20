<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ClientSupplierBalance;
use App\Models\ClientSupplierTransaction;
use App\Models\Order;
use App\Models\PixTransaction;
use App\Models\Supplier;
use App\Helpers\DocumentValidator;
use App\Services\Financial\AutoPayService;
use App\Services\Integrations\Factories\PaymentGatewayFactory;
use App\Services\Integrations\Payments\ShipayService;
use App\Traits\FormatsMoneyBR;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

class WalletController extends Controller
{
    use FormatsMoneyBR;

    private function clientOrFail(Request $request)
    {
        $client = $request->user()->client;
        if (!$client) {
            abort(403, 'Usuario nao possui perfil de lojista.');
        }
        return $client;
    }

    /**
     * FOR-042: tenta atualizar client->document a partir do body do request.
     * Retorna o document limpo se atualizou com sucesso, ou null caso contrario.
     */
    private function tryUpdateClientDocument($client, Request $request): ?string
    {
        $docRaw = (string) $request->input('document', '');
        if ($docRaw === '') {
            return null;
        }
        $clean = preg_replace('/\D/', '', $docRaw);
        if (!$clean || !DocumentValidator::isValid($clean)) {
            return null;
        }
        $client->document = $clean;
        $client->save();
        return $clean;
    }


    #[OA\Post(
        path: '/api/v1/financial/deposit',
        summary: 'Gerar QR PIX para adicionar saldo na carteira',
        description: 'Gera um QR Code PIX para o lojista depositar saldo. Requer CPF/CNPJ valido no perfil.',
        tags: ['Carteira'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['supplier_id', 'amount'],
                properties: [
                    new OA\Property(property: 'supplier_id', type: 'integer', example: 30),
                    new OA\Property(property: 'amount', type: 'number', description: 'Valor em R$ (min 5, max 10000)', example: 100.00),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'QR Code gerado com sucesso'),
            new OA\Response(response: 422, description: 'Document ausente/invalido ou fornecedor sem gateway'),
        ]
    )]
    public function deposit(Request $request): JsonResponse
    {
        $client = $this->clientOrFail($request);

        // FOR-042: aceita document no body para destravar lojistas migrados sem CPF/CNPJ valido
        $docFromBody = $this->tryUpdateClientDocument($client, $request);
        $rawDoc = $docFromBody ?? ($client->document ?? '');
        $cleanDocDeposit = preg_replace('/\D/', '', $rawDoc);
        if (empty($cleanDocDeposit)) {
            return response()->json([
                'error'   => 'document_required',
                'message' => 'Informe seu CPF/CNPJ para gerar PIX.',
            ], 422);
        }
        if (!DocumentValidator::isValid($cleanDocDeposit)) {
            return response()->json([
                'error'   => 'document_invalid',
                'message' => 'CPF ou CNPJ informado e invalido. Verifique os digitos e tente novamente.',
            ], 422);
        }

        $validated = $request->validate([
            'supplier_id' => 'required|integer|exists:suppliers,id',
            'amount'      => 'required|numeric|min:5|max:10000',
        ]);

        $supplier = Supplier::findOrFail($validated['supplier_id']);

        if (!$supplier->allows_direct_payment) {
            return response()->json(['error' => 'Fornecedor nao aceita pagamento direto.'], 422);
        }

        $gateway = PaymentGatewayFactory::makeForSupplier($supplier);

        if (!($gateway instanceof ShipayService)) {
            return response()->json(['error' => 'Gateway do fornecedor nao suporta wallet topup.'], 422);
        }

        $fee            = (float) ($supplier->pix_fee ?? 0);
        $totalWithFee   = round($validated['amount'] + $fee, 2);
        $idempotencyKey = 'wallet_' . $client->id . '_' . $supplier->id . '_' . Str::random(8);

        $pixTx = PixTransaction::create([
            'supplier_id'     => $supplier->id,
            'client_id'       => $client->id,
            'type'            => 'wallet_topup',
            'gateway'         => 'shipay',
            'amount'          => $validated['amount'],
            'fee_amount'      => $fee,
            'net_amount'      => $validated['amount'],
            'status'          => 'pending',
            'idempotency_key' => $idempotencyKey,
            'expires_at'      => now()->addMinutes(30),
        ]);

        try {
            $qrResult = $gateway->createWalletQrCode(
                $idempotencyKey,
                $totalWithFee,
                $client->company_name ?? 'Cliente',
                preg_replace('/\D/', '', $rawDoc)
            );

            $pixTx->update([
                'qr_code'      => $qrResult['qr_code'] ?? null,
                'qr_code_text' => $qrResult['qr_code_text'] ?? null,
                'external_id'  => $qrResult['external_id'] ?? null,
            ]);
        } catch (\Throwable $e) {
            $pixTx->update(['status' => 'failed']);
            Log::error('[Wallet] Erro ao gerar QR PIX', [
                'client_id'   => $client->id,
                'supplier_id' => $supplier->id,
                'error'       => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Erro ao gerar QR PIX: ' . $e->getMessage()], 500);
        }

        $amount = round($validated['amount'], 2);

        return response()->json([
            'transaction_id'  => $pixTx->id,
            'amount'          => $amount,
            'amount_formatted'=> $this->formatBRL($amount),
            'fee'             => round($fee, 2),
            'fee_formatted'   => $this->formatBRL(round($fee, 2)),
            'total_charged'   => $totalWithFee,
            'total_formatted' => $this->formatBRL($totalWithFee),
            'qr_code'         => $pixTx->qr_code,
            'qr_code_text'    => $pixTx->qr_code_text,
            'expires_at'      => $pixTx->expires_at?->toIso8601String(),
            'status'          => 'pending',
        ], 201);
    }

    #[OA\Get(
        path: '/api/v1/financial/deposit/{id}/status',
        summary: 'Verificar status de um deposito PIX',
        tags: ['Carteira'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Status do deposito'),
            new OA\Response(response: 404, description: 'Deposito nao encontrado'),
        ]
    )]
    /**
     * MUL-452: pergunta ao gateway se o PIX foi pago e credita se foi.
     *
     * Usa o MESMO caminho canonico do postback (WalletLedger com chave de
     * idempotencia por transacao), entao webhook e conferencia manual podem rodar
     * os dois sem creditar duas vezes.
     *
     * Devolve true quando creditou agora.
     */
    private function confereNoGateway(PixTransaction $tx): bool
    {
        try {
            $supplier = Supplier::find($tx->supplier_id);
            if (! $supplier) {
                return false;
            }

            $gateway = PaymentGatewayFactory::makeForSupplier($supplier);
            if ($gateway->getPaymentStatus((string) $tx->external_id) !== 'paid') {
                return false;
            }
        } catch (\Throwable $e) {
            Log::warning('[MUL-452] falha ao conferir o PIX no gateway', [
                'pix_transaction_id' => $tx->id,
                'error'              => $e->getMessage(),
            ]);

            return false;
        }

        return $this->creditaDeposito($tx, ['fonte' => 'conferencia', 'gateway' => $tx->gateway]);
    }

    /**
     * MUL-452: confirma o deposito e credita a carteira.
     *
     * Idempotente pela chave 'pix_topup:{id}' no ledger (MUL-363 Fase 2) -- e o que
     * permite o postback e a conferencia coexistirem sem risco de credito dobrado.
     */
    private function creditaDeposito(PixTransaction $tx, array $payload = []): bool
    {
        if ($tx->status === 'paid') {
            return false;
        }

        $tx->update([
            'status'           => 'paid',
            'paid_at'          => now(),
            'gateway_response' => $payload ?: $tx->gateway_response,
        ]);

        $ledgerTx = app(\App\Services\Financial\Ledger\WalletLedger::class)->credit(
            $tx->client_id, $tx->supplier_id, (float) $tx->net_amount,
            new \App\Services\Financial\Ledger\LedgerEntryMeta(
                type: 'pix_topup',
                description: 'Deposito PIX #' . $tx->id,
                actor: 'system:MUL-452',
                idempotencyKey: 'pix_topup:' . $tx->id,
                pixTransactionId: $tx->id,
                extra: array_merge(['payment_method' => 'pix'], $payload),
            )
        );

        Log::info('[MUL-452] deposito confirmado e creditado', [
            'pix_transaction_id' => $tx->id,
            'client_id'          => $tx->client_id,
            'supplier_id'        => $tx->supplier_id,
            'amount'             => $tx->net_amount,
            'novo_saldo'         => (float) $ledgerTx->running_balance,
        ]);

        return true;
    }

    public function depositStatus(Request $request, int $id): JsonResponse
    {
        $client = $this->clientOrFail($request);

        $tx = PixTransaction::where('client_id', $client->id)
            ->where('type', 'wallet_topup')
            ->findOrFail($id);

        // MUL-452: o botao "conferir" agora CONFERE.
        //
        // Ate aqui ele so devolvia a linha local, entao dizia "pendente" mesmo com o
        // dinheiro ja na Shipay. Isso so funcionava enquanto o postback chegasse -- e
        // ele nunca chegou: zero registros de '[Wallet:Shipay] Webhook recebido' em
        // toda a base de log, com postback_url indo certo no request e a rota
        // respondendo 200 publicamente. Medido em 20/08/2026 no deposito #27
        // (TOTAO STORE, R$ 10,00): Shipay respondia 'paid', a carteira nao tinha
        // credito e o extrato nao mostrava nada.
        //
        // Depender de um aviso que a gente nao controla e o erro; perguntar e barato.
        if ($tx->status === 'pending') {
            $this->confereNoGateway($tx);
            $tx->refresh();
        }

        return response()->json([
            'transaction_id' => $tx->id,
            'status'         => $tx->status,
            'amount'         => (float) $tx->amount,
            'paid_at'        => $tx->paid_at?->toIso8601String(),
            'expires_at'     => $tx->expires_at?->toIso8601String(),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/financial/deposits',
        summary: 'Listar depositos do lojista',
        tags: ['Carteira'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'supplier_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pending', 'paid', 'expired', 'failed'])),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista de depositos paginada'),
        ]
    )]
    public function deposits(Request $request): JsonResponse
    {
        $client = $this->clientOrFail($request);

        $query = PixTransaction::where('client_id', $client->id)
            ->where('type', 'wallet_topup')
            ->with('supplier:id,company_name,display_name')
            ->latest();

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', (int) $request->query('supplier_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $paginator = $query->paginate((int) $request->query('per_page', 15));

        $items = collect($paginator->items())->map(function ($tx) {
            return array_merge($tx->toArray(), [
                'amount_formatted'     => $this->formatBRL((float) $tx->amount),
                'fee_amount_formatted' => $this->formatBRL((float) $tx->fee_amount),
                'net_amount_formatted' => $this->formatBRL((float) $tx->net_amount),
            ]);
        });

        return response()->json([
            'data' => $items,
            'meta' => [
                'total'        => $paginator->total(),
                'per_page'     => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // PARTE 2 - Pagamento por saldo puro
    // -------------------------------------------------------------------------

    #[OA\Post(
        path: '/api/v1/financial/pay-with-balance',
        summary: 'Pagar pedidos usando saldo da carteira',
        description: 'Debita o total dos pedidos do saldo da carteira. Usa lockForUpdate para seguranca.',
        tags: ['Financeiro'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['order_ids', 'supplier_id'],
                properties: [
                    new OA\Property(property: 'order_ids', type: 'array', items: new OA\Items(type: 'integer'), example: [123, 456]),
                    new OA\Property(property: 'supplier_id', type: 'integer', example: 30),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Pedidos pagos com saldo'),
            new OA\Response(response: 422, description: 'Saldo insuficiente ou pedidos invalidos'),
        ]
    )]
    public function payWithBalance(Request $request): JsonResponse
    {
        $client = $this->clientOrFail($request);

        $validated = $request->validate([
            'order_ids'   => 'required|array|min:1',
            'order_ids.*' => 'required|integer',
            'supplier_id' => 'required|integer|exists:suppliers,id',
        ]);

        $supplier = Supplier::findOrFail($validated['supplier_id']);

        $orders = Order::where('client_id', $client->id)
            ->where('supplier_id', $supplier->id)
            ->whereIn('id', $validated['order_ids'])
            ->whereNull('wallet_paid_at')
            ->get();

        if ($orders->count() !== count($validated['order_ids'])) {
            return response()->json([
                'error'   => 'invalid_orders',
                'message' => 'Um ou mais pedidos nao foram encontrados, ja foram pagos ou nao pertencem a este lojista/fornecedor.',
            ], 422);
        }

        // MUL-379: pedido ja enviado sai da cobranca (Order::jaFoiEnviado). Recusa o
        // lote inteiro dizendo QUAIS sao, em vez de cobrar os outros em silencio: o
        // lojista escolheu aqueles pedidos e precisa ver o que mudou.
        $enviados = $orders->filter(fn ($o) => $o->jaFoiEnviado());
        if ($enviados->isNotEmpty()) {
            return response()->json([
                'error'     => 'orders_already_shipped',
                'message'   => 'Pedido ja enviado nao gera cobranca. Remova da selecao: '
                    . $enviados->pluck('order_number')->implode(', '),
                'order_ids' => $enviados->pluck('id')->values(),
            ], 422);
        }

        $total = round($orders->sum('supplier_total'), 2);

        return DB::transaction(function () use ($client, $supplier, $orders, $total) {
            $balance = ClientSupplierBalance::where('client_id', $client->id)
                ->where('supplier_id', $supplier->id)
                ->lockForUpdate()
                ->first();

            $currentBalance = $balance ? (float) $balance->balance : 0.0;

            if ($currentBalance < $total) {
                return response()->json([
                    'error'              => 'insufficient_balance',
                    'balance'            => $currentBalance,
                    'balance_formatted'  => $this->formatBRL($currentBalance),
                    'required'           => $total,
                    'required_formatted' => $this->formatBRL($total),
                    'deficit'            => round($total - $currentBalance, 2),
                ], 422);
            }

            // MUL-363 Fase 2: debito por pedido via nucleo canonico (nada de decrement
            // manual). Idempotencia pelo LEDGER: pedido com debito ativo (soma de
            // debitos - creditos cobre o custo) nao e cobrado de novo, mesmo que o
            // carimbo tenha sido apagado por bug de espelho — restaura o carimbo.
            $ledger = app(\App\Services\Financial\Ledger\WalletLedger::class);
            $transactions = [];
            $paidOrderIds = [];
            $alreadyPaid  = [];

            foreach ($orders as $order) {
                $orderTotal = round((float) $order->supplier_total, 2);

                $net = (float) (ClientSupplierTransaction::where('order_id', $order->id)
                    ->selectRaw("COALESCE(SUM(CASE WHEN type='debit' THEN amount ELSE -amount END),0) s")
                    ->value('s') ?? 0);
                if ($orderTotal > 0 && $net >= $orderTotal - 0.01) {
                    $prevTx = ClientSupplierTransaction::where('order_id', $order->id)
                        ->where('type', 'debit')->orderBy('id')->first();
                    $order->update([
                        'wallet_paid_at'        => $order->wallet_paid_at ?? ($prevTx?->created_at ?? now()),
                        'wallet_transaction_id' => $order->wallet_transaction_id ?? $prevTx?->id,
                    ]);
                    $alreadyPaid[] = $order->id;
                    continue;
                }

                $tx = $ledger->debit($client->id, $supplier->id, $orderTotal,
                    new \App\Services\Financial\Ledger\LedgerEntryMeta(
                        type: 'order_debit',
                        description: 'Pagamento pedido #' . $order->id,
                        orderId: $order->id,
                        actor: 'user:' . auth()->id(),
                        extra: ['flow' => 'pay_with_balance'],
                    )
                );

                $order->update([
                    'wallet_paid_at'        => now(),
                    'wallet_transaction_id' => $tx->id,
                ]);

                if ($order->legacy_id && $client->legacy_id_login) {
                    try {
                        DB::connection('legacy')->table('conta_corrente')->insert([
                            'id_login'    => $client->legacy_id_login,
                            'id_deposito' => $supplier->legacy_empresa_id,
                            'tipo'        => 'D',
                            'valor'       => $orderTotal,
                            'descricao'   => 'Pagamento pedido #' . $order->legacy_id . ' via HubAI Wallet',
                            'id_pedido'   => $order->legacy_id,
                            'data_add'    => now(),
                        ]);
                    } catch (\Throwable $e) {
                        Log::warning('[Wallet] Erro conta_corrente legado', [
                            'order_id' => $order->id,
                            'error'    => $e->getMessage(),
                        ]);
                    }
                }

                $transactions[] = [
                    'id'               => $tx->id,
                    'order_id'         => $order->id,
                    'amount'           => $orderTotal,
                    'amount_formatted' => $this->formatBRL($orderTotal),
                ];
                $paidOrderIds[] = $order->id;
            }

            $newBalance = round((float) $balance->fresh()->balance, 2);

            Log::info('[Wallet] Pedidos pagos com saldo', [
                'client_id'   => $client->id,
                'supplier_id' => $supplier->id,
                'order_ids'   => $paidOrderIds,
                'total'       => $total,
                'new_balance' => $newBalance,
            ]);

            return response()->json([
                'paid_orders'             => $paidOrderIds,
                // MUL-363: pedidos que o ledger provou ja pagos — carimbo restaurado, sem novo debito
                'already_paid'            => $alreadyPaid,
                'total_debited'           => $total,
                'total_debited_formatted' => $this->formatBRL($total),
                'new_balance'             => $newBalance,
                'new_balance_formatted'   => $this->formatBRL($newBalance),
                'transactions'            => $transactions,
            ]);
        });
    }

    // -------------------------------------------------------------------------
    // PARTE 3 - Pagamento parcial (PIX + saldo)
    // -------------------------------------------------------------------------

    #[OA\Post(
        path: '/api/v1/financial/pay-partial',
        summary: 'Pagar pedidos com saldo + PIX para o restante',
        description: 'Usa o saldo disponivel e gera PIX pelo restante. Se saldo cobrir tudo, redireciona para pay-with-balance.',
        tags: ['Financeiro'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['order_ids', 'supplier_id'],
                properties: [
                    new OA\Property(property: 'order_ids', type: 'array', items: new OA\Items(type: 'integer'), example: [123, 456]),
                    new OA\Property(property: 'supplier_id', type: 'integer', example: 30),
                    new OA\Property(property: 'use_balance', type: 'boolean', example: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'PIX gerado com uso parcial do saldo'),
            new OA\Response(response: 422, description: 'Pedidos invalidos ou gateway indisponivel'),
        ]
    )]
    public function payPartial(Request $request): JsonResponse
    {
        $client = $this->clientOrFail($request);

        $validated = $request->validate([
            'order_ids'   => 'required|array|min:1',
            'order_ids.*' => 'required|integer',
            'supplier_id' => 'required|integer|exists:suppliers,id',
            'use_balance' => 'sometimes|boolean',
        ]);

        $useBalance = $validated['use_balance'] ?? true;
        $supplier   = Supplier::findOrFail($validated['supplier_id']);

        $orders = Order::where('client_id', $client->id)
            ->where('supplier_id', $supplier->id)
            ->whereIn('id', $validated['order_ids'])
            ->whereNull('wallet_paid_at')
            ->get();

        if ($orders->count() !== count($validated['order_ids'])) {
            return response()->json([
                'error'   => 'invalid_orders',
                'message' => 'Um ou mais pedidos nao foram encontrados, ja foram pagos ou nao pertencem a este lojista/fornecedor.',
            ], 422);
        }

        // MUL-379: pedido ja enviado sai da cobranca (Order::jaFoiEnviado). Recusa o
        // lote inteiro dizendo QUAIS sao, em vez de cobrar os outros em silencio: o
        // lojista escolheu aqueles pedidos e precisa ver o que mudou.
        $enviados = $orders->filter(fn ($o) => $o->jaFoiEnviado());
        if ($enviados->isNotEmpty()) {
            return response()->json([
                'error'     => 'orders_already_shipped',
                'message'   => 'Pedido ja enviado nao gera cobranca. Remova da selecao: '
                    . $enviados->pluck('order_number')->implode(', '),
                'order_ids' => $enviados->pluck('id')->values(),
            ], 422);
        }

        $total = round($orders->sum('supplier_total'), 2);

        $balanceRecord  = ClientSupplierBalance::where('client_id', $client->id)
            ->where('supplier_id', $supplier->id)
            ->first();
        $currentBalance = $balanceRecord ? (float) $balanceRecord->balance : 0.0;

        if ($useBalance && $currentBalance >= $total) {
            return $this->payWithBalance($request);
        }

        // FOR-042: aceita document no body para destravar lojistas migrados sem CPF/CNPJ valido
        $docFromBodyPartial = $this->tryUpdateClientDocument($client, $request);
        $rawDoc = $docFromBodyPartial ?? ($client->document ?? '');
        $cleanDocPartial = preg_replace('/\D/', '', $rawDoc);
        if (empty($cleanDocPartial)) {
            return response()->json([
                'error'   => 'document_required',
                'message' => 'Informe seu CPF/CNPJ para gerar PIX.',
            ], 422);
        }
        if (!DocumentValidator::isValid($cleanDocPartial)) {
            return response()->json([
                'error'   => 'document_invalid',
                'message' => 'CPF ou CNPJ informado e invalido. Verifique os digitos e tente novamente.',
            ], 422);
        }

        $gateway = PaymentGatewayFactory::makeForSupplier($supplier);
        if (!($gateway instanceof ShipayService)) {
            return response()->json(['error' => 'Gateway do fornecedor nao suporta PIX.'], 422);
        }

        $balanceUsed = $useBalance ? min($currentBalance, $total) : 0.0;
        $pixAmount   = round($total - $balanceUsed, 2);
        $fee         = (float) ($supplier->pix_fee ?? 0);
        $totalPix    = round($pixAmount + $fee, 2);

        return DB::transaction(function () use (
            $client, $supplier, $orders, $balanceUsed,
            $pixAmount, $fee, $totalPix, $rawDoc, $gateway, $validated
        ) {
            if ($balanceUsed > 0) {
                // MUL-363 Fase 2: perna de saldo do pagamento parcial via nucleo canonico
                app(\App\Services\Financial\Ledger\WalletLedger::class)->debit(
                    $client->id, $supplier->id, $balanceUsed,
                    new \App\Services\Financial\Ledger\LedgerEntryMeta(
                        type: 'order_debit',
                        description: 'Debito parcial para pagamento de ' . count($validated['order_ids']) . ' pedido(s)',
                        actor: 'user:' . auth()->id(),
                        extra: ['flow' => 'pay_partial', 'order_ids' => $validated['order_ids']],
                    )
                );
            }

            $idempotencyKey = 'partial_' . $client->id . '_' . $supplier->id . '_' . Str::random(8);

            $pixTx = PixTransaction::create([
                'supplier_id'     => $supplier->id,
                'client_id'       => $client->id,
                'type'            => 'order_payment',
                'gateway'         => 'shipay',
                'amount'          => $pixAmount,
                'fee_amount'      => $fee,
                'net_amount'      => $pixAmount,
                'balance_used'    => $balanceUsed,
                'order_ids'       => $orders->pluck('id')->toArray(),
                'status'          => 'pending',
                'idempotency_key' => $idempotencyKey,
                'expires_at'      => now()->addMinutes(30),
            ]);

            try {
                $qrResult = $gateway->createWalletQrCode(
                    $idempotencyKey,
                    $totalPix,
                    $client->company_name ?? 'Cliente',
                    preg_replace('/\D/', '', $rawDoc)
                );

                $pixTx->update([
                    'qr_code'      => $qrResult['qr_code'] ?? null,
                    'qr_code_text' => $qrResult['qr_code_text'] ?? null,
                    'external_id'  => $qrResult['external_id'] ?? null,
                ]);
            } catch (\Throwable $e) {
                $pixTx->update(['status' => 'failed']);
                Log::error('[Wallet] Erro QR PIX parcial', [
                    'client_id'   => $client->id,
                    'supplier_id' => $supplier->id,
                    'error'       => $e->getMessage(),
                ]);
                return response()->json(['error' => 'Erro ao gerar QR PIX: ' . $e->getMessage()], 500);
            }

            Log::info('[Wallet] PIX parcial gerado', [
                'client_id'    => $client->id,
                'supplier_id'  => $supplier->id,
                'order_ids'    => $orders->pluck('id')->toArray(),
                'balance_used' => $balanceUsed,
                'pix_amount'   => $pixAmount,
            ]);

            return response()->json([
                'balance_used'                => round($balanceUsed, 2),
                'balance_used_formatted'      => $this->formatBRL(round($balanceUsed, 2)),
                'pix_amount'                  => $pixAmount,
                'pix_amount_formatted'        => $this->formatBRL($pixAmount),
                'pix_fee'                     => round($fee, 2),
                'pix_fee_formatted'           => $this->formatBRL(round($fee, 2)),
                'total_pix_charged'           => $totalPix,
                'total_pix_charged_formatted' => $this->formatBRL($totalPix),
                'qr_code'                     => $pixTx->qr_code,
                'qr_code_text'                => $pixTx->qr_code_text,
                'transaction_id'              => $pixTx->id,
                'orders_pending_pix'          => $orders->pluck('id')->toArray(),
                'expires_at'                  => $pixTx->expires_at?->toIso8601String(),
            ]);
        });
    }

    // -------------------------------------------------------------------------
    // PARTE 5 - Webhook Shipay completo
    // -------------------------------------------------------------------------

    public function webhookShipay(Request $request): JsonResponse
    {
        $rawBody   = $request->getContent();
        $sigHeader = $request->header('X-Shipay-Signature', '');

        // FOR-038: Validar HMAC antes de qualquer processamento
        if ($sigHeader !== '') {
            $settings = \App\Models\SupplierPaymentSetting::where('gateway', 'shipay')
                ->where('is_active', 1)
                ->first();
            if ($settings && $settings->webhook_secret) {
                $shipay = new \App\Services\Integrations\Payments\ShipayService();
                if (!$shipay->verifyWebhookSignature($rawBody, $sigHeader, $settings->webhook_secret)) {
                    Log::warning('[Wallet:Shipay] HMAC invalido - webhook rejeitado', ['sig' => substr($sigHeader, 0, 16)]);
                    return response()->json(['error' => 'invalid_signature'], 401);
                }
            }
        }

        $payload = $request->all();
        $orderId = $payload['order_id'] ?? '';
        $status  = $payload['status'] ?? '';

        Log::info('[Wallet:Shipay] Webhook recebido', ['order_id' => $orderId, 'status' => $status]);

        if ($status !== 'approved') {
            return response()->json(['received' => true]);
        }

        $tx = PixTransaction::where('external_id', $orderId)
            ->whereIn('type', ['wallet_topup', 'order_payment'])
            ->where('status', 'pending')
            ->first();

        if (!$tx) {
            Log::warning('[Wallet:Shipay] Transaction nao encontrada', ['order_id' => $orderId]);
            return response()->json(['received' => true]);
        }

        // Double-check na Shipay
        try {
            $supplierModel = Supplier::find($tx->supplier_id);
            if ($supplierModel) {
                $gateway = PaymentGatewayFactory::makeForSupplier($supplierModel);
                if ($gateway instanceof ShipayService) {
                    $remoteStatus = $gateway->getPaymentStatus($orderId);
                    if ($remoteStatus !== 'paid') {
                        Log::warning('[Wallet:Shipay] Double-check falhou', [
                            'order_id'      => $orderId,
                            'remote_status' => $remoteStatus,
                        ]);
                        return response()->json(['received' => true]);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('[Wallet:Shipay] Double-check erro - abortando credito', ['error' => $e->getMessage()]);
            return response()->json(['received' => true]);
        }

        $tx->update([
            'status'           => 'paid',
            'paid_at'          => now(),
            'gateway_response' => $payload,
        ]);

        if ($tx->type === 'wallet_topup') {
            // MUL-363 Fase 2: credito via nucleo canonico. Idempotente por PIX —
            // reentrega do webhook Shipay NAO credita duas vezes (antes creditava!).
            $ledgerTx = app(\App\Services\Financial\Ledger\WalletLedger::class)->credit(
                $tx->client_id, $tx->supplier_id, (float) $tx->net_amount,
                new \App\Services\Financial\Ledger\LedgerEntryMeta(
                    type: 'pix_topup',
                    description: 'Deposito PIX #' . $tx->id,
                    actor: 'system:ShipayWebhook',
                    idempotencyKey: 'pix_topup:' . $tx->id,
                    pixTransactionId: $tx->id,
                    extra: ['payment_method' => 'pix', 'gateway' => 'shipay'],
                )
            );

            Log::info('[Wallet:Shipay] Saldo creditado', [
                'client_id'   => $tx->client_id,
                'supplier_id' => $tx->supplier_id,
                'amount'      => $tx->net_amount,
                'new_balance' => (float) $ledgerTx->running_balance,
            ]);

            // Nota: dividas registradas pelo AuditUnpaidOrdersJob ficam como saldo negativo.
            // Quando o deposito cobre (saldo >= 0), o StatusTransitioner libera automaticamente
            // a aceitacao dos pedidos. Nao e necessario reprocessar — o saldo e a fonte de verdade.

            return response()->json(['received' => true, 'credited' => true]);
        }

        if ($tx->type === 'order_payment' && !empty($tx->order_ids)) {
            $orderIds = is_array($tx->order_ids) ? $tx->order_ids : json_decode($tx->order_ids, true);

            foreach ($orderIds as $oid) {
                $order = Order::find($oid);
                if (!$order || $order->wallet_paid_at) continue;

                // MUL-363 Fase 2: o codigo antigo criava um DEBITO sem mexer no saldo —
                // linha de ledger orfã que violava o invariante saldo == SUM(ledger).
                // Modelo novo: PAR liquido-zero via nucleo (entrada do PIX + debito do
                // pedido), idempotente por (pix, pedido). Saldo final identico (zero
                // efeito), invariante preservado, rastreabilidade completa.
                $amount = round((float) $order->supplier_total, 2);
                $txRecord = null;
                if ($amount > 0) {
                    $ledger = app(\App\Services\Financial\Ledger\WalletLedger::class);
                    $ledger->credit($tx->client_id, $tx->supplier_id, $amount,
                        new \App\Services\Financial\Ledger\LedgerEntryMeta(
                            type: 'pix_payment_in',
                            description: 'PIX recebido pedido #' . $order->id,
                            orderId: $order->id,
                            actor: 'system:ShipayWebhook',
                            idempotencyKey: 'pix_in:' . $tx->id . ':' . $order->id,
                            pixTransactionId: $tx->id,
                            extra: ['payment_method' => 'pix', 'gateway' => 'shipay'],
                        )
                    );
                    $txRecord = $ledger->debit($tx->client_id, $tx->supplier_id, $amount,
                        new \App\Services\Financial\Ledger\LedgerEntryMeta(
                            type: 'order_debit',
                            description: 'Pagamento PIX pedido #' . $order->id,
                            orderId: $order->id,
                            actor: 'system:ShipayWebhook',
                            idempotencyKey: 'pix_charge:' . $tx->id . ':' . $order->id,
                            pixTransactionId: $tx->id,
                            extra: ['payment_method' => 'pix', 'gateway' => 'shipay'],
                        )
                    );
                }

                $order->update([
                    'wallet_paid_at'        => now(),
                    'wallet_transaction_id' => $txRecord?->id,
                    'status'               => 'paid',  // FOR-052: setar status+paid_at ao confirmar
                    'paid_at'              => $order->paid_at ?? now(),
                ]);

                if ($order->legacy_id && $order->client?->legacy_id_login) {
                    try {
                        $sup = Supplier::find($tx->supplier_id);
                        DB::connection('legacy')->table('conta_corrente')->insert([
                            'id_login'    => $order->client->legacy_id_login,
                            'id_deposito' => $sup?->legacy_empresa_id,
                            'tipo'        => 'D',
                            'valor'       => (float) $order->supplier_total,
                            'descricao'   => 'Pagamento PIX pedido #' . $order->legacy_id . ' via HubAI Wallet',
                            'id_pedido'   => $order->legacy_id,
                            'data_add'    => now(),
                        ]);
                    } catch (\Throwable $e) {
                        Log::warning('[Wallet] Erro conta_corrente legado', [
                            'order_id' => $order->id,
                            'error'    => $e->getMessage(),
                        ]);
                    }
                }
            }

            Log::info('[Wallet:Shipay] Pedidos marcados como pagos via PIX', [
                'tx_id'     => $tx->id,
                'order_ids' => $orderIds,
            ]);
        }

        return response()->json(['received' => true, 'paid' => true]);
    }
}
