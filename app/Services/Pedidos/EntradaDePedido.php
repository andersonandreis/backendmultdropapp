<?php

namespace App\Services\Pedidos;

use App\Models\ClientKit;
use App\Models\ClientProduct;
use App\Models\MarketplaceAccount;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Support\Facades\Cache;

/**
 * FOR-131 — ponto unico de entrada de pedido de marketplace.
 *
 * REGRA DE NEGOCIO (ditada pelo Ruan em 14/08/2026):
 *
 *   "todo pedido que marca um fornecedor, o sistema tem que buscar no catalogo daquele
 *    fornecedor. Multdrop tem multdrop e multdrop filial como sub-catalogo, JTDrop tem
 *    apenas JTDrop. Se o SKU do pedido nao tiver um SKU pai que seja do catalogo do
 *    JTDrop, pode descartar o pedido sem nem processar, so mantem o log — ele
 *    provavelmente e do catalogo externo do seller."
 *
 *   "os itens que chegam no pedido sao SKU filho ou de kit; o sistema monta os itens
 *    internos buscando SKU pai ou a composicao do kit. O webhook ja tem o id da loja, e
 *    por ele achamos o fornecedor daquela loja."
 *
 * O FORNECEDOR VEM DA LOJA. `marketplace_accounts.supplier_id` significa "esta loja compra
 * deste fornecedor" e serve para ROTEAR. Nao significa "esta mercadoria e dele" — foi essa
 * confusao que gerou a FOR-121/JT-013 e o roteamento morto de 14/08.
 *
 * Este servico NAO grava nada. Ele resolve e decide; quem grava e o chamador.
 *
 * Extraido de WebhookOrderService (1.130 linhas, 32 tickets cravados). A logica de
 * resolucao e a mesma — se o comportamento mudar em algum ponto, e bug, nao feature.
 */
class EntradaDePedido
{
    public const PROCESSAR = 'processar';
    public const DESCARTAR = 'descartar';
    public const RETER     = 'reter';

    /**
     * Catalogo de um fornecedor: ele proprio + os sub-catalogos (parent_supplier_id).
     * JTDrop (13) nao tem filhos. Multdrop (30) tem o 157, Multdrop Filial.
     */
    public function catalogoDoFornecedor(int $supplierId): array
    {
        return Cache::remember("for131:catalogo:{$supplierId}", 300, function () use ($supplierId) {
            $ids = [$supplierId];
            $filhos = Supplier::withoutGlobalScopes()
                ->where('parent_supplier_id', $supplierId)
                ->pluck('id')
                ->all();

            return array_values(array_unique(array_merge($ids, $filhos)));
        });
    }

    /**
     * Resolve UM sku do pedido dentro do catalogo do fornecedor da loja.
     *
     * Tres tentativas, nesta ordem:
     *   1. SKU pai direto      products.sku
     *   2. SKU filho           client_products.custom_sku -> product_id
     *   3. SKU de kit          client_kits.sku -> client_kit_items -> product_id
     *
     * Devolve null quando o SKU nao pertence ao catalogo daquele fornecedor — ou seja,
     * quando e catalogo externo do seller.
     *
     * @return array{product_id:int,supplier_id:int,origem:string,kit_id:?int}|null
     */
    public function resolverSku(?string $sku, MarketplaceAccount $conta, ?string $idDoAnuncio = null): ?array
    {
        $sku = trim((string) $sku);
        if (! $conta->supplier_id) {
            return null;
        }

        $catalogo = $this->catalogoDoFornecedor((int) $conta->supplier_id);
        $skuUtil  = $sku !== '' && ! $this->ehPlaceholder($sku);

        // 1. SKU pai direto
        $pai = ! $skuUtil ? null : Product::withoutGlobalScopes()
            ->where('sku', $sku)
            ->whereIn('supplier_id', $catalogo)
            ->first(['id', 'supplier_id']);
        if ($pai) {
            return ['product_id' => $pai->id, 'supplier_id' => $pai->supplier_id,
                    'origem' => 'pai', 'kit_id' => null];
        }

        // 2. SKU filho (anuncio do seller aponta o pai)
        $filho = ! $skuUtil ? null : ClientProduct::withoutGlobalScopes()
            ->where('custom_sku', $sku)
            ->whereNotNull('product_id')
            ->when($conta->id, fn ($q) => $q->orderByRaw(
                'CASE WHEN marketplace_account_id = ? THEN 0 ELSE 1 END', [$conta->id]
            ))
            ->first(['id', 'product_id']);
        if ($filho) {
            $p = Product::withoutGlobalScopes()
                ->whereKey($filho->product_id)
                ->whereIn('supplier_id', $catalogo)
                ->first(['id', 'supplier_id']);
            if ($p) {
                return ['product_id' => $p->id, 'supplier_id' => $p->supplier_id,
                        'origem' => 'filho', 'kit_id' => null];
            }
        }

        // 3. ID DO ANUNCIO no marketplace -> anuncio nosso -> produto pai.
        //    Descoberta em 14/08 confrontando o servico com 499 pedidos reais: 22 de 40
        //    divergencias vinham daqui. O seller usa SKU proprio no marketplace
        //    (PROD-347-489) e o vinculo com o catalogo esta no ANUNCIO, nao no SKU.
        //    Sem esta via a trava descartaria 11% dos pedidos legitimos.
        $anuncioId = trim((string) ($idDoAnuncio ?? ''));
        if ($anuncioId !== '') {
            $cp = ClientProduct::withoutGlobalScopes()
                ->where('external_listing_id', $anuncioId)
                ->whereNotNull('product_id')
                ->when($conta->id, fn ($q) => $q->orderByRaw(
                    'CASE WHEN marketplace_account_id = ? THEN 0 ELSE 1 END', [$conta->id]
                ))
                ->first(['id', 'product_id']);
            if ($cp) {
                $p = Product::withoutGlobalScopes()
                    ->whereKey($cp->product_id)
                    ->whereIn('supplier_id', $catalogo)
                    ->first(['id', 'supplier_id']);
                if ($p) {
                    return ['product_id' => $p->id, 'supplier_id' => $p->supplier_id,
                            'origem' => 'anuncio', 'kit_id' => null];
                }
            }
        }

        // 4. SKU de kit — hoje so o multdrop tem kit (6.020, source_tenant=multdrop);
        //    o Fornecefy tem 0. O ramo existe para quando a trava chegar la.
        $clientId = $conta->client_id ?: $conta->wl_client_id;
        if ($skuUtil && $clientId) {
            $kit = ClientKit::withoutGlobalScopes()
                ->where('client_id', $clientId)
                ->where('sku', $sku)
                ->where('is_active', true)
                ->first(['id']);
            if ($kit) {
                // basta UM componente do catalogo para o kit ser nosso
                $componente = ClientProduct::withoutGlobalScopes()
                    ->whereIn('id', function ($q) use ($kit) {
                        $q->select('client_product_id')->from('client_kit_items')
                          ->where('kit_id', $kit->id);
                    })
                    ->whereNotNull('product_id')
                    ->pluck('product_id');
                $p = Product::withoutGlobalScopes()
                    ->whereIn('id', $componente)
                    ->whereIn('supplier_id', $catalogo)
                    ->first(['id', 'supplier_id']);
                if ($p) {
                    return ['product_id' => $p->id, 'supplier_id' => $p->supplier_id,
                            'origem' => 'kit', 'kit_id' => $kit->id];
                }
            }
        }

        return null;
    }

    /**
     * Decide o pedido inteiro a partir dos SKUs crus.
     *
     *   PROCESSAR  ao menos um SKU resolveu no catalogo do fornecedor da loja
     *   DESCARTAR  todos os SKUs sao conhecidos e nenhum resolveu -> catalogo externo
     *   RETER      ha SKU ausente/placeholder -> ainda nao da para julgar, nasce rascunho
     *
     * RETER existe porque o cron de varredura recebe payload sem seller_sku: medido em
     * 14/08, 301 de 406 payloads trazem itens e apenas 117 trazem SKU. Julgar sem SKU
     * seria descartar venda boa.
     *
     * @param  array<int,array{sku:?string,anuncio:?string}|string>  $itens
     * @return array{decisao:string,motivo:?string,resolvidos:array,supplier_id:?int}
     */
    public function decidir(MarketplaceAccount $conta, array $itens): array
    {
        if (! $conta->supplier_id) {
            return ['decisao' => self::RETER, 'motivo' => 'loja sem fornecedor definido',
                    'resolvidos' => [], 'supplier_id' => null];
        }

        $resolvidos = [];
        $semReferencia = 0;

        foreach ($itens as $item) {
            // aceita string (so sku) ou array ['sku'=>, 'anuncio'=>]
            $sku     = is_array($item) ? (string) ($item['sku'] ?? '') : (string) $item;
            $anuncio = is_array($item) ? (string) ($item['anuncio'] ?? '') : '';
            $skuUtil = trim($sku) !== '' && ! $this->ehPlaceholder(trim($sku));

            if (! $skuUtil && trim($anuncio) === '') {
                $semReferencia++;   // nada para julgar: nem SKU nem anuncio
                continue;
            }
            $r = $this->resolverSku($sku, $conta, $anuncio);
            if ($r) {
                $resolvidos[$sku !== '' ? $sku : $anuncio] = $r;
            }
        }

        if ($resolvidos !== []) {
            return ['decisao' => self::PROCESSAR, 'motivo' => null,
                    'resolvidos' => $resolvidos,
                    'supplier_id' => (int) collect($resolvidos)->first()['supplier_id']];
        }

        if ($semReferencia > 0) {
            return ['decisao' => self::RETER,
                    'motivo' => "{$semReferencia} item(ns) sem SKU nem id de anuncio no payload",
                    'resolvidos' => [], 'supplier_id' => null];
        }

        return ['decisao' => self::DESCARTAR,
                'motivo' => 'nenhum SKU pertence ao catalogo do fornecedor da loja',
                'resolvidos' => [], 'supplier_id' => null];
    }

    /**
     * SKU que na verdade e o id do anuncio, nao um SKU: MLB123, ml-MLB123, shopee-456.
     * Nesses o seller nao cadastrou SKU no marketplace — medido em 14/08: 10.205 anuncios
     * nossos com placeholder.
     */
    public function ehPlaceholder(string $sku): bool
    {
        return (bool) preg_match('/^(ml-|shopee-|tiktok-)/i', $sku)
            || (bool) preg_match('/^(MLB|MLA|MLM)\d+$/i', $sku);
    }
}
