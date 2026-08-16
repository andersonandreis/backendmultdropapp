<?php

/*
|--------------------------------------------------------------------------
| Health check publico — GET /api/health
|--------------------------------------------------------------------------
| Rota definida em routes/api.php: PublicApiController::health.
| Nao exige autenticacao.
*/

test('/api/health responde 200', function () {
    $response = smokeClient()->get('/api/health');

    expect($response->getStatusCode())->toBe(200);
});

test('/api/health responde JSON valido', function () {
    $response = smokeClient()->get('/api/health');

    $body = (string) $response->getBody();
    json_decode($body);

    expect(json_last_error())->toBe(JSON_ERROR_NONE)
        ->and($body)->not->toBeEmpty();
});
