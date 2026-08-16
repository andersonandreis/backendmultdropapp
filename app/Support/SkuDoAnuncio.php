<?php

namespace App\Support;

use App\Models\ClientProduct;

/**
 * FOR-106 / FOR-111 — o SKU que identifica o ANUNCIO, nao o produto.
 *
 * O sistema e multianuncio: o mesmo produto do mesmo cliente pode ter N anuncios. O SKU
 * antigo identificava o produto, entao colidia — 97 SKUs repetidos em 270 anuncios, o pior
 * caso com 19 anuncios sob o mesmo SKU (PROD-343).
 *
 * Formato:  {sku_do_catalogo}-{id_do_anuncio_em_base32}
 *           D53-LIMPADORMAGICO-231C
 *
 * Por que o id do anuncio: `client_products.id` ja existe, e chave primaria e nasce antes da
 * publicacao. Unico por construcao, sem ler nada antes de escrever — e reversivel, do SKU se
 * chega ao anuncio, e dele ao produto, cliente e conta.
 *
 * Por que base32 Crockford e nao 36 nem 62: Crockford remove I, L, O e U, os caracteres que se
 * confundem na leitura da etiqueta e na digitacao. Cobre 1.048.576 anuncios em 4 caracteres
 * (hoje sao 62.545). Base62 e sensivel a caixa: qualquer etapa que normalize para maiuscula
 * recria a colisao que estamos matando.
 *
 * Por que NAO contador sequencial (-1, -2, -3): para saber que este e o -3 e preciso contar os
 * anuncios existentes, ou seja, ler antes de escrever. O front publica com
 * Promise.all(storeIds.map(...)) — duas lojas em paralelo leem o mesmo numero e geram o mesmo
 * SKU. E se um anuncio for excluido, a numeracao dos outros passa a mentir sobre etiqueta ja
 * impressa e mapeamento ja feito no ERP do seller.
 *
 * ESTE E O UNICO LUGAR QUE GERA SKU DE ANUNCIO. Havia quatro formatos convivendo
 * (AutoListSupplierProductsJob, ClientCatalogService, ProductCloneService e o front em
 * PublishProduct.tsx:463). Politica espalhada foi o que deixou o `total * 0.5` do custo
 * sobreviver 40 dias em dois repositorios (FOR-102). Se precisar de outro formato, mude aqui.
 */
final class SkuDoAnuncio
{
    /** Crockford: sem I, L, O, U. */
    private const ALFABETO = "0123456789ABCDEFGHJKMNPQRSTVWXYZ";

    /** Limite do SELLER_SKU no Mercado Livre. */
    public const MAX = 50;

    /**
     * Placeholders que NAO identificam nada: gerados na importacao de anuncio sem SKU
     * (`ml-MLB123`, `shopee-456`), ou o proprio id do anuncio usado como ultimo recurso
     * pelo pedido (`MLB123`). MUL-216 ja tratava os dois primeiros na leitura do pedido.
     */
    public static function ehPlaceholder(?string $sku): bool
    {
        $sku = trim((string) $sku);

        return $sku === ""
            || (bool) preg_match("/^(ml-|shopee-)/i", $sku)
            || (bool) preg_match("/^(MLB|MLA|MLM)\d+$/i", $sku);
    }

    /** Inteiro -> base32 Crockford. 0 vira "0". */
    public static function base32(int $n): string
    {
        if ($n <= 0) {
            return "0";
        }

        $out = "";
        while ($n > 0) {
            $out = self::ALFABETO[$n % 32] . $out;
            $n = intdiv($n, 32);
        }

        return $out;
    }

    /**
     * O SKU do anuncio. Preserva o que ja existe e e valido — anuncio no ar com SKU
     * funcionando esta mapeado no Bling e na planilha do seller, e trocar quebra o
     * controle dele (FOR-106, NAO-TOCAR).
     */
    public static function paraAnuncio(ClientProduct $cp): string
    {
        if (! self::ehPlaceholder($cp->custom_sku)) {
            return trim((string) $cp->custom_sku);
        }

        return self::gerar(
            $cp->supplier_product_sku ?: $cp->product?->sku,
            (int) $cp->id
        );
    }

    /**
     * Monta {catalogo}-{base32}, truncando a parte do catalogo para caber em MAX.
     * Sem SKU de catalogo (anuncio sem produto pai, 3.113 casos em 13/08/2026) o SKU nasce
     * so com o sufixo: continua unico e continua reversivel ate o anuncio.
     */
    public static function gerar(?string $skuDoCatalogo, int $clientProductId): string
    {
        $sufixo = self::base32($clientProductId);
        $base   = strtoupper(preg_replace("/[^A-Za-z0-9-]/", "", (string) $skuDoCatalogo));

        if ($base === "") {
            return "ANUNCIO-" . $sufixo;
        }

        $espaco = self::MAX - strlen($sufixo) - 1;
        if (strlen($base) > $espaco) {
            $base = substr($base, 0, $espaco);
        }

        return $base . "-" . $sufixo;
    }

    /** Base32 Crockford -> inteiro. null se tiver caractere fora do alfabeto. */
    public static function deBase32(string $s): ?int
    {
        $s = strtoupper(trim($s));
        if ($s === "") {
            return null;
        }
        $n = 0;
        for ($i = 0; $i < strlen($s); $i++) {
            $p = strpos(self::ALFABETO, $s[$i]);
            if ($p === false) {
                return null;
            }
            $n = $n * 32 + $p;
        }
        return $n;
    }

    /**
     * JT-022c: o caminho de VOLTA do formato novo. O sufixo e o id do anuncio
     * (client_products.id); decodifica e verifica ida-e-volta — so confia se
     * regerar o SKU daquele anuncio reproduz EXATAMENTE a string recebida.
     * Protege contra SKU alheio que por acaso termina em segmento base32-valido.
     */
    public static function anuncioDoSku(?string $sku): ?ClientProduct
    {
        $sku = trim((string) $sku);
        $pos = strrpos($sku, "-");
        if ($pos === false) {
            return null;
        }
        $id = self::deBase32(substr($sku, $pos + 1));
        if (! $id || $id > PHP_INT_MAX) {
            return null;
        }
        $cp = ClientProduct::query()->find($id);
        if (! $cp) {
            return null;
        }
        return self::paraAnuncio($cp) === $sku ? $cp : null;
    }
}
