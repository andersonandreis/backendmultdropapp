<?php

/*
|--------------------------------------------------------------------------
| Login — nunca autentica de verdade, so confirma que o endpoint responde
|--------------------------------------------------------------------------
| GET /admin/login  -> pagina Filament (painel /admin, doc 06).
| POST /api/login   -> AuthController::login (Sanctum stateless). Sem
|                      credenciais validas, deve dar 422 (validacao), nunca
|                      autenticar e nunca 5xx.
*/

test('pagina /admin/login responde 200', function () {
    $response = smokeClient()->get('/admin/login');

    expect($response->getStatusCode())->toBe(200);
});

test('POST /api/login sem credenciais nao autentica e nao quebra (422, nunca 5xx)', function () {
    $response = smokeClient()->post('/api/login', [
        'headers' => [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ],
        'body' => '{}',
    ]);

    expect($response->getStatusCode())
        ->toBe(422)
        ->and($response->getStatusCode())->toBeLessThan(500);
});

test('painel /admin responde 200 ou 302 (redirect pro login)', function () {
    $response = smokeClient()->get('/admin');

    expect($response->getStatusCode())->toBeIn([200, 302]);
});
