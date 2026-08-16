<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Sync Inventory Job
    |--------------------------------------------------------------------------
    |
    | Quando true, o SyncInventoryJob envia o effective_stock dos produtos
    | para os marketplaces (ML/Magalu/Shopee) sempre que o InventoryObserver
    | detecta mudanca.
    |
    | Default DESLIGADO desde 29/05/2026 apos correcao do bug
    | Product::getEffectiveStockAttribute (regra "zera quando <=10" mascarava
    | o estoque real e pausou ~35k anuncios). Ate decisao manual de religar,
    | o bot do legado (AtualizarEstoqueMarketplace.php) e' a unica fonte que
    | mexe em estoque no marketplace.
    |
    | Ver: feedback_effective_stock_nao_zera + Obsidian HubAI/Projetos/
    |      Relatorio Anuncios Pausados 2026-05-29.md
    |
    */
    'sync_inventory_enabled' => env('MARKETPLACE_SYNC_INVENTORY_ENABLED', false),
];
