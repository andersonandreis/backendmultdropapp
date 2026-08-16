<?php

return [

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
     | FOR-101 (MUL-359 Fase A no Fornecefy) -- disk onde a ETIQUETA e gravada.
     |
     | 'public' (padrao)  storage/app/public/labels  -> servido sem auth
     | 'local'            storage/app/private/labels -> so pelo endpoint
     |                    autenticado /orders/{id}/label-file, pelo
     |                    proxyStorageLabel e pela busca WL->hub com
     |                    X-Federation-Secret (leitores preparados em 46d6a107).
     |
     | Por .env de cada backend: os 7 rodam o mesmo repo, e fechar o publico de
     | todos de uma vez quebraria os fronts que ainda nao migraram.
     | Fornecefy = 'local' desde 09/08/2026.
     */
    'labels_disk' => env('LABELS_DISK', 'public'),

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('CDN_URL') ?: env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
