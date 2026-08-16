<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * MUL-339 — o payload do pedido que o hub manda para o WL, montado num lugar so.
 *
 * O hub emitia por dois jobs com formatos diferentes: o FanoutOrderWebhookJob mandava o pedido
 * inteiro em {data:{order:{...}}}, e o DispatchTenantOrderWebhookJob mandava 11 campos na raiz.
 * O WL precisou de um remendo (MUL-310) para aceitar o segundo, depois de ele virar 422 catorze
 * mil vezes so em julho de 2026.
 *
 * O detalhe que doia: o segundo era o unico canal dos importadores de Shopee e Mercado Livre. O
 * caminho por onde os pedidos de verdade chegam era o que mandava menos informacao — sem itens,
 * sem canonical_status, sem supplier_total. Dai a regra "o WL espelha o hub" nunca se cumprir.
 *
 * O codigo aqui foi MOVIDO do fanout, nao reescrito.
 */
final class PayloadDoPedidoParaWl
{
    /**
     * Roda UMA vez por pedido: explode o kit quando a autoridade e do hub e monta a lista de itens.
     *
     * Tem efeito colateral (explodeOrder), entao nao pode ir para dentro do laco de endpoints.
     *
     * @return array{items: array<int,mixed>, hubExplodesKits: bool}
     */
    public static function preparar(Order $order, string $event): array
    {
        // MUL-235: arquitetura alvo = HUB e o unico que explode kits. HOJE (18/07) os
        // client_kits vivem so nos bancos das WLs (hub = 0 rows), entao a autoridade e
        // decidida POR CLIENTE: se o hub tem kits ativos do cliente, explode aqui
        // (idempotente) e marca items_exploded=true (WL nao re-explode). Se nao tem,
        // items_exploded=false e a WL segue explodindo local (fallback MUL-232).
        // Quando o sync de kits WL->HUB existir, o hub assume automaticamente.
        $hubExplodesKits = $order->client_id !== null
            && DB::table('client_kits')
                ->where('client_id', $order->client_id)
                ->where('is_active', 1)
                ->exists();
        if ($hubExplodesKits) {
            try {
                app(\App\Services\KitExplosionService::class)->explodeOrder($order);
            } catch (\Throwable $e) {
                Log::warning('[FanoutOrderWebhookJob] explodeOrder falhou (nao critico)', [
                    'order_id' => $order->id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        // MUL-252: indice SKU-do-anuncio a partir do raw_payload do marketplace
        $rawSkuByItemId = [];
        $rawSkuSingle = null;
        try {
            $rawP = $order->raw_payload;
            if (is_string($rawP)) $rawP = json_decode($rawP, true);
            if (is_string($rawP)) $rawP = json_decode($rawP, true);
            $rawList = [];
            foreach ((array) data_get($rawP, 'item_list', []) as $ri) { // shopee
                $s = trim((string) (($ri['model_sku'] ?? '') ?: ($ri['item_sku'] ?? '')));
                if ($s !== '') $rawList[] = ['id' => $ri['item_id'] ?? null, 'sku' => $s];
            }
            foreach ((array) data_get($rawP, 'order_items', []) as $ri) { // mercado livre
                $s = trim((string) (data_get($ri, 'item.seller_sku') ?: data_get($ri, 'item.seller_custom_field') ?: ''));
                if ($s !== '') $rawList[] = ['id' => data_get($ri, 'item.id'), 'sku' => $s];
            }
            foreach ($rawList as $r) if (!empty($r['id'])) $rawSkuByItemId[(string) $r['id']] = $r['sku'];
            $uniq = array_values(array_unique(array_column($rawList, 'sku')));
            if (count($uniq) === 1) $rawSkuSingle = $uniq[0];
        } catch (\Throwable $e) {
            // raw irregular: segue sem indice
        }

        // MUL-165: items com foto denormalizada via product_media
        $items = DB::table('order_items as oi')
            ->leftJoin('product_media as pm', function ($j) {
                $j->on('pm.product_id', '=', 'oi.product_id')
                  ->where('pm.type', '=', 'image');
            })
            ->where('oi.order_id', $order->id)
            ->groupBy('oi.id', 'oi.sku', 'oi.name', 'oi.quantity', 'oi.unit_price', 'oi.total', 'oi.external_item_id', 'oi.supplier_unit_cost', 'oi.supplier_total_cost', 'oi.product_image', 'oi.is_kit_component', 'oi.client_kit_id')
            ->selectRaw('oi.sku, oi.name, oi.quantity, oi.unit_price, oi.total, oi.external_item_id, oi.supplier_unit_cost, oi.supplier_total_cost, oi.product_image, oi.is_kit_component, oi.client_kit_id, MIN(COALESCE(pm.url, pm.original_url)) as media_url')
            ->get()
            ->map(function ($it) use ($rawSkuByItemId, $rawSkuSingle) {
                return [
                    'sku'                 => $it->sku,
                    'name'                => $it->name,
                    'quantity'            => (int) $it->quantity,
                    'unit_price'          => (float) $it->unit_price,
                    'total'               => (float) $it->total,
                    'external_item_id'    => $it->external_item_id,
                    'supplier_unit_cost'  => $it->supplier_unit_cost !== null ? (float) $it->supplier_unit_cost : null,
                    'supplier_total_cost' => $it->supplier_total_cost !== null ? (float) $it->supplier_total_cost : null,
                    'product_image'       => $it->product_image ?: $it->media_url,
                    // MUL-235: metadados da explosao — WL persiste, nao re-explode
                    'is_kit_component'    => (bool) $it->is_kit_component,
                    'hub_client_kit_id'   => $it->client_kit_id,
                    // MUL-339: o id acima e do banco do HUB e nao vale no WL — os kits
                    // sao os mesmos (6.020 SKUs batendo) mas cada banco tem seus ids, e
                    // o client_id tambem difere. O SKU e a unica chave estavel entre os
                    // dois. Sem ele o WL marcava o item como componente de kit sem saber
                    // de qual kit: 4.273 orfaos, contra zero no hub.
                    'hub_client_kit_sku'  => $it->client_kit_id
                        ? DB::table('client_kits')->where('id', $it->client_kit_id)->value('sku')
                        : null,
                    // MUL-230/MUL-252: SKU do anuncio — cascata kit > legado > raw_payload marketplace.
                    // Fonte unica: sem coluna guardada, apenas propagacao via payload do fanout.
                    'marketplace_sku'     => (function () use ($it, $rawSkuByItemId, $rawSkuSingle) {
                        if ($it->client_kit_id) {
                            $s = DB::table('client_kits')->where('id', $it->client_kit_id)->value('sku');
                            if ($s) return $s;
                        }
                        if ($it->external_item_id) {
                            try {
                                $s = \DB::connection('legacy')->table('produtos')->where('item_id', $it->external_item_id)->value('sku');
                                if ($s) return $s;
                            } catch (\Throwable $e) {
                            }
                            if (isset($rawSkuByItemId[(string) $it->external_item_id])) {
                                return $rawSkuByItemId[(string) $it->external_item_id];
                            }
                        }
                        return $rawSkuSingle;
                    })(),
                ];
            })
            ->all();

        return ['items' => $items, 'hubExplodesKits' => $hubExplodesKits];
    }

    /**
     * Roda uma vez por endpoint: o envelope do evento para aquele tenant.
     *
     * @param  array<int,mixed>  $items  o que `preparar()` devolveu
     */
    public static function montar(
        Order $order,
        array $items,
        bool $hubExplodesKits,
        $tenantId,
        string $event,
        array $extra = []
    ): array {
            $payload = [
                'id'          => 'evt_' . Str::ulid(),
                'event'       => $event,
                'occurred_at' => now()->toIso8601String(),
                'tenant_id'   => $tenantId,
                'data'        => array_merge([
                    'order' => [
                        'id'                     => $order->id,
                        'order_number'           => $order->order_number,
                        'canonical_status'       => $order->canonical_status,
                        'supplier_id'            => $order->supplier_id,
                        'client_id'              => $order->client_id,
                        'total'                  => (float) $order->total,
                        'external_order_id'      => $order->external_order_id,
                        'created_at'             => $order->created_at?->toIso8601String(),
                        'updated_at'             => $order->updated_at?->toIso8601String(),
                        'source'                 => $order->source,
                        'channel_name'           => $order->channel_name,
                        'marketplace_account_id' => $order->marketplace_account_id,
                        'marketplace_order_id'   => $order->marketplace_order_id,
                        'shop_id'                => $order->shop_id,
                        'subtotal'               => (float) ($order->subtotal ?? 0),
                        'shipping_cost'          => (float) ($order->shipping_cost ?? 0),
                        'discount_amount'        => (float) ($order->discount_amount ?? 0),
                        'status'                 => $order->status,
                        'currency'               => $order->currency,
                        // MUL-177: comprador e rastreio nunca iam no payload —
                        // o tenant exibia pedido sem cliente/rastreio pra sempre
                        'customer_name'          => $order->customer_name,
                        'customer_email'         => $order->customer_email,
                        'customer_phone'         => $order->customer_phone,
                        'buyer_username'         => $order->buyer_username,
                        'buyer_nickname'         => $order->buyer_nickname,
                        'tracking_number'        => $order->tracking_number,
                        'label_url'              => $order->label_url,
                        'label_status_reason'    => $order->label_status_reason,
                        'tracking_url'           => $order->tracking_url,
                        'carrier_name'           => $order->carrier_name,
                        'shipping_mode'          => $order->shipping_mode,
                        // MUL-214 item 20: endereco e entrega nunca iam no payload —
                        // WL mostrava pedido sem endereco pra sempre
                        'customer_address'       => $order->customer_address,
                        // MUL-343: shipped_at nunca ia no payload — o WL exibia pedido
                        // entregue sem data de envio, e o backfill de 07/08 (3.227 datas)
                        // ficava preso no hub.
                        'shipped_at'             => $order->shipped_at?->toIso8601String(),
                        'delivered_at'           => $order->delivered_at?->toIso8601String(),
                        'paid_at'                => $order->paid_at?->toIso8601String(),
                        // MUL-237: data real da venda no marketplace
                        "marketplace_created_at" => $order->marketplace_created_at?->toIso8601String(),
                        // MUL-363 Fase 4: campos de WALLET nao atravessam mais o espelho.
                        // Cada backend e dono absoluto do seu ledger e dos seus carimbos
                        // (regra 35 do CLAUDE.md). O flag informativo abaixo diz apenas
                        // "a origem considera pago" — o receptor NUNCA grava wallet_* com ele.
                        // FOR-127: o WL nao tem marketplace_accounts nem clients do hub
                        // (1.372 de 1.372 pedidos espelhados tem account_id NULL), entao o
                        // nome do seller precisa viajar DENTRO do pedido.
                        'wl_seller_name'         => $order->wl_seller_name
                            ?: $order->marketplaceAccount?->wl_client_name,
                        // FOR-128: id do cliente NA WL DE ORIGEM. Sem ele o pedido chega
                        // sem dono e o seller nao ve a propria venda no painel dele.
                        // So a WL de origem pode usar: e id local dela, nao do hub.
                        'wl_client_id'           => $order->marketplaceAccount?->wl_client_id,
                        // FOR-130: referencia do pagamento para o fornecedor auditar
                        'payment_external_id'    => $order->payment_external_id,
                        'payment_method'         => $order->payment_method,
                        'payment_gateway'        => $order->payment_gateway,
                        'origin_wallet_paid'     => $order->wallet_paid_at !== null,
                        'supplier_total'         => $order->supplier_total !== null ? (float) $order->supplier_total : null,
                        // MUL-352: taxa do marketplace, taxa da plataforma, frete e desconto
                        // nunca iam no payload — o WL exibia o financeiro do pedido vazio.
                        'marketplace_fee'        => $order->marketplace_fee !== null ? (float) $order->marketplace_fee : null,
                        'platform_fee'           => $order->platform_fee !== null ? (float) $order->platform_fee : null,
                        'shipping_cost'          => $order->shipping_cost !== null ? (float) $order->shipping_cost : null,
                        'discount_amount'        => $order->discount_amount !== null ? (float) $order->discount_amount : null,
                        'external_shipping_id'   => $order->external_shipping_id,
                        // MUL-352: o payload ORIGINAL do marketplace vai ao WL.
                        // O WL gravava json_encode() do envelope do hub — string dupla-
                        // codificada, JSON_KEYS retornava NULL em 2.788 de 2.788 pedidos.
                        // Agora hub e WL guardam a MESMA coisa: o objeto do marketplace.
                        'raw_payload'            => $order->raw_payload,
                        // MUL-252: NF-e saida + entrada nunca iam no payload — WL exibia campo NF-e vazio pra sempre
                        'invoice_number'         => $order->invoice_number,
                        'invoice_series'         => $order->invoice_series,
                        'invoice_status'         => $order->invoice_status ?? null,
                        'invoice_access_key'     => $order->invoice_access_key,
                        'invoice_issued_at'      => $order->invoice_issued_at,
                        'invoice_url'            => $order->invoice_url,
                        'invoice_xml_url'        => $order->invoice_xml_url,
                        'nfe_entrada_status'     => $order->nfe_entrada_status,
                        'nfe_entrada_access_key' => $order->nfe_entrada_access_key,
                        'nfe_entrada_pdf_url'    => $order->nfe_entrada_pdf_url,
                        'nfe_entrada_xml_url'    => $order->nfe_entrada_xml_url,
                        // MUL-235: true somente quando o HUB tem os kits do cliente e
                        // explodiu aqui — receptor novo entao NAO chama explosao local
                        'items_exploded'         => $hubExplodesKits,
                        'items'                  => $items,
                    ],
                ], $extra),
            ];

        return $payload;
    }
}
