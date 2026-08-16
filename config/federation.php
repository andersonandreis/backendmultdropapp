<?php

/**
 * NOV-171-B/C - Configuracao de Federacao Hub<->WL.
 *
 * Este arquivo funciona nos 4 backends (hub, multdrop, fornecefy, mestoredrop).
 *
 * HUB (api.hubai.io):
 *   - federation.tokens    -- tokens dos WLs (autenticar chamadas WL->hub)
 *   - federation.hub_url   -- URL propria (APP_URL)
 *   - federation.tenant    -- slug deste backend ('hubai')
 *
 * WLs (multdrop, fornecefy, mestoredrop):
 *   - federation.hub_url    -- URL do hub
 *   - federation.hub_token  -- Bearer token para chamar hub endpoints
 *   - federation.hmac_secret -- Secret para validar webhooks recebidos do hub
 *   - federation.tenant     -- slug deste WL (APP_TENANT: 'multdrop', 'fornecefy', etc.)
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Slug deste backend -- usado no anti-loop gate (NOV-171-C)
    |--------------------------------------------------------------------------
    */
    'tenant' => env('APP_TENANT', 'hubai'),

    /*
    |--------------------------------------------------------------------------
    | Tokens de autenticacao dos WLs (Bearer) -- usado APENAS no hub
    |--------------------------------------------------------------------------
    */
    'tokens' => [
        'multdrop'    => env('FEDERATION_TOKEN_MULTDROP'),
        'fornecefy'   => env('FEDERATION_TOKEN_FORNECEFY'),
        'mestoredrop' => env('FEDERATION_TOKEN_MESTOREDROP'),
        'dropksr'     => env('FEDERATION_TOKEN_DROPKSR'),
        'sellerglobal' => env('FEDERATION_TOKEN_SELLERGLOBAL'),
        'jtdrop'       => env('FEDERATION_TOKEN_JTDROP'),
    ],

    /*
    |--------------------------------------------------------------------------
    | URL base do hub
    |--------------------------------------------------------------------------
    */
    'hub_url' => env('FEDERATION_HUB_URL', env('APP_URL', 'https://api.hubai.io')),

    /*
    |--------------------------------------------------------------------------
    | Token Bearer para chamar o hub -- usado APENAS nos WLs
    |--------------------------------------------------------------------------
    */
    'hub_token' => env('FEDERATION_HUB_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Secret HMAC para validar webhooks do hub -- usado APENAS nos WLs
    |--------------------------------------------------------------------------
    */
    'hmac_secret' => env('FEDERATION_HMAC_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Nomes dos eventos de federacao
    |--------------------------------------------------------------------------
    */
    // supplier_id local usado ao receber catalogo federado do hub.
    // Em config/ (nao env() runtime) porque config e cacheada em producao.
    'local_supplier_id' => env('LOCAL_SUPPLIER_ID', 1),

    'catalog_event' => 'federation.catalog.sync',
    'order_event'   => 'federation.order.update',
    'kit_event'     => 'federation.kit.sync',

    // MUL-236 F2: WL com flag ativa encaminha writes de kit pro hub (fonte de verdade)
    'kits_hub_authority' => (bool) env('KITS_HUB_AUTHORITY', false),

    /*
    |--------------------------------------------------------------------------
    | JT-014 — sincronizacao continua de conta de marketplace WL -> hub
    |--------------------------------------------------------------------------
    |
    | Conta conectada na WL precisa existir no hub para o hub puxar o pedido.
    | Isso era feito por comando OneOff rodado a mao: cobriu junho, cobriu
    | julho pela metade, e parou. Resultado medido em 12/08: 858 de 1.206
    | contas ativas fora do hub, e 89% dos pedidos do dia nascendo so na WL,
    | invisiveis para o fornecedor.
    |
    | As credenciais ficam AQUI e nao em env() runtime pelo mesmo motivo do
    | local_supplier_id acima — o config e cacheado em producao e env()
    | devolve nulo. Foi exatamente isso que fez o comando falhar com
    | "HUB_APP_KEY nao definido" mesmo com a chave presente no .env.
    |
    | O cron so liga onde FEDERATION_ACCOUNT_SYNC_CRON=true (repo compartilhado
    | por 7 backends; nos outros a flag fica ausente e o agendamento nao existe).
    |
    */
    'account_sync_cron' => (bool) env('FEDERATION_ACCOUNT_SYNC_CRON', false),
    'hub_app_key'       => env('HUB_APP_KEY'),
    'hub_db_host'       => env('HUB_DB_HOST', '127.0.0.1'),
    'hub_db_port'       => (int) env('HUB_DB_PORT', 3306),
    'hub_db_database'   => env('HUB_DB_DATABASE', 'hubaiapp'),
    'hub_db_username'   => env('HUB_DB_USERNAME'),
    'hub_db_password'   => env('HUB_DB_PASSWORD'),
];