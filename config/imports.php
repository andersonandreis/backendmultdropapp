<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Importacao automatica de PEDIDOS
    |--------------------------------------------------------------------------
    |
    | MUL-311 — decisao do Ruan em 31/07/2026: DESLIGADA.
    |
    | Conectar (ou reconectar) uma conta de marketplace disparava
    | ImportMarketplaceAccountDataJob pelo MarketplaceAccountObserver, que puxava
    | o historico de pedidos do seller. Em 31/07 uma conta Bling recem-conectada
    | importou 2.293 pedidos em 30 minutos, com 673 duplicatas.
    |
    | Com esta chave em false, a conexao continua importando PRODUTOS e
    | continua recebendo pedido novo por WEBHOOK — o que para e so a varredura
    | historica de pedidos.
    |
    | Para religar: IMPORT_AUTO_ORDERS_ON_CONNECT=true no .env e
    | `php artisan config:clear` (o config fica cacheado neste servidor).
    |
    */
    'auto_orders_on_connect' => env('IMPORT_AUTO_ORDERS_ON_CONNECT', false),

    /*
    |--------------------------------------------------------------------------
    | Exigir vinculo de fornecedor para importar o pedido (FOR-103)
    |--------------------------------------------------------------------------
    |
    | DESLIGADA por padrao. O repositorio e compartilhado pelos 7 backends e o
    | HUB precisa receber todo pedido — ligar isso la seria perda de dado.
    |
    | Com true, o pedido cujo NENHUM item resolve para um produto de fornecedor
    | nao e criado. E o caso do seller que anuncia produto proprio no mesmo
    | marketplace: a venda e dele, nao passa pelo fornecedor, e hoje entra no
    | banco sem custo, sem NF e apontando para um fornecedor que nao vendeu nada.
    |
    | Ligar SO no backend do WL que quer esse comportamento:
    |   IMPORT_REQUIRE_SUPPLIER_LINK=true  no .env + php artisan config:cache
    |
    | Ler por config('imports.require_supplier_link') — NUNCA por env() em
    | runtime: o config fica cacheado neste servidor e env() devolve vazio.
    |
    */
    'require_supplier_link' => env('IMPORT_REQUIRE_SUPPLIER_LINK', false),

    /*
    | JT-022: portao de catalogo no receptor de fanout. true so em WL de
    | FORNECEDOR (jtdrop): espelho novo cujos itens tem SKU e nenhum resolve
    | no catalogo local e descartado com log. Ler via config(), nunca env().
    */
    'mirror_require_catalog_sku' => (bool) env('MIRROR_REQUIRE_CATALOG_SKU', false),

];
