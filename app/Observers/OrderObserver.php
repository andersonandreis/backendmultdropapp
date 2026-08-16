<?php

namespace App\Observers;

use App\Models\Order;
use App\Models\Payment;
use App\Enums\OrderStatus;
use App\Services\ClientWalletService;
use App\Services\Financial\SupplierWalletService;
use App\Models\MarketplaceAccount;
use App\Jobs\IssueSellerInvoiceJob;
use App\Jobs\FetchShippingLabelJob;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\OrderStatusHistory;
use Illuminate\Support\Str;

class OrderObserver
{
    /**
     * INF-053 letra C: static registry pra guard cross-webhook.
     * HubAIOrderWebhookController marca $id=true antes de save; Observer
     * detecta e nao dispara fanout de volta. Attribute no model NAO funciona
     * (Eloquent tenta persistir como coluna).
     */
    public static array $syncingFromHub = [];

    /** MUL-363 evento "pedido pago": delay opcional do Bling sync por pedido (fila lenta MUL-277) */
    public static array $blingSyncDelaySeconds = [];

    /**
     * Status que disparam baixa de estoque (NOV-108).
     * Mapeados para garantir que o decremento ocorre na primeira transicao
     * para qualquer um desses status.
     */
    private const STOCK_DEDUCT_STATUSES = ['paid', 'shipped', 'delivered', 'completed'];

    /**
     * Handle the Order "created" event.
     */
    /**
     * FOR-127: carimba o nome do seller da WL no proprio pedido.
     *
     * Ponto UNICO de proposito: existem 6 caminhos que criam pedido de marketplace
     * (WebhookOrderService, SyncMLOrdersJob, ProcessMLOrderJob, SyncShopeeOrdersJob,
     * SyncTikTokOrdersJob, ImportMarketplaceAccountDataJob) e patch em cada um deles
     * ja falhou duas vezes hoje (FOR-103, FOR-124). Aqui pega todos.
     *
     * So preenche quando esta vazio -> idempotente, e nunca sobrescreve valor vindo
     * do payload da federacao.
     */
    public function creating(Order $order): void
    {
        if (! empty($order->wl_seller_name)
            || (empty($order->marketplace_account_id) && empty($order->client_id))) {
            return;
        }

        $nome = \App\Models\MarketplaceAccount::withoutGlobalScopes()
            ->whereKey($order->marketplace_account_id)
            ->value('wl_client_name');

        // quando a conta nao tem (seller com cliente no proprio hub), usa o cliente
        if (! $nome && $order->client_id) {
            $nome = \App\Models\Client::withoutGlobalScopes()
                ->whereKey($order->client_id)
                ->with('user:id,name,full_name')
                ->first()?->user?->name;
        }

        if ($nome) {
            $order->wl_seller_name = mb_substr(trim($nome), 0, 191);
        }
    }

    public function created(Order $order): void
    {
        // Quando criar um pedido, avisar o supplier
        // Reduzir estoque virtual em "reservados"

        // MUL-363 EVENTO 1 tambem na criacao: pedido que JA NASCE pagavel
        // (etiqueta presente / enviado / FBA) dispara o autopay — cobre pedido
        // espelhado/importado que chega completo. Politica no AutoPayService.
        if (! $order->is_draft && ! $order->wallet_paid_at && (float) $order->supplier_total > 0) {
            $nasceuPagavel = $order->label_url || $order->manual_label_path
                || in_array($order->status, ['shipped', 'delivered'], true)
                || preg_match('/fulfillment|fba/i', trim(($order->carrier_name ?? '') . ' ' . ($order->shipping_mode ?? '') . ' ' . ($order->channel_name ?? '')));
            if ($nasceuPagavel) {
                \App\Jobs\TryAutoPayJob::dispatch($order->id)->onQueue('default');
            }
        }

        // MUL-197: pedido-RASCUNHO nao dispara fanout nem efeitos — o fanout
        // order.created acontece na PROMOCAO (DraftOrderPromoter). Sem isso, a
        // casca do hub seria relayada pra WL via FanoutOrderWebhookJob (+30s).
        if ($order->is_draft) {
            $this->writeAudit($order, 'created', null, 'draft');
            // MUL-310 (decisao do Ruan 31/07): rascunho tambem espelha. Ficar em rascunho
            // enquanto o pedido nao esta completo e correto, mas ele NAO pode ficar oculto
            // do fornecedor. A WL cria como rascunho tambem; quando o hub mandar os itens,
            // o DraftOrderPromoter promove dos dois lados. Efeitos colaterais (etiqueta,
            // desconto) seguem suprimidos — so o espelho e liberado.
            $this->fanoutWebhook($order, 'order.created', ['is_draft' => true, 'draft_reason' => $order->draft_reason]);
            return;
        }

        // MUL-202: rede de seguranca — se chegou aqui com is_draft=0 mas faltando
        // dados criticos (supplier_total<=0 ou customer_name vazio ou total<=0),
        // forca rascunho e aborta efeitos colaterais. Cobre qualquer caminho de
        // criacao que nao passou pelo DraftOrderPromoter.
        $needsSafetyNet = (float) ($order->supplier_total ?? 0) <= 0
            || trim((string) ($order->customer_name ?? '')) === ''
            || (float) ($order->total ?? 0) <= 0;

        if ($needsSafetyNet && ! $order->wallet_paid_at) {
            $reason = match(true) {
                in_array($order->source ?? '', ['bling', 'hub_webhook'], true) => 'awaiting_hub_relay',
                ($order->source ?? '') === 'shopee' => 'observer_safety_net',
                default => 'observer_safety_net',
            };
            $order->is_draft     = true;
            $order->draft_reason = $reason;
            $order->saveQuietly();
            $this->writeAudit($order, 'created', null, 'draft_safety_net', [
                'reason'         => $reason,
                'supplier_total' => $order->supplier_total,
                'customer_name'  => $order->customer_name,
                'total'          => $order->total,
            ]);
            // MUL-310: idem — a rede de seguranca segura os efeitos colaterais, nao o espelho.
            $this->fanoutWebhook($order, 'order.created', ['is_draft' => true, 'draft_reason' => $reason]);
            \Illuminate\Support\Facades\Log::info('[MUL-202] OrderObserver safety net ativada', [
                'order_id'       => $order->id,
                'source'         => $order->source,
                'reason'         => $reason,
                'supplier_total' => $order->supplier_total,
            ]);
            return;
        }

        // Audit log (Supplier Core / Fase 3 M2.4) â registra criacao.
        $this->writeAudit($order, 'created', null, $order->canonical_status ?? 'created');
        $this->fanoutWebhook($order, 'order.created');
        $this->trackDiscountSale($order);

        // NOV-207: baixa etiqueta na CRIACAO do pedido (antes do pagamento).
        // Trigger 'order_created' bypassa Gate 2a (wallet_paid_at) e Gate 2 (status paid).
        // Delay 30s da tempo do fanout gravar label_url se veio via webhook interno.
        FetchShippingLabelJob::dispatch($order->id, 'order_created')->delay(now()->addSeconds(30));
    }

    /**
     * INF-052: Guard financeiro — bloqueia marcacao de wallet_paid_at
     * quando qualquer item do pedido nao tem supplier_unit_cost preenchido.
     *
     * Regra Ruan 15/07: pedidos sem custo do produto NUNCA sao marcados como
     * pagos. Foco no bug tenant=fornecefy onde payload chegava sem custo e o
     * hub aceitava marcar wallet_paid_at (51 pedidos JT subsidiados).
     */
    public function updating(Order $order): void
    {
        if (!$order->isDirty("wallet_paid_at")) {
            return;
        }
        if ($order->getOriginal("wallet_paid_at") !== null) {
            return;
        }
        if ($order->wallet_paid_at === null) {
            return;
        }
        // INF-052 fase C: tambem bloquear pedido SEM items (casca vazia).
        // Backfill INF-049 marcou wallet_paid_at em pedidos que fornecefy considerava pagos,
        // sem verificar se items existiam localmente no hub. Consequencia: 31 pedidos JT
        // pagos sem informacao de produto — Ruan viu na tela e nao entendeu.
        $itemsCount = $order->items()->count();
        if ($itemsCount === 0) {
            Log::channel("marketplace")->warning("[INF-052 C] wallet_paid_at bloqueado: pedido sem items", [
                "order_id" => $order->id,
                "order_number" => $order->order_number,
                "tenant_slug" => $order->tenant_slug,
                "supplier_id" => $order->supplier_id,
            ]);
            throw new \RuntimeException(
                "INF-052 fase C guard: pedido #{$order->id} nao tem items (casca vazia). ".
                "wallet_paid_at nao pode ser marcado. Aguarde items chegarem via webhook."
            );
        }

        $missingCostItems = $order->items()
            ->where(function ($q) {
                $q->whereNull("supplier_unit_cost")
                  ->orWhere("supplier_unit_cost", "<=", 0);
            })
            ->exists();
        if ($missingCostItems) {
            Log::channel("marketplace")->warning("[INF-052] wallet_paid_at bloqueado: item sem supplier_unit_cost", [
                "order_id" => $order->id,
                "order_number" => $order->order_number,
                "tenant_slug" => $order->tenant_slug,
                "supplier_id" => $order->supplier_id,
            ]);
            throw new \RuntimeException(
                "INF-052 guard: pedido #{$order->id} tem item sem supplier_unit_cost. ".
                "wallet_paid_at nao pode ser marcado. Corrija o custo antes."
            );
        }
    }

    /**
     * Handle the Order "updated" event.
     */
    public function updated(Order $order): void
    {
        // ===== MUL-363: DOIS EVENTOS INTERNOS UNICOS (decisao Ruan 11/08) =====
        // Ficam ANTES do guard de sync-do-hub de proposito: etiqueta que chega
        // pelo ESPELHO e justamente o gatilho principal do autopay. Loop e
        // impossivel: o autopay grava wallet_paid_at em save local (nao-hub) e o
        // nucleo e idempotente (auto_pay:order:<id> UNIQUE).

        // EVENTO 1 — "ficou pagavel" (etiqueta disponivel / enviado / FBA) → autopay.
        // UNICO ponto de disparo do autopay no sistema; a politica mora no AutoPayService.
        if (! $order->is_draft && ! $order->wallet_paid_at && (float) $order->supplier_total > 0) {
            $ficouPagavel =
                ($order->wasChanged('label_url') && $order->label_url) ||
                ($order->wasChanged('manual_label_path') && $order->manual_label_path) ||
                ($order->wasChanged('status') && in_array($order->status, ['shipped', 'delivered'], true)) ||
                (($order->wasChanged('carrier_name') || $order->wasChanged('shipping_mode') || $order->wasChanged('channel_name'))
                    && preg_match('/fulfillment|fba/i', trim(($order->carrier_name ?? '') . ' ' . ($order->shipping_mode ?? '') . ' ' . ($order->channel_name ?? ''))));
            if ($ficouPagavel) {
                \App\Jobs\TryAutoPayJob::dispatch($order->id)->onQueue('default');
            }
        }

        // EVENTO 2 — "pedido pago" (QUALQUER caminho: seller, autopay, forcada, PIX,
        // pagamento externo) → ganchos pos-pagamento num lugar so. O SyncOrderToBlingJob
        // encadeia a NF-e automatica ('paid') internamente. O delay opcional preserva a
        // fila lenta da MUL-277 (lote de forcadas seta o offset antes de cobrar).
        if ($order->wasChanged('wallet_paid_at') && $order->wallet_paid_at && ! $order->is_draft) {
            $delay = (int) (self::$blingSyncDelaySeconds[$order->id] ?? 0);
            unset(self::$blingSyncDelaySeconds[$order->id]);
            \App\Jobs\SyncOrderToBlingJob::dispatch($order->id, 'paid')
                ->onQueue('default')
                ->delay($delay > 0 ? now()->addSeconds($delay) : null);
        }

        // INF-053 letra C: se este save veio de sync do hub (HubAIOrderWebhookController
        // handlePayload marca em self::$syncingFromHub antes de salvar), NAO disparar
        // fanout — evita loop cross-webhook quando WL aplica update recebido do hub.
        if (!empty(self::$syncingFromHub[$order->id])) {
            return;
        }

        // MUL-197: rascunho NAO dispara efeitos colaterais (fanout, etiqueta, NF,
        // baixa de estoque, debito/reembolso de wallet). Efeitos rodam apenas
        // depois da promocao (is_draft=0). A promocao em si usa saveQuietly e
        // dispara fanout order.created + AutoPay explicitamente no promoter.
        if ($order->is_draft) {
            return;
        }

        // INF-053 letra C: fanout order.updated em mudancas de campos sync-criticos
        // que hoje NAO disparavam (Observer::updated so olhava canonical_status).
        // Foco em campos que dropshipper/fornecedor precisa ver espelhado nas WLs:
        // wallet_paid_at, wallet_transaction_id, paid_at, label_url, supplier_total.
        // Evita dupla emissao: se canonical_status TAMBEM mudou, o branch abaixo
        // ja dispara status_changed. Sem canonical_status dirty -> dispara updated.
        $syncFields = [
            'wallet_paid_at', 'wallet_transaction_id', 'paid_at',
            'label_url', 'supplier_total', 'label_printed_at',
            'tracking_number', 'shipped_at', 'delivered_at', 'cancelled_at',
            // MUL-252: NF-e gravada no hub precisa espelhar nas WLs
            'invoice_number', 'invoice_access_key', 'invoice_url',
            'nfe_entrada_status', 'nfe_entrada_access_key',
        ];
        if ($order->isDirty($syncFields) && !$order->isDirty('canonical_status')) {
            $dirty = collect($syncFields)->filter(fn($f) => $order->isDirty($f))->values()->all();
            $this->fanoutWebhook($order, 'order.updated', ['dirty_fields' => $dirty]);
        }

        // Audit log (Supplier Core / Fase 3 M2.4) — registra transicao canonical_status.
        if ($order->isDirty('canonical_status')) {
            $this->writeAudit(
                $order,
                'status_change',
                $order->getOriginal('canonical_status'),
                $order->canonical_status
            );
            $this->fanoutWebhook($order, 'order.status_changed', [
                'from_status' => $order->getOriginal('canonical_status'),
                'to_status'   => $order->canonical_status,
            ]);
            // MES-046-B: gravar historico auditavel de status
            OrderStatusHistory::record(
                $order,
                'canonical_status',
                $order->getOriginal('canonical_status'),
                $order->canonical_status,
                'observer'
            );
        }

        // MES-046-B: historico de order_processing_status
        if ($order->isDirty('order_processing_status')) {
            OrderStatusHistory::record(
                $order,
                'order_processing_status',
                $order->getOriginal('order_processing_status'),
                $order->order_processing_status,
                'observer'
            );
        }

        if ($order->isDirty('status')) {
            $newStatus = $order->status;

            // --- NOV-108: Baixa automatica de estoque quando pedido e pago/enviado ---
            // Guard de idempotencia: stock_decremented_at garante que e feito exatamente uma vez.
            if (
                in_array($newStatus, self::STOCK_DEDUCT_STATUSES, true)
                && is_null($order->stock_decremented_at)
            ) {
                $this->decrementInventory($order);
            }

            // --- Debito automatico quando pedido e marcado como pago ---
            if ($newStatus === OrderStatus::PAID->value && !$order->getOriginal('paid_at')) {
                // Guard de idempotencia: pedidos manuais pagos via wallet ja foram debitados
                // diretamente no ManualOrderController — nao debitar novamente aqui
                $alreadyDebitedViaWallet = ($order->source === 'manual')
                    && Payment::where('order_id', $order->id)
                        ->where('gateway', 'wallet')
                        ->where('status', 'paid')
                        ->exists();

                if ($alreadyDebitedViaWallet) {
                    return;
                }

                try {
                    $walletService = app(ClientWalletService::class);

                    // Calcular supplier_total somando os items (mais preciso que o campo direto)
                    $supplierTotal = $order->items->sum(fn($item) => (float) $item->supplier_total_cost);

                    // Se items nao carregados ou soma zero, usa o campo direto do pedido
                    if ($supplierTotal <= 0) {
                        $supplierTotal = (float) $order->supplier_total;
                    }

                    if ($supplierTotal > 0) {
                        $valorDebitado = $walletService->debitAvailable(
                            $order->client_id,
                            $order->supplier_id,
                            $supplierTotal,
                            "Debito Pedido #{$order->order_number}",
                            $order->id
                        );

                        // Se debitou menos do que o total (saldo insuficiente), marca como payment_pending
                        if ($valorDebitado < $supplierTotal) {
                            $order->order_processing_status = 'payment_pending';
                            $order->saveQuietly();

                            Log::info("Pedido #{$order->order_number}: saldo insuficiente. Debitado R$ {$valorDebitado} de R$ {$supplierTotal}. order_processing_status => payment_pending.");
                        } else {
                            Log::info("Pedido #{$order->order_number}: debito total de R$ {$valorDebitado} realizado. order_processing_status mantido.");
                        }

                        // NOV-157: creditar supplier pelo valor debitado do lojista
                        if ($valorDebitado > 0 && $order->supplier_id) {
                            try {
                                app(SupplierWalletService::class)->creditOrderSale(
                                    $order->supplier_id,
                                    $valorDebitado,
                                    $order
                                );
                            } catch (\Exception $e) {
                                Log::error("Falha ao creditar SupplierWallet no pagamento do Pedido {$order->id}. Erro: " . $e->getMessage());
                            }
                        }
                    }
                } catch (\Exception $e) {
                    Log::error("Falha ao debitar na Wallet no pagamento do Pedido {$order->id}. Erro: " . $e->getMessage());
                }
            }


            // --- NOV-166-D: Emissao automatica de NF-e quando pedido e pago ---
            // MUL-084: Etiqueta automatica ao pagar — evita clique manual no botao Gerar Etiqueta
            if ($newStatus === OrderStatus::PAID->value && empty($order->label_url)) {
                try {
                    FetchShippingLabelJob::dispatch($order->id)->delay(now()->addSeconds(10));
                    Log::info('[OrderObserver] FetchShippingLabelJob auto-disparado apos PAID', [
                        'order_id' => $order->id,
                        'source'   => $order->source,
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('[OrderObserver] Falha ao auto-disparar etiqueta', [
                        'order_id' => $order->id,
                        'error'    => $e->getMessage(),
                    ]);
                }
            }

            // Dispara IssueSellerInvoiceJob se o seller tem Bling com auto_invoice_enabled=1
            if ($newStatus === OrderStatus::PAID->value) {
                $hasBlingAuto = MarketplaceAccount::where('client_id', $order->client_id)
                    ->where('platform', 'bling')
                    ->where('status', 'active')
                    ->where('auto_invoice_enabled', 1)
                    ->exists();

                if ($hasBlingAuto) {
                    IssueSellerInvoiceJob::dispatch($order->id);
                    Log::info("[OrderObserver] IssueSellerInvoiceJob disparado", [
                        'order_id' => $order->id,
                    ]);
                }
            }

            // --- Reembolso na Carteira do Lojista (Devolucao / Cancelamento de pedido ja pago) ---
            if (
                ($newStatus === OrderStatus::RETURNED->value || $newStatus === OrderStatus::CANCELLED->value)
                // MUL-371: wallet_paid_at tambem conta — pedido importado do Bling pode ter sido
                // cobrado na wallet sem paid_at de marketplace (caso Marcela 57638: cobranca
                // forcada em copia duplicada, cancelamento nao estornava)
                && ($order->paid_at || $order->wallet_paid_at)
            ) {
                try {
                    $walletService = app(ClientWalletService::class);

                    // MUL-362 F1 / NOV-214: ESTORNO SEGUE O LEDGER LOCAL.
                    // So devolve o que ESTA wallet debitou de fato (debitos - creditos do
                    // pedido no ledger local), limitado ao supplier_total. Consequencias:
                    // - espelho de pedido de WL no hub NAO credita o bolso do hub (era o
                    //   vazamento que encheu as carteiras invisiveis — MUL-362 causa 3);
                    // - estorno em dobro entre backends fica impossivel;
                    // - observer disparado 2x nao credita 2x (idempotency key por pedido).
                    $netLocal = (float) \App\Models\ClientSupplierTransaction::where('order_id', $order->id)
                        ->selectRaw("COALESCE(SUM(CASE WHEN type='debit' THEN amount ELSE -amount END),0) s")
                        ->value('s');
                    $valorReembolso = round(min((float) $order->supplier_total, $netLocal), 2);

                    if ($valorReembolso <= 0) {
                        Log::info("Pedido #{$order->order_number}: sem debito liquido neste ledger (net " . number_format($netLocal, 2) . ") — estorno e responsabilidade do backend que cobrou.");
                        return;
                    }

                    $walletService->creditRefund(
                        $order->client_id,
                        $order->supplier_id,
                        $valorReembolso,
                        $order,
                        'pedido ' . ($newStatus === OrderStatus::RETURNED->value ? 'devolvido' : 'cancelado'),
                        null,
                        "refund:{$newStatus}:order:{$order->id}"
                    );

                    Log::info("Credito de R$ {$valorReembolso} disparado com sucesso para a Wallet do Lojista {$order->client_id} (Fornecedor {$order->supplier_id}).");

                    // NOV-157: estornar credito do supplier quando pedido e cancelado/devolvido
                    if ($order->supplier_id) {
                        try {
                            app(SupplierWalletService::class)->debitOrderChargeback($order);
                        } catch (\Exception $eSup) {
                            Log::error("Falha ao estornar SupplierWallet no cancelamento do Pedido {$order->id}. Erro: " . $eSup->getMessage());
                        }
                    }
                } catch (\Exception $e) {
                    Log::error("Falha ao gerar credito de devolucao na Wallet. Pedido: {$order->id}. Erro: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * NOV-108: Baixa automatica de estoque ao pagar/enviar pedido.
     *
     * Para cada item do pedido, decrementa inventory.quantity de forma atomica
     * usando GREATEST(0, quantity - N) para nunca ir negativo.
     *
     * O decremento dispara o InventoryObserver.updated() que ja cuida
     * automaticamente do auto-pause de anuncios ML/Shopee via SyncInventoryJob
     * quando quantity chega a 0.
     *
     * Guard de idempotencia: stock_decremented_at — garante execucao unica mesmo
     * que o webhook/evento dispare multiplas vezes.
     */
    /**
     * MUL-334: o estoque deste fornecedor e governado por um ERP?
     * Grupo = o supplier raiz + os catalogos que apontam para ele (parent_supplier_id).
     * Basta um catalogo do grupo ter conta ERP para o grupo inteiro ser governado por ela:
     * no ERP e um produto so, com um saldo, e os catalogos sao o mesmo item com precos
     * diferentes.
     */
    private function estoqueGeridoPorErp(?int $supplierId): bool
    {
        if (! $supplierId) {
            return false;
        }

        static $cache = [];
        if (array_key_exists($supplierId, $cache)) {
            return $cache[$supplierId];
        }

        $raiz = (int) (\Illuminate\Support\Facades\DB::table('suppliers')
            ->where('id', $supplierId)->value('parent_supplier_id') ?: $supplierId);

        $doGrupo = \Illuminate\Support\Facades\DB::table('suppliers')
            ->where('id', $raiz)->orWhere('parent_supplier_id', $raiz)
            ->pluck('id');

        return $cache[$supplierId] = \Illuminate\Support\Facades\DB::table('erp_accounts')
            ->whereIn('supplier_id', $doGrupo)->exists();
    }

    private function decrementInventory(Order $order): void
    {
        if (!$order->supplier_id) {
            return;
        }

        // MUL-334: fornecedor com ERP nao tem baixa local. O Bling decrementa ao faturar e o
        // syncStockForErpAccount traz o saldo de volta a cada 6h — baixar aqui tambem conta a
        // mesma venda duas vezes. O ERP e a fonte unica do estoque, o sistema so espelha.
        if ($this->estoqueGeridoPorErp((int) $order->supplier_id)) {
            \Illuminate\Support\Facades\Log::info('[MUL-334] baixa de estoque ignorada: fornecedor gerido por ERP', [
                'order_id'    => $order->id,
                'supplier_id' => $order->supplier_id,
            ]);
            $order->stock_decremented_at = now();
            $order->saveQuietly();
            return;
        }

        try {
            $items = $order->relationLoaded('items') ? $order->items : $order->items()->get();

            if ($items->isEmpty()) {
                Log::warning('[NOV-108] decrementInventory: pedido sem items', [
                    'order_id'    => $order->id,
                    'supplier_id' => $order->supplier_id,
                ]);
                return;
            }

            $svc = app(\App\Services\Inventory\InventoryMovementService::class);
            $decremented = [];

            foreach ($items as $item) {
                if (!$item->product_id || $item->quantity <= 0) {
                    continue;
                }

                // NOV-117: usa InventoryMovementService::recordSale (registra em inventory_movements
                // + decrementa Inventory.quantity atomicamente + idempotência por (order_id, product_id)).
                $movement = $svc->recordSale($order, $item, $order->marketplace ?? null);

                if ($movement) {
                    $decremented[] = [
                        'product_id'  => $item->product_id,
                        'qty'         => $item->quantity,
                        'movement_id' => $movement->id,
                    ];
                }
            }

            // Marcar como decrementado (idempotencia)
            $order->stock_decremented_at = now();
            $order->saveQuietly();

            Log::info('[NOV-108/117] Baixa de estoque realizada com movement log', [
                'order_id'    => $order->id,
                'order_number'=> $order->order_number,
                'supplier_id' => $order->supplier_id,
                'status'      => $order->status,
                'decremented' => $decremented,
            ]);

        } catch (\Throwable $e) {
            Log::error('[NOV-108/117] Falha na baixa de estoque', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Grava uma linha em order_audit_log. Falhas sao logadas mas NAO
     * propagam — audit nao pode quebrar o fluxo principal.
     */
    private function writeAudit(Order $order, string $action, ?string $from, ?string $to, array $metadata = []): void
    {
        try {
            // Prioridade: 1) Tenant API (app()->instance setado pelo TenantApiAuth),
            //             2) usuario logado no painel HubAI, 3) system.
            $actorType = 'system';
            $actorId = null;
            if (app()->bound('current_tenant_actor')) {
                $ctx = app('current_tenant_actor');
                $actorType = $ctx['type'] ?? 'tenant';
                $actorId   = $ctx['id'] ?? null;
                $metadata['credential_key'] = $ctx['key'] ?? null;
            } elseif (Auth::check()) {
                $actorType = 'hubai';
                $actorId = (string) Auth::id();
            }

            DB::table('order_audit_log')->insert([
                'order_id'   => $order->id,
                'actor_type' => $actorType,
                'actor_id'   => $actorId,
                'action'     => $action,
                'from_state' => $from,
                'to_state'   => $to,
                'metadata'   => json_encode($metadata),
                'at'         => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[OrderObserver] audit_log falhou', [
                'order_id' => $order->id,
                'action'   => $action,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Fan-out de webhook para endpoints inscritos cujo tenant TENHA acesso ao
     * supplier do pedido (via tenant_supplier).
     * Falha no fan-out NAO quebra o fluxo do pedido (try/catch + log).
     */
    private function fanoutWebhook(Order $order, string $event, array $extra = []): void
    {
        try {
            if (!$order->supplier_id) return;

            // MUL-177: payload montado no FanoutOrderWebhookJob na EXECUCAO.
            // order.created disparava sincrono dentro do Order::create(), antes
            // dos OrderItem::create() dos importadores — tenant recebia items:[]
            // e sem comprador/rastreio. O delay garante que os items ja existem.
            \App\Jobs\FanoutOrderWebhookJob::dispatch($order->id, $event, $extra)
                ->delay(now()->addSeconds($event === 'order.created' ? 30 : 5));
        } catch (\Throwable $e) {
            Log::warning('[OrderObserver] fanoutWebhook falhou', [
                'order_id' => $order->id,
                'event'    => $event,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * [DESATIVADO FOR-036 2026-06-26] Rastreamento de desconto gradual removido.
     * Preco do catalogo = preco do fornecedor, sem descontos.
     */
    private function trackDiscountSale(Order $order): void
    {
        // FOR-036: desconto gradual desativado — retorna imediatamente sem alterar nada
        return;
    }
}
