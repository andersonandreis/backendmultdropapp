<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\HubClient;
use App\Models\Client;
use App\Models\Order;
use App\Services\Orders\DraftOrderPromoter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * MUL-160 - Recebe pedidos do hub via fanoutWebhook (OrderObserver).
 * Aceita payload data.order (novo) e order (compat).
 * Mapeia hub.client_id -> local.client_id via HubClient.legacy_id_login.
 * Fallback: client_id=null (permitido pela migration MUL-160).
 * filterByOrdersSchema adapta ao schema de cada WL (multdrop/fornecefy/etc).
 */
class HubAIOrderWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        return $this->handlePayload($request->all());
    }

    /**
     * Letra B (INF-053): nucleo do sync compartilhado.
     * Aceita payload como array — chamado pelo Request (HTTP) OU pelo FederationReceiveOrderJob (queue).
     */
    public function handlePayload(array $payload): JsonResponse
    {
        $orderData = $payload['data']['order'] ?? $payload['order'] ?? null;

        // MUL-310: o hub emite por DOIS jobs com formatos diferentes. O FanoutOrderWebhookJob
        // manda {data:{order:{...}}}; o DispatchTenantOrderWebhookJob manda os campos na RAIZ
        // (order_id/hub_order_id/supplier_id/status/total). O segundo virava 422 — 14.780 vezes
        // so em julho/2026. Agora e aceito e normalizado para o formato interno.
        if (!is_array($orderData)) {
            $idRaiz = $payload['hub_order_id'] ?? $payload['order_id'] ?? null;
            if ($idRaiz) {
                $orderData = array_filter([
                    'id'                 => (int) $idRaiz,
                    'supplier_id'        => $payload['supplier_id'] ?? null,
                    'status'             => $payload['status'] ?? null,
                    'total'              => $payload['total'] ?? null,
                    'origin_tenant_slug' => $payload['origin_tenant_slug'] ?? null,
                ], static fn ($v) => $v !== null);
            }
        }

        if (!is_array($orderData) || empty($orderData['id'])) {
            return response()->json([
                'error' => 'invalid_payload',
                'hint'  => 'expected data.order.id, order.id, hub_order_id or order_id',
            ], 422);
        }

        $event = $payload['event'] ?? 'order.updated';

        if (!in_array($event, ['order.created', 'order.updated', 'order.status_changed'])) {
            return response()->json(['ignored' => true, 'event' => $event]);
        }

        // MUL-212: env() nao funciona com config:cache — ler via config
        $supplierId    = (int) config('services.hubai_federation.default_supplier_id');
        $tenantId      = config('services.hubai_federation.default_tenant_id');
        $hubaiOrderId  = (int) $orderData['id'];
        $hubaiClientId = !empty($orderData['client_id']) ? (int) $orderData['client_id'] : null;

        $localClientId = null;
        if ($hubaiClientId) {
            try {
                $hubClient = HubClient::query()
                    ->select(['id', 'legacy_id_login'])
                    ->where('id', $hubaiClientId)
                    ->first();
                if ($hubClient && $hubClient->legacy_id_login) {
                    $local = Client::query()
                        ->where('legacy_id_login', $hubClient->legacy_id_login)
                        ->first();
                    if ($local) {
                        $localClientId = (int) $local->id;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('HubAI webhook: falha ao mapear client_id', [
                    'hubai_client_id' => $hubaiClientId,
                    'error'           => $e->getMessage(),
                ]);
            }
        }

        // FOR-128: quando ESTE backend e a WL de origem do pedido, o hub carrega o id
        // local do cliente em marketplace_accounts.wl_client_id. E o unico caminho para
        // pedido cujo seller nao existe como cliente do hub (896 de 1.026 no Fornecefy).
        // Nunca aplicar em tenant que nao seja a origem: o id e local da WL de origem.
        if ($localClientId === null) {
            $origem = $orderData['origin_tenant_slug'] ?? ($payload['origin_tenant_slug'] ?? null);
            $wlClientId = ! empty($orderData['wl_client_id']) ? (int) $orderData['wl_client_id'] : null;

            if ($wlClientId && $origem && $origem === config('app.tenant')) {
                $existe = Client::query()->whereKey($wlClientId)->exists();
                if ($existe) {
                    $localClientId = $wlClientId;
                } else {
                    Log::warning('[FOR-128] wl_client_id nao existe neste banco', [
                        'wl_client_id' => $wlClientId,
                        'origem'       => $origem,
                    ]);
                }
            }
        }



        $orderNumber = !empty($orderData['order_number'])
            ? (string) $orderData['order_number']
            : ('HUB-' . $hubaiOrderId);

        $candidates = [
            'tenant_id'              => $tenantId,
            'supplier_id'            => $supplierId,
            'hubai_client_id'        => $hubaiClientId,
            'client_id'              => $localClientId,
            'source'                 => $orderData['source'] ?? null,
            'status'                 => $orderData['status'] ?? (isset($orderData['canonical_status']) ? $this->mapCanonicalToStatus($orderData['canonical_status']) : null),
            'canonical_status'       => $orderData['canonical_status'] ?? null,
            'subtotal'               => $orderData['subtotal'] ?? null,
            'shipping_cost'          => $orderData['shipping_cost'] ?? null,
            'discount_amount'        => $orderData['discount_amount'] ?? null,
            'total'                  => $orderData['total'] ?? null,
            'currency'               => $orderData['currency'] ?? null,
            'order_number'           => $orderNumber,
            'external_order_id'      => $orderData['external_order_id'] ?? null,
            // MUL-352: guardar o payload ORIGINAL do marketplace quando o hub o enviar,
            // e so cair no envelope do hub como ultimo recurso. Antes gravava sempre o
            // envelope ja serializado — dupla codificacao, JSON inconsultavel.
            'raw_payload'            => ! empty($orderData['raw_payload'])
                ? (is_string($orderData['raw_payload'])
                    ? $orderData['raw_payload']
                    : json_encode($orderData['raw_payload'], JSON_UNESCAPED_UNICODE))
                : json_encode($payload),
            // FOR-127: nome do seller vem no payload; o WL nao tem como derivar.
            'wl_seller_name'         => $orderData['wl_seller_name'] ?? null,
            // FOR-130: referencia do pagamento no gateway
            'payment_external_id'    => $orderData['payment_external_id'] ?? null,
            'payment_method'         => $orderData['payment_method'] ?? null,
            'payment_gateway'        => $orderData['payment_gateway'] ?? null,
            'customer_name'          => $orderData['customer_name'] ?? null,
            'customer_email'         => $orderData['customer_email'] ?? null,
            'customer_phone'         => $orderData['customer_phone'] ?? null,
            'buyer_username'         => $orderData['buyer_username'] ?? null,
            'buyer_nickname'         => $orderData['buyer_nickname'] ?? null,
            // MUL-214 item 20: endereco do comprador + data de entrega vindos do hub
            'customer_address'       => $orderData['customer_address'] ?? null,
            'delivered_at'           => ! empty($orderData['delivered_at'])
                ? \Carbon\Carbon::parse($orderData['delivered_at'])
                : null,
            // MUL-343: shipped_at nunca era lido do payload do hub
            'shipped_at'             => ! empty($orderData['shipped_at'])
                ? \Carbon\Carbon::parse($orderData['shipped_at'])
                : null,
            // MUL-352: financeiro do pedido — taxa, frete e desconto
            'marketplace_fee'        => $orderData['marketplace_fee'] ?? null,
            'platform_fee'           => $orderData['platform_fee'] ?? null,
            'shipping_cost'          => $orderData['shipping_cost'] ?? null,
            'discount_amount'        => $orderData['discount_amount'] ?? null,
            'external_shipping_id'   => $orderData['external_shipping_id'] ?? null,
            'paid_at'                => ! empty($orderData['paid_at'])
                ? \Carbon\Carbon::parse($orderData['paid_at'])
                : null,
            // MUL-363 Fase 4: wallet_paid_at/wallet_transaction_id NAO entram mais pelo
            // espelho — cada backend e dono absoluto dos seus carimbos de pagamento
            // (regra 35). O payload traz so o flag informativo origin_wallet_paid, que
            // NAO e persistido em campo de wallet. Era a raiz da MUL-362 causa 1.
            'supplier_total'         => array_key_exists('supplier_total', $orderData)
                ? $orderData['supplier_total']
                : null,
            'channel_name'           => $orderData['channel_name'] ?? null,
            'tracking_number'        => $orderData['tracking_number'] ?? null,
            'label_url'              => $orderData['label_url'] ?? null,
            'label_status_reason'    => $orderData['label_status_reason'] ?? null,
            'tracking_url'           => $orderData['tracking_url'] ?? null,
            // MUL-177: marketplace_account_id do payload e ID do banco do HUB - nao gravar aqui
            'carrier_name'           => $orderData['carrier_name'] ?? null,
            'shipping_mode'          => $orderData['shipping_mode'] ?? null,
            'marketplace_order_id'   => $orderData['marketplace_order_id'] ?? null,
            'shop_id'                => $orderData['shop_id'] ?? null,
            'tenant_slug'            => $orderData['tenant_slug'] ?? null,
            'origin_tenant_slug'     => $orderData['origin_tenant_slug'] ?? null,
            // MUL-237: data real da venda no marketplace
            "marketplace_created_at" => ! empty($orderData["marketplace_created_at"])
                ? \Carbon\Carbon::parse($orderData["marketplace_created_at"])
                : null,
            // MUL-252: NF-e saida + entrada vindas do hub (null = ignora, MUL-165)
            'invoice_number'         => $orderData['invoice_number'] ?? null,
            'invoice_series'         => $orderData['invoice_series'] ?? null,
            'invoice_status'         => $orderData['invoice_status'] ?? null,
            'invoice_access_key'     => $orderData['invoice_access_key'] ?? null,
            'invoice_issued_at'      => ! empty($orderData['invoice_issued_at'])
                ? \Carbon\Carbon::parse($orderData['invoice_issued_at'])
                : null,
            'invoice_url'            => $orderData['invoice_url'] ?? null,
            'invoice_xml_url'        => $orderData['invoice_xml_url'] ?? null,
            'nfe_entrada_status'     => $orderData['nfe_entrada_status'] ?? null,
            'nfe_entrada_access_key' => $orderData['nfe_entrada_access_key'] ?? null,
            'nfe_entrada_pdf_url'    => $orderData['nfe_entrada_pdf_url'] ?? null,
            'nfe_entrada_xml_url'    => $orderData['nfe_entrada_xml_url'] ?? null,
        ];

        $data  = $this->filterByOrdersSchema($candidates);

        // MUL-289: o payload traz marketplace_account_id do banco do HUB, e a MUL-177
        // corretamente nao grava esse valor (aponta para outra linha aqui). Mas nada
        // resolvia a conta LOCAL, entao todo pedido vindo do fanout nascia com
        // marketplace_account_id NULL — mesmo com a conta conectada e ATIVA na WL.
        // Consequencia: FetchShippingLabelJob marcava missing_marketplace_account e o
        // painel do seller exibia "Conecte sua conta do marketplace", um CTA falso para
        // um problema que nao era dele. Resolve por client_id + shop_id + plataforma.
        if (empty($data['marketplace_account_id']) && $localClientId && !empty($orderData['shop_id'])) {
            $plataforma = $orderData['source'] ?? null;
            if ($plataforma === 'ml') { $plataforma = 'mercadolivre'; }
            if ($plataforma) {
                $contaLocal = \Illuminate\Support\Facades\DB::table('marketplace_accounts')
                    ->where('client_id', $localClientId)
                    ->where('shop_id', (string) $orderData['shop_id'])
                    ->where('platform', $plataforma)
                    ->orderBy('id')
                    ->value('id');
                if ($contaLocal) {
                    $data['marketplace_account_id'] = $contaLocal;
                }
            }
        }

        $items = is_array($orderData['items'] ?? null) ? $orderData['items'] : [];

        // MUL-186: lock anti-race — 2 webhooks simultaneos do mesmo pedido passavam
        // juntos pela busca e criavam 2 rows (62 grupos shopee x shopee no multdrop).
        $lock = \Illuminate\Support\Facades\Cache::lock('hubai-order-recv-' . $hubaiOrderId, 15);
        $lockAcquired = false;
        try {
            try {
                $lockAcquired = $lock->block(10);
            } catch (\Throwable $lockErr) {
                $lockAcquired = false; // sem lock: degrada pro comportamento antigo
            }

            // MUL-181 (race): o import nativo cria a order sem hubai_order_id; buscar
            // tambem por marketplace_order_id para ADOTAR essa row em vez de duplicar
            $order = Order::where('hubai_order_id', $hubaiOrderId)->first();

            $marketplaceOrderId = $orderData['marketplace_order_id'] ?? null;
            if (! $order && $marketplaceOrderId) {
                $order = Order::where('marketplace_order_id', $marketplaceOrderId)
                    ->whereNull('hubai_order_id')
                    ->orderBy('id')
                    ->first();
            }

            if (! $order) {
                // JT-022: portao de catalogo do fornecedor (regra do Ruan / FOR-131).
                // Ativo so onde MIRROR_REQUIRE_CATALOG_SKU=true (WL de fornecedor).
                // Pedido NOVO cujos itens TEM SKU e nenhum resolve no catalogo local
                // (pai direto, filho ou kit) e do catalogo externo do seller: nao vira
                // espelho, fica o log. Sem item/sem SKU nao e julgado aqui (MUL-310:
                // nasce rascunho e o DraftOrderPromoter decide quando os itens chegarem).
                if (config('imports.mirror_require_catalog_sku')
                    && $this->algumItemResolveNoCatalogoLocal($items) === false) {
                    Log::warning('[JT-022] espelho descartado: nenhum SKU do catalogo local', [
                        'hubai_order_id'       => $hubaiOrderId,
                        'marketplace_order_id' => $marketplaceOrderId,
                        'event'                => $event,
                        'skus'                 => array_values(array_filter(array_map(
                            static fn ($i) => $i['sku'] ?? null,
                            $items
                        ))),
                    ]);
                    return response()->json(['status' => 'discarded_external_catalog']);
                }

                // MUL-310: o guard antigo (MUL-181) RECUSAVA pedido desconhecido sem itens e
                // sem total, respondendo HTTP 200 — o hub gravava 'success' e ninguem via que
                // o pedido nunca chegou. Foram 141 pedidos perdidos so no MultDrop.
                // Agora o pedido e SEMPRE criado, como RASCUNHO, com o motivo registrado:
                // o fornecedor precisa enxergar o pedido mesmo incompleto. Assim que o hub
                // mandar os itens, o DraftOrderPromoter promove.
                $semSubstancia = count($items) === 0 && (float) ($orderData['total'] ?? 0) <= 0;
                $motivoRascunho = $event !== 'order.created'
                    ? 'hub_backfill_' . str_replace('order.', '', $event)
                    : ($semSubstancia ? 'hub_shell_awaiting_items' : 'hub_webhook_incomplete');

                if ($event !== 'order.created' || $semSubstancia) {
                    Log::info('[MUL-310] pedido desconhecido criado como rascunho', [
                        'event'                => $event,
                        'hubai_order_id'       => $hubaiOrderId,
                        'marketplace_order_id' => $marketplaceOrderId,
                        'items'                => count($items),
                        'total'                => $orderData['total'] ?? null,
                        'draft_reason'         => $motivoRascunho,
                    ]);
                }

                // defaults so na criacao — payload parcial em update nao pode zerar valores locais
                $createDefaults = $this->filterByOrdersSchema([
                    'source'           => 'hub_webhook',
                    'status'           => 'pending_payment',
                    'canonical_status' => 'created',
                    'subtotal'         => 0,
                    'shipping_cost'    => 0,
                    'discount_amount'  => 0,
                    'total'            => 0,
                    'currency'         => 'BRL',
                ]);

                // MUL-202: todo pedido criado por relay do hub nasce como rascunho.
                // DraftOrderPromoter tenta promover imediatamente apos syncOrderItems.
                $createDefaults = $this->filterByOrdersSchema(array_merge($createDefaults, [
                    'is_draft'     => 1,
                    // MUL-310: motivo diz de onde veio — casca, backfill de update, ou incompleto
                    'draft_reason' => $motivoRascunho ?? 'hub_webhook_incomplete',
                ]));

                $order = Order::create(array_merge(
                    $createDefaults,
                    array_filter($data, static fn ($v) => $v !== null),
                    ['hubai_order_id' => $hubaiOrderId]
                ));

                // MUL-181: pedido retroativo deve entrar com a data REAL do pedido
                // (created_at do hub), senao a ordenacao por data fica errada
                if (! empty($orderData['created_at'])) {
                    try {
                        $order->created_at = \Illuminate\Support\Carbon::parse($orderData['created_at'])
                            ->setTimezone(config('app.timezone'));
                        $order->saveQuietly();
                    } catch (\Throwable) {
                        // data invalida no payload — mantem timestamp local
                    }
                }
            } else {
                // Letra A (INF-053): allowlist de campos onde NULL vindo do hub significa "apagar valor local".
                // Regra antiga MUL-165 mantida pros demais campos: null = ignora, protege payload parcial.
                // MUL-363 Fase 4: wallet_paid_at/wallet_transaction_id SAIRAM da allowlist E do
                // payload — campos de wallet nao atravessam o espelho em nenhuma direcao
                // (regra 35). A guarda MUL-287/MUL-362 ficou desnecessaria e foi removida.
                $hubClearableFields = [
                    'paid_at',
                    'label_url',
                    // MUL-311: canonical_status SAIU daqui. A coluna e varchar(32) NOT NULL
                    // default 'created' — gravar null nela e impossivel e derrubava o webhook
                    // com HTTP 500 (35 entregas em 31/07/2026, todas order.updated).
                    'supplier_total',
                ];
                $update = [];
                foreach ($data as $k => $v) {
                    if ($v !== null) {
                        $update[$k] = $v;
                    // MUL-311: a checagem era array_key_exists($k, $data), que e SEMPRE verdadeira —
                    // $data e montado com '?? null', entao toda chave existe. Resultado: payload
                    // parcial (o formato plano, por exemplo) era lido como "o hub mandou null de
                    // proposito" e apagava valor local. Agora olha o payload de origem.
                    } elseif (in_array($k, $hubClearableFields, true) && array_key_exists($k, $orderData)) {
                        // hub mandou explicitamente null neste campo — aplicar clear
                        $update[$k] = null;
                    }
                }
                $update['hubai_order_id'] = $hubaiOrderId;
                // INF-053 letra C: marca sync do hub pra Observer nao disparar fanout de volta
                \App\Observers\OrderObserver::$syncingFromHub[$order->id] = true;
                try {
                    $order->fill($update)->save();
                } finally {
                    unset(\App\Observers\OrderObserver::$syncingFromHub[$order->id]);
                }
            }

            // MUL-356: etiqueta pronta apaga carimbo de erro velho.
            //
            // O hub limpa label_status_reason/label_error_at quando o download da certo
            // (FetchShippingLabelJob), mas o clear nao chegava aqui: pela regra MUL-165
            // null no payload significa "ignora", e label_status_reason nao esta na
            // allowlist de campos limpaveis pelo hub. label_error_at nem viaja no payload.
            //
            // Medido em 08/08/2026 no MultDrop: 64 pedidos com a etiqueta pronta seguiam
            // carimbados como tracking_invalid, e 49 linhas da fila presas em failed pelo
            // skip_permanently (MUL-354) — a fila nunca mais tentava. Nao aparecia na tela
            // (o painel esconde o motivo quando ha label_url), mas travava a fila.
            //
            // Etiqueta presente e prova suficiente de que nao ha erro pendente — mesma
            // conclusao que o FetchShippingLabelJob aplica no hub quando o download da certo.
            // Envolvido em try/catch: carimbo velho e ruim, webhook em 500 e pior
            // (MUL-311 derrubou 35 entregas por uma escrita invalida).
            try {
                if (! empty($order->label_url) && ($order->label_status_reason || $order->label_error_at)) {
                    $order->forceFill([
                        'label_status_reason' => null,
                        'label_error_at'      => null,
                    ])->saveQuietly();

                    \Illuminate\Support\Facades\DB::table('order_label_queues')
                        ->where('order_id', $order->id)
                        ->where('status', 'failed')
                        ->update(['status' => 'available', 'error_log' => null, 'updated_at' => now()]);

                    Log::info('[Label] Carimbo de erro limpo - etiqueta espelhada chegou', [
                        'order_id'       => $order->id,
                        'hubai_order_id' => $hubaiOrderId,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('[Label] Falha ao limpar carimbo de erro', [
                    'order_id' => $order->id,
                    'error'    => $e->getMessage(),
                ]);
            }

            // MUL-165: sincroniza items do payload -> order_items local (denormaliza foto)
            if (count($items) > 0) {
                $this->syncOrderItems($order, $items);
                // MUL-235: HUB manda pedido JA explodido (items_exploded=true) — WL so armazena.
                // items_exploded=false explicito = hub sem os kits do cliente (autoridade por
                // cliente) → WL explode local SEM warning. Chave AUSENTE = payload legado
                // (worker hub com codigo antigo) → warning pra rastrear extincao.
                if (empty($orderData['items_exploded'])) {
                    if (!array_key_exists('items_exploded', $orderData)) {
                        Log::warning('HubAI webhook: payload legado sem items_exploded — explosao local fallback (MUL-232)', [
                            'order_id' => $order->id,
                        ]);
                    }
                    try { app(\App\Services\KitExplosionService::class)->explodeOrder($order->fresh()); } catch (\Throwable $e) { \Illuminate\Support\Facades\Log::warning('HubAI webhook: explodeOrder falhou', ['order_id' => $order->id, 'error' => $e->getMessage()]); }
                }
            }

            // MUL-202: tenta promover o rascunho (na criacao E em updates subsequentes).
            // Se o pedido ja e publico (is_draft=0), promote() retorna [true,[]] sem efeito.
            if ($order->is_draft) {
                try {
                    [$promoted, $missing] = app(DraftOrderPromoter::class)->promote($order, 'hub_webhook');
                    Log::info('HubAI webhook: DraftOrderPromoter', [
                        'hubai_order_id' => $hubaiOrderId,
                        'local_order_id' => $order->id,
                        'promoted'       => $promoted,
                        'missing'        => $missing,
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('HubAI webhook: DraftOrderPromoter falhou', [
                        'order_id' => $order->id,
                        'error'    => $e->getMessage(),
                    ]);
                }
            }

            Log::info('HubAI webhook order processed', [
                'event'           => $event,
                'hubai_order_id'  => $hubaiOrderId,
                'local_order_id'  => $order->id,
                'hubai_client_id' => $hubaiClientId,
                'local_client_id' => $localClientId,
                'created'         => $order->wasRecentlyCreated,
                'is_draft'        => (bool) $order->is_draft,
            ]);

            return response()->json([
                'ok'             => true,
                'order_id'       => $order->id,
                'hubai_order_id' => $hubaiOrderId,
                'action'         => $order->wasRecentlyCreated ? 'created' : 'updated',
                'client_mapped'  => $localClientId !== null,
                'is_draft'       => (bool) $order->is_draft,
            ]);

        } catch (\Throwable $e) {
            Log::error('HubAI webhook order failed', [
                'error'           => $e->getMessage(),
                'hubai_order_id'  => $hubaiOrderId,
                'hubai_client_id' => $hubaiClientId,
            ]);
            return response()->json(['error' => 'processing_failed', 'message' => $e->getMessage()], 500);
        } finally {
            // MUL-186: libera o lock anti-race
            if ($lockAcquired) {
                try {
                    $lock->release();
                } catch (\Throwable) {
                }
            }
        }
    }

    /**
     * JT-022: true = ao menos um item resolve no catalogo do fornecedor local
     * (pai direto, filho via client_products, ou kit ativo); false = todos os
     * SKUs conhecidos falharam; null = sem item com SKU (nao julga).
     * Resolucao SEM escopo de client de proposito: falso negativo (deixar
     * passar pedido de seller) e aceitavel; falso positivo (descartar pedido
     * do fornecedor) nao e.
     */
    private function algumItemResolveNoCatalogoLocal(array $items): ?bool
    {
        $skus = [];
        foreach ($items as $it) {
            $sku = trim((string) ($it['sku'] ?? ''));
            // JT-022d: placeholder (ml-*, shopee-*, MLB123...) NAO e SKU do seller —
            // e anuncio SEM sku. Nao entra no julgamento: caso 156599 (Antena, anuncio
            // vinculado so no WL de origem) foi descartado por placeholder. Sem SKU
            // real -> null -> segue MUL-310 (rascunho).
            if ($sku !== '' && ! \App\Support\SkuDoAnuncio::ehPlaceholder($sku)) {
                $skus[] = $sku;
            }
        }
        if ($skus === []) {
            return null;
        }

        // JT-022b: formato ANTIGO de SKU de anuncio (PROD-{produto}-{conta}) — o
        // vinculo com o catalogo esta no ANUNCIO de OUTRO backend, nao no SKU, e
        // este backend nao tem como julgar (FD7E: PROD-440-8922 = D53-MINIMOPTV328).
        // Nao julga: deixa passar. A resolucao real e a via 3 da FOR-131 no hub.
        foreach ($skus as $sku) {
            if (preg_match('/^PROD-\d+-\d+/', $sku)) {
                return null;
            }
        }

        $supplierId = (int) config('app.local_supplier_id');
        if ($supplierId <= 0) {
            return null;
        }

        try {
            $catalogo = [$supplierId];
            if (\Illuminate\Support\Facades\Schema::hasColumn('suppliers', 'parent_supplier_id')) {
                $filhos = \DB::table('suppliers')->where('parent_supplier_id', $supplierId)->pluck('id')->all();
                $catalogo = array_merge($catalogo, $filhos);
            }

            foreach ($skus as $sku) {
                if (\DB::table('products')->whereIn('supplier_id', $catalogo)->where('sku', $sku)->exists()) {
                    return true;
                }
                if (\DB::table('client_products')
                    ->join('products', 'products.id', '=', 'client_products.product_id')
                    ->whereIn('products.supplier_id', $catalogo)
                    ->where('client_products.custom_sku', $sku)
                    ->exists()) {
                    return true;
                }
                if (\Illuminate\Support\Facades\Schema::hasTable('client_kits')
                    && \DB::table('client_kits')->where('sku', $sku)->where('is_active', 1)->exists()) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[JT-022] falha na resolucao de catalogo — deixando passar', ['error' => $e->getMessage()]);
            return null; // na duvida, nao descarta
        }

        return false;
    }

    private function filterByOrdersSchema(array $candidates): array
    {
        static $cachedColumns = null;
        if ($cachedColumns === null) {
            try {
                $cachedColumns = Schema::getColumnListing('orders');
            } catch (\Throwable $e) {
                $cachedColumns = array_keys($candidates);
            }
        }
        $out = [];
        foreach ($candidates as $k => $v) {
            if (in_array($k, $cachedColumns, true)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /**
     * MUL-165: sincroniza items do payload nas order_items locais.
     * Estrategia: apagar items existentes e recriar (idempotente).
     * Tenta mapear product_id via products.sku local; foto denormalizada no product_image.
     */
    private function syncOrderItems($order, array $items): void
    {
        try {
            $orderItemsColumns = \Illuminate\Support\Facades\Schema::getColumnListing('order_items');
            $productsHasSku    = \Illuminate\Support\Facades\Schema::hasColumn('products', 'sku');
            $productMediaExists = \Illuminate\Support\Facades\Schema::hasTable('product_media');

            \App\Models\Order::where('id', $order->id)->firstOrFail();
            \DB::table('order_items')->where('order_id', $order->id)->delete();

            foreach ($items as $it) {
                $sku  = (string) ($it['sku'] ?? '');
                $name = (string) ($it['name'] ?? '');
                // MUL-181: hub nem sempre tem sku (import marketplace) — item com nome ainda vale
                if ($sku === '' && $name === '') continue;

                $productId = null;
                $photo = $it['product_image'] ?? null;

                if ($sku !== '' && $productsHasSku) {
                    $productId = \DB::table('products')->where('sku', $sku)->value('id');
                }

                // Fallback: se webhook nao trouxe foto, tenta buscar por product_id local
                if (!$photo && $productId && $productMediaExists) {
                    $photo = \DB::table('product_media')
                        ->where('product_id', $productId)
                        ->where('type', 'image')
                        ->whereRaw('(url IS NOT NULL OR original_url IS NOT NULL)')
                        ->selectRaw('COALESCE(url, original_url) as u')
                        ->orderBy('id')
                        ->value('u');
                }

                $row = [
                    'order_id'   => $order->id,
                    'sku'        => $sku,
                    'name'       => $name !== '' ? $name : 'Produto',
                    'quantity'   => (int) ($it['quantity'] ?? 1),
                    'unit_price' => (float) ($it['unit_price'] ?? 0),
                    'total'      => (float) ($it['total'] ?? 0),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                if (in_array('product_id', $orderItemsColumns, true) && $productId) $row['product_id'] = $productId;
                if (in_array('external_item_id', $orderItemsColumns, true)) $row['external_item_id'] = $it['external_item_id'] ?? null;
                if (in_array('supplier_unit_cost', $orderItemsColumns, true)) $row['supplier_unit_cost'] = $it['supplier_unit_cost'] ?? null;
                if (in_array('supplier_total_cost', $orderItemsColumns, true)) $row['supplier_total_cost'] = $it['supplier_total_cost'] ?? null;
                if (in_array('product_image', $orderItemsColumns, true)) $row['product_image'] = $photo;
                // MUL-235: preserva flag de componente de kit vinda do hub (payload novo)
                if (in_array('is_kit_component', $orderItemsColumns, true)) $row['is_kit_component'] = !empty($it['is_kit_component']) ? 1 : 0;
                // MUL-339: de-para do kit pelo SKU. O payload traz hub_client_kit_id, que e
                // id do banco do hub e nao vale aqui — por isso 4.273 componentes ficaram
                // marcados como de kit sem apontar para kit nenhum. O SKU e a chave que os
                // dois lados compartilham.
                if (in_array('client_kit_id', $orderItemsColumns, true) && !empty($it['hub_client_kit_sku'])) {
                    $row['client_kit_id'] = \Illuminate\Support\Facades\DB::table('client_kits')
                        ->where('sku', $it['hub_client_kit_sku'])
                        ->when($order->client_id, fn ($q) => $q->where('client_id', $order->client_id))
                        ->value('id');
                }

                \DB::table('order_items')->insert($row);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('HubAI webhook: syncOrderItems falhou', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    private function mapCanonicalToStatus(?string $canonical): string
    {
        return match ($canonical) {
            'created'   => 'pending_payment',
            'paid'      => 'paid',
            'processed' => 'processing',
            'shipped'   => 'shipped',
            'delivered' => 'delivered',
            'cancelled' => 'cancelled',
            'returned'  => 'returned',
            default     => 'pending_payment',
        };
    }
}
