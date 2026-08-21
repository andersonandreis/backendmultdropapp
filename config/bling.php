<?php

return [
    "client_id" => env("BLING_CLIENT_ID"),
    "client_secret" => env("BLING_CLIENT_SECRET"),
    "redirect_uri" => env("BLING_REDIRECT_URI"),

    // MUL-411: app PROPRIO do Multdrop (cadastrado na conta adm.multdrop@gmail.com).
    //
    // Convive com o app antigo acima, de proposito. A divisao e por TIPO DE CONTA:
    //   - erp_accounts (id=1, sincronizacao de estoque de 699 produtos) renova o token
    //     LOCALMENTE com a chave ANTIGA. Trocar ali quebraria a sincronizacao.
    //   - marketplace_accounts (as 11 de hoje) sao centrally_managed: o token vem
    //     pronto do hub via relay, entao nao usam chave nenhuma daqui.
    //   - conexoes NOVAS de marketplace passam a usar este app.
    //
    // Rollback: basta remover BLING_APP_NOVO_* do .env — sem essas variaveis o
    // comportamento volta a ser exatamente o de antes.
    "app_novo" => [
        "client_id"     => env("BLING_APP_NOVO_CLIENT_ID"),
        "client_secret" => env("BLING_APP_NOVO_CLIENT_SECRET"),
        "redirect_uri"  => env("BLING_APP_NOVO_REDIRECT_URI", "https://api.multdrop.app/bling/callback"),
    ],

    "api_base" => "https://api.bling.com.br/Api/v3",
    "auth_url" => "https://www.bling.com.br/Api/v3/oauth/authorize",
    "token_url" => "https://www.bling.com.br/Api/v3/oauth/token",

    // MUL-183: circuit breaker do BlingProductSync — aborta sync que CRIA mais de N
    // produtos novos (sinal de conta/supplier errado ou full import indevido). 0 desliga.
    "max_new_products_per_sync" => (int) env("BLING_MAX_NEW_PRODUCTS_PER_SYNC", 200),

    // MUL-029-2: Bling OAuth Relay (Laravel -> Laravel, espelha padrao Shopee)
    //
    // Como Bling soh aceita 1 redirect_uri por aplicativo (descoberto em MUL-029),
    // todas as WLs (multdrop, fornecefy, hubai) usam a MESMA redirect_uri
    // (api.hubai.io/bling/callback). O hubai.io troca o code por tokens reais
    // (tem o client_secret), depois faz POST HMAC-assinado pra WL de origem.
    //
    // O \"tenant\" eh identificado pelo campo source_system no state OAuth.
    // Endpoints sao mapeados aqui pra evitar SSRF (so endpoints registrados aceitos).
    //
    // Feature flag: BLING_USE_RELAY=true ativa o relay; false mantem comportamento legado
    // (salvar direto em marketplace_accounts do hubai.io).
    "use_relay" => env("BLING_USE_RELAY", false),
    "relay_secret" => env("BLING_RELAY_HMAC_SECRET", ""),
    "app_tenant" => env("APP_TENANT", "hubai"),
    "relay_endpoints" => [
        "multdrop"  => env("BLING_RELAY_ENDPOINT_MULTDROP", "https://api.multdrop.app/api/oauth/bling/wl-relay"),
        "fornecefy" => env("BLING_RELAY_ENDPOINT_FORNECEFY", "https://api.fornecefy.io/api/oauth/bling/wl-relay"),
        "hubai"     => env("BLING_RELAY_ENDPOINT_HUBAI", "https://api.hubai.io/api/oauth/bling/wl-relay"),
        "mestoredrop" => env("BLING_RELAY_ENDPOINT_MESTOREDROP", "https://api.mestoredrop.com.br/api/oauth/bling/wl-relay"),
        // SEL-324: tenants provisionados depois do mapa original
        "sellerglobal" => env("BLING_RELAY_ENDPOINT_SELLERGLOBAL", "https://api.seller.global/api/oauth/bling/wl-relay"),
        "jtdrop" => env("BLING_RELAY_ENDPOINT_JTDROP", "https://api.jtdrop.com.br/api/oauth/bling/wl-relay"),
        "dropksr" => env("BLING_RELAY_ENDPOINT_DROPKSR", "https://api.dropksr.com.br/api/oauth/bling/wl-relay"),
    ],
    /*
    | MUL-427: fallback de etiqueta pelo Bling do seller quando o marketplace
    | recusa o documento (tracking_invalid / label_unavailable). Ler via config().
    */
    'seller_label_fallback' => env('BLING_SELLER_LABEL_FALLBACK', true),

];
