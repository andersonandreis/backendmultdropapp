<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\BridgeRelayQueue;
use App\Models\MarketplaceAccount;
use App\Models\Order;
use App\Services\Integrations\Marketplaces\ShopeeService;
use App\Services\WebhookOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * ShopeeWebhookController
 *
 * Recebe push notifications diretas da Shopee Open Platform.
 * Rota: POST /webhooks/shopee
 *
 * Eventos implementados:
 *   code 3  — ORDER_STATUS_UPDATE    : atualiza status do pedido no banco
 *   code 4  — TRACKING_UPDATE        : salva numero de rastreio
 *   code 5  — SHOP_UPDATE            : desativa MarketplaceAccount se loja desautorizada
 *   code 11 — ESCROW_UPDATE          : loga para reconciliacao futura
 *   code 15 — SHIPPING_DOCUMENT_STATUS : baixa etiqueta quando READY (elimina polling)
 *   code 16 — ITEM_VIOLATION         : bloqueia produto e notifica admin
 *
 * Validacao de assinatura Shopee:
 *   base_string = url + "|" + partner_id + "|" + timestamp
 *   expected = HMAC-SHA256(base_string, partner_key)
 *   header Authorization = "sha256=" + hex(expected)
 */
class ShopeeWebhookController extends Controller
{
    // Mapa de codigos para descricao legivel
    private const EVENT_NAMES = [
        2  => 'SHOP_AUTHORIZATION_CANCELED',
        3  => 'ORDER_STATUS_UPDATE',
        4  => 'TRACKING_UPDATE',
        5  => 'SHOP_UPDATE',
        6  => 'ITEM_UPDATE',
        11 => 'ESCROW_UPDATE',
        15 => 'SHIPPING_DOCUMENT_STATUS',
        16 => 'ITEM_VIOLATION',
        29 => 'RETURN_UPDATES',
    ];

    public function handle(Request $request): JsonResponse
    {
        $code   = (int) ($request->input('code') ?? 0);
        $shopId = (int) ($request->input('shop_id') ?? 0);
        $data   = $request->input('data', []);

        // NOV-195: evento VAZIO (sem shop_id e sem data) nao e push real da Shopee —
        // algo bate neste endpoint a cada ~5min com corpo vazio (provavel monitor).
        // Antes disso, cada hit virava entrada na bridge_relay_queue que falhava
        // 5x ate failed_max (2.088 registros de lixo acumulados). ACK e descarta.
        if ($shopId === 0 && empty($data)) {
            return response()->json(['received' => true, 'ignored' => 'empty_event']);
        }

        Log::info('[Shopee Push] Evento recebido', [
            'code'    => $code,
            'event'   => self::EVENT_NAMES[$code] ?? "UNKNOWN_{$code}",
            'shop_id' => $shopId,
        ]);

        // MUL-352: guardar o evento ORIGINAL. Bling e ML ja gravavam em webhook_logs;
        // a Shopee processava e descartava o corpo (0 registros de shopee na tabela).
        // Sem isso, toda correcao de historico exige chamar a API de novo — foi o que
        // custou 3.179 chamadas de rastreio no backfill da MUL-343.
        // Nunca pode derrubar o webhook: falha aqui e engolida.
        try {
            \App\Models\WebhookLog::create([
                'platform' => 'shopee',
                'topic'    => self::EVENT_NAMES[$code] ?? "code_{$code}",
                'resource' => is_array($data) ? ($data['ordersn'] ?? $data['order_sn'] ?? null) : null,
                'user_id'  => $shopId ?: null,
                'status'   => 'received',
                'payload'  => $request->all(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Shopee Push] falha ao gravar webhook_logs', ['erro' => $e->getMessage()]);
        }

        // Verificar assinatura
        // MUL-090: assinatura invalida — logar mas PROCESSAR o webhook.
        // Chave push_partner_key no .env nao confere com assinatura Shopee real.
        // Ruan deve obter chave correta em open.shopee.com e atualizar SHOPEE_PUSH_PARTNER_KEY.
        $sigValid = $this->validateSignature($request);
        if (! $sigValid) {
            Log::warning('[Shopee Push] Assinatura invalida — processando mesmo assim (MUL-090)', [
                'code'    => $code,
                'shop_id' => $shopId,
                'ip'      => $request->ip(),
            ]);
        }

        // HUB-131: Deduplicacao Shopee.
        // Shopee nao tem notification_id unico — usamos timestamp+shop_id+code como chave.
        // Se o mesmo evento chegar 2x (retry do marketplace), descarta silenciosamente.
        $timestamp  = $request->input('timestamp', 0);
        $externalId = "shopee|{$shopId}|{$code}|{$timestamp}";
        $isNew = \App\Models\ProcessedWebhookId::markProcessed('shopee', $externalId, (string) $code);
        if (! $isNew) {
            Log::info('[Shopee Push] Evento duplicado descartado (HUB-131)', [
                'code'        => $code,
                'shop_id'     => $shopId,
                'external_id' => $externalId,
            ]);
            return response()->json(['received' => true]);
        }

        // SEL-357: ignorar writes de webhook se a conta Shopee deste shop_id e espelho readonly
        $mirrorAccount = MarketplaceAccount::where('shop_id', (string) $shopId)
            ->where('mirror_mode', 'readonly')
            ->first();
        if ($mirrorAccount) {
            Log::info('[Shopee Push] shop_id e espelho readonly (SEL-357) — ignorando write', [
                'code'       => $code,
                'shop_id'    => $shopId,
                'account_id' => $mirrorAccount->id,
            ]);
            return response()->json(['received' => true, 'ignored' => 'mirror_readonly']);
        }

        try {
        match ($code) {
            2  => $this->handleShopAuthorizationCanceled($shopId, $data),
            3  => $this->handleOrderStatusUpdate($shopId, $data),
            4  => $this->handleTrackingUpdate($shopId, $data),
            5  => $this->handleShopUpdate($shopId, $data),
            11 => $this->handleEscrowUpdate($shopId, $data),
            15 => $this->handleShippingDocumentStatus($shopId, $data),
            16 => $this->handleItemViolation($shopId, $data),
            29 => $this->handleReturnUpdate($shopId, $data),
            default => Log::info('[Shopee Push] Evento nao tratado', ['code' => $code]),
        };
        } catch (\Throwable $e) {
            Log::warning('[Shopee Push] Erro no handler local', [
                'code'  => $code,
                'error' => $e->getMessage(),
            ]);
        }


        // MUL-087: relay via defer() - retorna 200 para Shopee ANTES do relay
        // Causa raiz falhas jun27-30: relay sincrono 30s bloqueava resposta HTTP
        // Shopee marcava timeout (Response Code: -) e pausava pushes Partner 2036907
        defer(function () use ($code, $shopId, $data): void {
            try {
                $relayResult = app(\App\Services\GoolhubBridgeService::class)->relayShopeeEvent(
                    $code,
                    array_merge($data, ["shop_id" => $shopId])
                );
                if (! $relayResult["success"]) {
                    \Illuminate\Support\Facades\Log::warning("[Shopee Push] Bridge erro - enfileirando para retry", [
                        "code"  => $code,
                        "error" => $relayResult["error"] ?? "unknown",
                    ]);
                    $orderSn = $data["ordersn"] ?? $data["order_sn"] ?? null;
                    $orderId = $orderSn ? \App\Models\Order::where("marketplace_order_id", $orderSn)->value("id") : null;
                    \App\Models\BridgeRelayQueue::enqueueShopeeRelay($code, $shopId, $data, $orderId, $relayResult["error"] ?? "bridge error");
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("[Shopee Push] Falha relay legado - enfileirando para retry", [
                    "code"  => $code,
                    "error" => $e->getMessage(),
                ]);
                try {
                    $orderSn = $data["ordersn"] ?? $data["order_sn"] ?? null;
                    $orderId = $orderSn ? \App\Models\Order::where("marketplace_order_id", $orderSn)->value("id") : null;
                    \App\Models\BridgeRelayQueue::enqueueShopeeRelay($code, $shopId, $data, $orderId, substr($e->getMessage(), 0, 500));
                } catch (\Throwable $e2) {
                    \Illuminate\Support\Facades\Log::error("[Shopee Push] FALHA ao enfileirar para retry", ["error" => $e2->getMessage()]);
                }
            }
        });
        return response()->json(["received" => true]);
    }

    // =========================================================================
    // EVENTO 3 — ORDER_STATUS_UPDATE
    // Payload: { order_sn, status }
    // Status possiveis: UNPAID, READY_TO_SHIP, PROCESSED, SHIPPED, COMPLETED, IN_CANCEL, CANCELLED
    // =========================================================================
    private function handleOrderStatusUpdate(int $shopId, array $data): void
    {
        $orderSn    = $data['ordersn'] ?? $data['order_sn'] ?? '';
        $newStatus  = $data['status'] ?? '';

        if (! $orderSn) {
            return;
        }


        // NOV-150-B: webhook-first para status de pagamento confirmado
        $paidStatuses = ['READY_TO_SHIP', 'PROCESSED', 'SHIPPED', 'COMPLETED'];
        if (in_array($newStatus, $paidStatuses, true)) {
            try {
                app(WebhookOrderService::class)->processWebhookOrder(
                    'shopee',
                    ['data' => array_merge($data, ['shop_id' => $shopId])],
                    $shopId
                );
            } catch (\Throwable $e) {
                Log::warning('[Shopee Push] WebhookOrderService falhou (nao critico)', [
                    'order_sn' => $orderSn,
                    'status'   => $newStatus,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        $order = Order::where('marketplace_order_id', $orderSn)
            ->orWhere('order_number', $orderSn)
            ->first();

        if (! $order) {
            Log::info('[Shopee Push] ORDER_STATUS: pedido nao encontrado', ['order_sn' => $orderSn]);
            return;
        }

        // Mapa Shopee status -> canonical_status interno
        $canonicalMap = [
            'UNPAID'        => 'pending_payment',
            'READY_TO_SHIP' => 'awaiting_shipment',
            'PROCESSED'     => 'processing',
            'SHIPPED'       => 'shipped',
            'COMPLETED'     => 'delivered',
            'TO_CONFIRM_RECEIVE' => 'delivered',   // chegou ao comprador; falta ele confirmar (doc Shopee)
            'RETRY_SHIP'    => 'awaiting_shipment',
            'IN_CANCEL'     => 'cancellation_requested',
            'CANCELLED'     => 'cancelled',
        ];

        $updates = ['status' => strtolower($newStatus)];

        if (isset($canonicalMap[$newStatus])) {
            $updates['canonical_status'] = $canonicalMap[$newStatus];
        } else {
            // sem isto o proximo buraco tambem seria silencioso (LOG_LEVEL=warning esconde info)
            Log::warning('[Shopee Push] status sem traducao no mapa', [
                'order_sn' => $orderSn,
                'status'   => $newStatus,
            ]);
        }

        // Marcos temporais. O handler nunca os escrevia: 0 de 2.038 entregues tinham delivered_at.
        // So preenche quando ainda nulo, para nao reescrever historico.
        $marcos = [
            'READY_TO_SHIP'      => 'paid_at',
            'PROCESSED'          => 'paid_at',
            'SHIPPED'            => 'shipped_at',
            'TO_CONFIRM_RECEIVE' => 'delivered_at',
            'COMPLETED'          => 'delivered_at',
        ];
        if (isset($marcos[$newStatus]) && is_null($order->{$marcos[$newStatus]})) {
            $updates[$marcos[$newStatus]] = now();
        }

        // Quando READY_TO_SHIP, marcar para gerar etiqueta
        if ($newStatus === 'READY_TO_SHIP') {
            $updates['order_processing_status'] = 'awaiting_label';
        }

        $order->update($updates);

        Log::info('[Shopee Push] ORDER_STATUS: atualizado', [
            'order_sn' => $orderSn,
            'status'   => $newStatus,
        ]);
    }

    // =========================================================================
    // EVENTO 4 — TRACKING_UPDATE
    // Payload: { order_sn, package_number, logistics_status, tracking_no }
    // =========================================================================
    private function handleTrackingUpdate(int $shopId, array $data): void
    {
        $orderSn     = $data['ordersn'] ?? $data['order_sn'] ?? '';
        $trackingNo  = $data['tracking_no'] ?? '';
        // MUL-354: este evento e a UNICA fonte gratuita de package_number — nenhum outro
        // push traz. Era descartado, e sem ele a Shopee recusa baixar o documento.
        $packageNo   = $data['package_number'] ?? null;

        if (! $orderSn || ! $trackingNo) {
            return;
        }

        // orWhere sem agrupamento aplicaria (A OR B) e poderia pegar pedido alheio
        // cujo order_number coincida com este ordersn.
        $order = Order::where(function ($q) use ($orderSn) {
            $q->where('marketplace_order_id', $orderSn)->orWhere('order_number', $orderSn);
        })->first();

        if (! $order) {
            Log::warning('[Shopee Push] TRACKING_UPDATE: pedido nao encontrado', [
                'order_sn' => $orderSn, 'shop_id' => $shopId,
            ]);
            return;
        }

        $updates = ['tracking_number' => $trackingNo];
        if ($packageNo && empty($order->external_shipping_id)) {
            $updates['external_shipping_id'] = $packageNo;
        }
        $order->updateQuietly($updates);

        Log::info('[Shopee Push] TRACKING_UPDATE', [
            'order_sn'       => $orderSn,
            'tracking_no'    => $trackingNo,
            'package_number' => $packageNo,
        ]);

        // MUL-354: a logistica foi arranjada — e o momento em que o documento fica
        // disponivel. Medido em 08/08: o evento chega entre 16s e 41min da venda.
        // O job pergunta o estado antes de criar, entao serve tanto para quem emite
        // pelo Bling (ja READY -> baixa) quanto para quem nao emite (cria).
        if (empty($order->label_url)) {
            \App\Jobs\FetchShippingLabelJob::dispatch($order->id, 'tracking_update');
        }
    }

    // =========================================================================
    // EVENTO 5 — SHOP_UPDATE
    // Payload: { shopid, shop_update_info: { status, ... } }
    // Quando loja desautoriza o app, desativar a MarketplaceAccount
    // =========================================================================
    /**
     * EVENTO 2 — SHOP_AUTHORIZATION_CANCELED (shop_authorization_canceled_push)
     *
     * MUL-353: a Shopee AVISA quando perde a autorizacao, e o aviso era descartado
     * no default do match — e o Log::info do default e engolido pelo LOG_LEVEL=warning.
     * Consequencia: conta morria em silencio e so aparecia semanas depois. Aconteceu
     * em 01/07 (14 contas Shopee) e de novo com o Bling (26 contas, MUL-350).
     *
     * A logica ja existia em handleShopUpdate (code 5) — mas o code 5 e outro evento.
     */
    private function handleShopAuthorizationCanceled(int $shopId, array $data): void
    {
        $updated = MarketplaceAccount::where('shop_id', (string) $shopId)
            ->where('platform', 'shopee')
            ->update([
                'status'          => 'needs_reauth',
                'needs_reauth'    => 1,
                'sync_blocked_at' => now(),
            ]);

        Log::warning('[Shopee Push] AUTORIZACAO CANCELADA — conta precisa reautorizar', [
            'shop_id' => $shopId,
            'contas_marcadas' => $updated,
            'data' => $data,
        ]);
    }

    /**
     * EVENTO 29 — RETURN_UPDATES (return_updates_push)
     *
     * MUL-353: chega em tempo real com return_sn e a mudanca de logistics_status.
     * Era descartado; marketplace_returns so era alimentada por outro caminho.
     * Payload: { order_sn, return_sn, updated_values: [{update_field, old_value, new_value, update_time}] }
     */
    private function handleReturnUpdate(int $shopId, array $data): void
    {
        $returnSn = $data['return_sn'] ?? null;
        $orderSn  = $data['order_sn'] ?? $data['ordersn'] ?? null;
        $updates  = $data['updated_values'] ?? [];

        Log::warning('[Shopee Push] RETURN_UPDATE', [
            'shop_id' => $shopId, 'return_sn' => $returnSn,
            'order_sn' => $orderSn, 'updates' => $updates,
        ]);

        if (! $returnSn) {
            return;
        }

        // Só toca em devolucao que ja existe: criar do zero exige o get_return_detail,
        // que e outra chamada e outro escopo. Aqui garantimos que o registro existente
        // reflita o evento e que o payload bruto fique guardado.
        $existente = \DB::table('marketplace_returns')->where('return_sn', $returnSn)->first();
        if (! $existente) {
            Log::warning('[Shopee Push] RETURN_UPDATE: devolucao ainda nao importada', [
                'return_sn' => $returnSn,
            ]);
            return;
        }

        $set = ['marketplace_updated_at' => now(), 'updated_at' => now()];
        foreach ($updates as $u) {
            if (($u['update_field'] ?? '') === 'logistics_status') {
                $novo = (string) ($u['new_value'] ?? '');
                // a mercadoria chegou de volta encerra a devolucao (mesma regra do EtapaDoPedido)
                if ($novo === 'LOGISTICS_REQUEST_ARRIVED' || str_contains($novo, 'ARRIVED')) {
                    $set['is_arrived_at_warehouse'] = 1;
                }
                $set['needs_logistics'] = str_contains($novo, 'CANCELED') ? 0 : 1;
            }
        }

        try {
            \DB::table('marketplace_returns')->where('return_sn', $returnSn)->update($set);
        } catch (\Throwable $e) {
            Log::warning('[Shopee Push] RETURN_UPDATE: falha ao atualizar', [
                'return_sn' => $returnSn, 'erro' => $e->getMessage(),
            ]);
        }
    }

    private function handleShopUpdate(int $shopId, array $data): void
    {
        $updateInfo = $data['shop_update_info'] ?? [];
        $status     = $updateInfo['status'] ?? $data['status'] ?? '';

        if ($status === 'BANNED' || $status === 'DEAUTHORIZED') {
            $updated = MarketplaceAccount::where('shop_id', (string) $shopId)
                ->where('platform', 'shopee')
                ->update(['status' => 'inactive', 'sync_blocked_at' => now()]);

            Log::warning('[Shopee Push] SHOP_UPDATE: loja desautorizada', [
                'shop_id' => $shopId,
                'status'  => $status,
                'updated' => $updated,
            ]);
        }
    }

    // =========================================================================
    // EVENTO 11 — SHIPPING_DOCUMENT_STATUS
    // Payload: { order_sn, package_number, document_type, status }
    // Status: READY | FAILED
    //
    // SUBSTITUI o polling de get_shipping_document_result em ShopeeService.
    // Quando READY: baixar a etiqueta imediatamente.
    // =========================================================================
    private function handleShippingDocumentStatus(int $shopId, array $data): void
    {
        $orderSn      = $data['ordersn'] ?? $data['order_sn'] ?? '';
        $docStatus    = $data['status'] ?? '';
        $docType      = $data['document_type'] ?? 'SHIPPING_LABEL';

        Log::info('[Shopee Push] SHIPPING_DOCUMENT_STATUS', [
            'order_sn'  => $orderSn,
            'status'    => $docStatus,
            'doc_type'  => $docType,
            'shop_id'   => $shopId,
        ]);

        if ($docStatus !== 'READY' || ! $orderSn) {
            if ($docStatus === 'FAILED') {
                Order::where('marketplace_order_id', $orderSn)
                    ->orWhere('order_number', $orderSn)
                    ->update(['order_processing_status' => 'label_failed']);
            }
            return;
        }

        // Buscar a MarketplaceAccount desta loja para ter tokens
        $account = MarketplaceAccount::where('shop_id', (string) $shopId)
            ->where('platform', 'shopee')
            ->where('status', 'active')
            ->first();

        if (! $account) {
            Log::warning('[Shopee Push] SHIPPING_DOCUMENT_STATUS: conta nao encontrada', ['shop_id' => $shopId]);
            return;
        }

        // Baixar a etiqueta via ShopeeService (step 5 do fluxo — sem polling)
        try {
            $shopeeService = app(ShopeeService::class);
            $order = Order::where('marketplace_order_id', $orderSn)
                ->orWhere('order_number', $orderSn)
                ->first();

            if (! $order) {
                Log::warning('[Shopee Push] SHIPPING_DOCUMENT_STATUS: pedido nao encontrado', ['order_sn' => $orderSn]);
                return;
            }

            // MUL-353: passa pelo FetchShippingLabelJob em vez de baixar direto aqui.
            // O download direto era UMA tentativa: se a etiqueta ainda nao estivesse
            // pronta do lado da Shopee, morria no log e o pedido ficava em
            // awaiting_label para sempre (1.347 pedidos assim em 08/08).
            // O job ja tem tries=3, lock por pedido e grava o motivo padronizado em
            // orders.label_status_reason — agora com backoff de 1min/5min/15min.
            // MUL-354: o evento 15 diz 'documento READY' — mesmo sinal do evento 4.
            // Os dois podem chegar em qualquer ordem e ambos disparam este job; quem
            // chegar primeiro faz o trabalho. O job e ShouldBeUnique por order_id, entao
            // o segundo dispatch e descartado em vez de baixar a etiqueta duas vezes.
            \App\Jobs\FetchShippingLabelJob::dispatch($order->id, 'tracking_update');

            Log::info('[Shopee Push] SHIPPING_DOCUMENT_STATUS: etiqueta enfileirada', [
                'order_sn' => $orderSn,
                'order_id' => $order->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('[Shopee Push] SHIPPING_DOCUMENT_STATUS: falha ao baixar etiqueta', [
                'order_sn' => $orderSn,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    // =========================================================================
    // EVENTO 15 — ESCROW_UPDATE (pagamento liberado)
    // Logar para reconciliacao — ShopeeReconciliationAdapter processa depois
    // =========================================================================
    private function handleEscrowUpdate(int $shopId, array $data): void
    {
        $orderSn = $data['ordersn'] ?? $data['order_sn'] ?? '';

        Log::info('[Shopee Push] ESCROW_UPDATE: pagamento liberado', [
            'order_sn' => $orderSn,
            'shop_id'  => $shopId,
            'data'     => $data,
        ]);

        if (! $orderSn) {
            return;
        }

        // MUL-353: o evento avisa que o dinheiro fechou — e o momento em que comissao,
        // taxa e frete ficam definitivos. Antes so marcava canonical_status; agora busca
        // os valores. Sem isto o financeiro ficava 100% vazio (20.199 de 20.199 pedidos)
        // e so entrava por varredura manual da API.
        //
        // canonical_status NAO e mais tocado aqui: 'payment_released' nao existe no mapa
        // do EtapaDoPedido, entao derrubava o pedido para "Pendente" na timeline.
        $order = Order::where('marketplace_order_id', $orderSn)->first();
        if (! $order) {
            Log::warning('[Shopee Push] ESCROW_UPDATE: pedido nao encontrado', ['order_sn' => $orderSn]);
            return;
        }

        try {
            $account = MarketplaceAccount::where('shop_id', (string) $shopId)
                ->where('platform', 'shopee')->first();
            if (! $account) {
                return;
            }

            $resp = app(ShopeeService::class)->getEscrowDetail($account, $orderSn);
            $oi   = $resp['response']['order_income'] ?? [];
            if (! $oi) {
                return;
            }

            // so preenche o que esta vazio — nunca sobrescreve valor ja conferido
            $updates = [];
            foreach ([
                'marketplace_fee' => 'commission_fee',
                'platform_fee'    => 'service_fee',
                'shipping_cost'   => 'actual_shipping_fee',
                'discount_amount' => 'seller_discount',
            ] as $col => $campoApi) {
                $v = (float) ($oi[$campoApi] ?? 0);
                if ($v > 0 && empty($order->{$col})) {
                    $updates[$col] = $v;
                }
            }

            if ($updates) {
                $order->updateQuietly($updates);
                Log::info('[Shopee Push] ESCROW_UPDATE: financeiro preenchido', [
                    'order_sn' => $orderSn, 'campos' => array_keys($updates),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('[Shopee Push] ESCROW_UPDATE: falha ao buscar escrow', [
                'order_sn' => $orderSn, 'erro' => $e->getMessage(),
            ]);
        }
    }

    // =========================================================================
    // EVENTO 16 — ITEM_VIOLATION
    // Payload: { item_list: [{ item_id, ... }], violation_type }
    // =========================================================================
    private function handleItemViolation(int $shopId, array $data): void
    {
        $itemList      = $data['item_list'] ?? [];
        $violationType = $data['violation_type'] ?? 'UNKNOWN';

        $itemIds = array_column($itemList, 'item_id');

        Log::warning('[Shopee Push] ITEM_VIOLATION', [
            'shop_id'        => $shopId,
            'violation_type' => $violationType,
            'item_count'     => count($itemList),
            'item_ids'       => $itemIds,
        ]);

        // Bloquear produtos violados: desativar + registrar motivo em SyncLog
        if (! empty($itemIds)) {
            $products = \App\Models\Product::whereIn('shopee_item_id', $itemIds)->get();

            foreach ($products as $product) {
                $product->update(['is_active' => false]);

                \App\Models\SyncLog::create([
                    'syncable_type'   => \App\Models\Product::class,
                    'syncable_id'     => $product->id,
                    'platform'        => 'shopee',
                    'action'          => 'Item Violation',
                    'direction'       => 'inbound',
                    'status'          => 'failed',
                    'error_message'   => "VIOLACAO SHOPEE [{$violationType}]: produto desativado automaticamente. shop_id={$shopId}",
                    'request_payload' => json_encode([
                        'shopee_item_id' => $product->shopee_item_id,
                        'violation_type' => $violationType,
                        'shop_id'        => $shopId,
                    ]),
                ]);
            }

            Log::warning('[Shopee Push] ITEM_VIOLATION: produtos desativados', [
                'shop_id'        => $shopId,
                'violation_type' => $violationType,
                'desativados'    => $products->pluck('sku')->toArray(),
            ]);
        }
    }


    // =========================================================================
    // VALIDACAO DE ASSINATURA
    // base_string = partner_id + "|" + url + "|" + timestamp
    // expected    = HMAC-SHA256(base_string, partner_key)
    // =========================================================================
    private function validateSignature(Request $request): bool
    {
        $partnerId  = (int) config('services.shopee.partner_id');
        $partnerKey = config('services.shopee.push_partner_key', config('services.shopee.partner_key'));

        if (! $partnerId || ! $partnerKey) {
            // Em desenvolvimento sem credenciais, aceitar tudo
            return true;
        }

        $authorization = $request->header('Authorization', '');
        // Shopee envia "sha256=HEX_DIGEST" ou apenas o hex
        $receivedSig = str_starts_with($authorization, 'sha256=')
            ? substr($authorization, 7)
            : $authorization;

        if (! $receivedSig) {
            // Eventos do legado via bridge nao tem assinatura Shopee — aceitar
            return true;
        }

        $timestamp  = $request->input('timestamp', time());
        $url        = $request->url();
        // MUL-089: ordem correta conforme docs Shopee — partner_id|url|timestamp
        $baseString = $partnerId . '|' . $url . '|' . $timestamp;
        $expected   = hash_hmac('sha256', $baseString, $partnerKey);


        if (hash_equals($expected, $receivedSig)) {
            return true;
        }

        // MUL-089b: tentar com chave hex2bin (chave pode estar hex-encoded no .env)
        $partnerKeyBin = @hex2bin($partnerKey) ?: $partnerKey;
        if ($partnerKeyBin !== $partnerKey) {
            $expectedBin = hash_hmac('sha256', $baseString, $partnerKeyBin);
            if (hash_equals($expectedBin, $receivedSig)) {
                return true;
            }
        }

        return false;
    }
}
