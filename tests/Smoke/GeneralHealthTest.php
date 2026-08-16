<?php

/*
|--------------------------------------------------------------------------
| Sanidade geral do site — rota inexistente e headers basicos
|--------------------------------------------------------------------------
*/

test('rota inexistente retorna 404 (nao 5xx, app esta de pe e roteando)', function () {
    $response = smokeClient()->get('/rota-que-nao-existe-nov182-smoke');

    expect($response->getStatusCode())->toBe(404);
});

test('site responde via HTTPS sem erro de certificado', function () {
    // O client Guzzle usa verify=true (padrao) — se o certificado estivesse
    // invalido ou expirado, a chamada teria lancado ConnectException antes
    // de chegar aqui. Chegar a este ponto ja e a prova.
    $response = smokeClient()->get('/api/health');

    expect($response->getStatusCode())->toBeLessThan(500);
});
