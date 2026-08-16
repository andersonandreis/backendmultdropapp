<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Taxa global da plataforma HubAI
    |--------------------------------------------------------------------------
    |
    | Percentual cobrado pela plataforma HubAI sobre cada venda processada.
    | Nivel 1 do PricingCalculator::calculateTotalFees().
    | Define via HUBAI_PLATFORM_FEE_PERCENT no .env (default: 0).
    |
    */
    'platform_fee_percent' => env('HUBAI_PLATFORM_FEE_PERCENT', 0),

    /*
    |--------------------------------------------------------------------------
    | Supplier ID do Hub (multdrop no banco hubaiapp)
    |--------------------------------------------------------------------------
    |
    | MUL-169: usado pelo HubMarketplaceAccount global scope para filtrar
    | marketplace_accounts por supplier_id. Deve estar aqui (config file)
    | e nao em env() direto, pois config:cache torna env() sempre NULL
    | em runtime, ativando o fallback whereRaw('1=0') e zerando a listagem.
    |
    | Define via HUB_SUPPLIER_ID no .env (default: 30).
    |
    */
    'hub_supplier_id' => (int) env('HUB_SUPPLIER_ID', 30),

];
