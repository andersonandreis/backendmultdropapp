<?php

/**
 * Configuracao de Marketplaces suportados pelo HubAI.
 *
 * method:
 *   'direct' — OAuth proprio gerenciado pelo OAuthController/MercadoLivreService/BlingAuthService
 *   'bridge' — Delegado para a API legada goolhub.io via GoolhubBridgeService
 *
 * canal_id: ID do canal no sistema legado goolhub.io (usado apenas para method=bridge)
 */

return [

    'mercadolivre' => [
        'method'   => 'direct',
        'label'    => 'Mercado Livre',
        'canal_id' => 6,
    ],

    'bling' => [
        'method'   => 'direct',
        'label'    => 'Bling ERP',
        'canal_id' => 20, // mantido para referencia, nao usado no metodo direct
    ],

    'shopee' => [
        'method'   => 'direct',
        'label'    => 'Shopee',
        'canal_id' => 3,
    ],

    'magalu' => [
        'method'   => 'direct',
        'label'    => 'Magazine Luiza',
        'canal_id' => 1,
    ],

    'amazon' => [
        'method'   => 'direct',
        'label'    => 'Amazon',
        'canal_id' => 10,
    ],

    'tiktok' => [
        'method'   => 'direct',
        'label'    => 'TikTok Shop',
        'canal_id' => 9,
    ],

    'shopify' => [
        'method'   => 'direct',
        'label'    => 'Shopify',
        'canal_id' => 15,
    ],

];
