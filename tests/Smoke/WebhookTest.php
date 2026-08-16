<?php

/*
|--------------------------------------------------------------------------
| Webhooks — payload vazio, sem autenticar, sem criar dado nenhum
|--------------------------------------------------------------------------
| Confirmado por curl real contra os 4 sites (06/07/2026):
|   POST /api/webhooks/bling   -> 401 (guard de assinatura/token, esperado)
|   POST /api/webhooks/pagarme -> 200 (aceita e ignora payload vazio)
| Ambos != 5xx, que e o criterio do briefing NOV-182 ("payload vazio ->
| 4xx aceitavel"): o que importa e o endpoint nao quebrar (nunca 500).
*/

test('POST /api/webhooks/bling com payload vazio nao retorna 5xx', function () {
    $response = smokeClient()->post('/api/webhooks/bling', [
        'headers' => ['Content-Type' => 'application/json'],
        'body' => '{}',
    ]);

    expect($response->getStatusCode())->toBeLessThan(500);
});

test('POST /api/webhooks/pagarme com payload vazio nao retorna 5xx', function () {
    $response = smokeClient()->post('/api/webhooks/pagarme', [
        'headers' => ['Content-Type' => 'application/json'],
        'body' => '{}',
    ]);

    expect($response->getStatusCode())->toBeLessThan(500);
});

test('POST /api/webhooks/asaas com payload vazio nao retorna 5xx', function () {
    $response = smokeClient()->post('/api/webhooks/asaas', [
        'headers' => ['Content-Type' => 'application/json'],
        'body' => '{}',
    ]);

    expect($response->getStatusCode())->toBeLessThan(500);
});
