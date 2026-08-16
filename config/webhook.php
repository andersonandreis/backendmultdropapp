<?php
return [
    // INF-034: quando true, POST /webhooks/* enfileira WebhookIngestJob
    // (worker webhook-ingest) e retorna 200 em <5ms. Quando false, processa
    // sincronamente (modo legado).
    'async_mode' => env('WEBHOOK_ASYNC_MODE', false),
];
