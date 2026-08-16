<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'storage/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_filter(array_map('trim',
        explode(',', env('CORS_ALLOWED_ORIGINS', env('FRONTEND_URL', 'http://localhost')))
    )),
    'allowed_origins_patterns' => [
        '#^https://[a-z0-9\-]+\.lovable\.app$#',
        '#^https://[a-z0-9\-]+\.lovable\.dev$#',
        '#^https://(www\.)?fornecefy\.io$#',
        '#^https://(www\.)?jtdrop\.com\.br$#',
        '#^https://(www\.)?multdrop\.app$#',
        '#^https://(www\.)?mestoredrop\.com\.br$#',
        '#^https://(www\.)?seller\.global$#',
        '#^https://(www\.)?hubai\.io$#',
        '#^http://localhost(:\d+)?$#',
        '#^http://127\.0\.0\.1(:\d+)?$#',
    ],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
