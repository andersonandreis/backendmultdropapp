<?php

return [
    'hubai' => [
        'allowed_return_domains' => ['hubai.io', 'app.hubai.io'],
        'relay_url'  => env('SHOPEE_RELAY_HUBAI', 'https://api.hubai.io/api/oauth/shopee/hubai-relay'),
        'success_path' => '/painel-cliente/integracoes',
    ],
    'fornecefy' => [
        // FOR-021: api.fornecefy.io adicionado para permitir return_url do painel Filament
        'allowed_return_domains' => ['fornecefy.io', 'app.fornecefy.io', 'api.fornecefy.io'],
        'relay_url'  => env('SHOPEE_RELAY_FORNECEFY', 'https://api.fornecefy.io/api/oauth/shopee/hubai-relay'),
        'success_path' => '/integracoes',
    ],
    'multdrop' => [
        // GOL-031: multdropbr.com e api.multdrop.app adicionados para cobrir dominio customizado WL
        'allowed_return_domains' => ['multdrop.app', 'multdropbr.com', 'app.multdropbr.com', 'api.multdrop.app'],
        'relay_url'  => env('SHOPEE_RELAY_MULTDROP', 'https://api.multdrop.app/api/oauth/shopee/hubai-relay'),
        'success_path' => '/integracoes',
    ],
    'mestoredrop' => [
        // NOV-058-E: MEStoreDrop WL — service isolado, relay para api.mestoredrop.com.br
        'allowed_return_domains' => ['mestoredrop.com.br', 'app.mestoredrop.com.br', 'api.mestoredrop.com.br'],
        'relay_url'  => env('SHOPEE_RELAY_MESTORE', 'https://api.mestoredrop.com.br/api/oauth/shopee/hubai-relay'),
        'success_path' => '/integracoes',
    ],
    'seller-global' => [
        // SEL-076: tenant seller.global — front mandava service=fornecefy e a conta
        // caía no tenant errado; entry própria com relay pro backend api.seller.global
        'allowed_return_domains' => ['seller.global', 'app.seller.global', 'api.seller.global'],
        'relay_url'  => env('SHOPEE_RELAY_SELLERGLOBAL', 'https://api.seller.global/api/oauth/shopee/hubai-relay'),
        'success_path' => '/integracoes',
    ],
    'legado' => [
        'allowed_return_domains' => ['*'],
        'relay_url'  => 'https://goolhub.io/api/bridge/shopee_save_tokens.php',
        'mode' => 'legacy_bridge',
    ],
];
