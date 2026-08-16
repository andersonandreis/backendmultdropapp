<?php

/*
|--------------------------------------------------------------------------
| Homepage — GET /
|--------------------------------------------------------------------------
*/

test('homepage responde 200', function () {
    $response = smokeClient()->get('/');

    expect($response->getStatusCode())->toBe(200);
});

test('homepage nao retorna erro de servidor', function () {
    $response = smokeClient()->get('/');

    expect($response->getStatusCode())->toBeLessThan(500);
});
