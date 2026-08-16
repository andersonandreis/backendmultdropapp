<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Identidade do fornecedor local (MultDrop / whitelabel)
    |--------------------------------------------------------------------------
    |
    | Cada instalacao (api.hubai.io, api.multdrop.app, etc.) representa um
    | fornecedor diferente do ponto de vista do legado.
    |
    | - supplier_id     : id na tabela `suppliers` (banco novo)
    | - legacy_loja_id  : id na tabela `lojas` do legado (id_loja_white)
    | - deposito_id     : id_deposito padrao para chamadas ao legado
    |
    | Em hubai.io (matriz):    supplier_id=30,  legacy_loja_id=565, deposito_id=498
    | Em multdrop.app (white): supplier_id=1,   legacy_loja_id=565, deposito_id=498
    |
    | Defina via env (LOCAL_SUPPLIER_ID, LOCAL_LEGACY_LOJA_ID, LOCAL_DEPOSITO_ID).
    |
    */
    'supplier_id'     => (int) env('LOCAL_SUPPLIER_ID', 30),
    // MUL-281: filial (MultDrop Filial, catalogo D773) — usado no painel admin
    // pra listar catalogo dos 2 suppliers em abas separadas. NAO afeta pedidos.
    'filial_supplier_id' => env('MULTDROP_FILIAL_SUPPLIER_ID') ? (int) env('MULTDROP_FILIAL_SUPPLIER_ID') : null,
    'legacy_loja_id'  => (int) env('LOCAL_LEGACY_LOJA_ID', 565),
    'deposito_id'     => (int) env('LOCAL_DEPOSITO_ID', 498),

];
