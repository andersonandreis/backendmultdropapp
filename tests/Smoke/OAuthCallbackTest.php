<?php

/*
|--------------------------------------------------------------------------
| OAuth callbacks — relay central (doc 05), nunca 5xx mesmo sem parametros
|--------------------------------------------------------------------------
| Todas via App\Http\Controllers\Api\OAuthController::callback, publicas
| (sem auth), usadas pelos providers de marketplace via redirect do browser.
| Sem "code"/"state" reais, o controller deve tratar o erro graciosamente
| (redirect 302 com mensagem de erro) e NUNCA estourar 500.
*/

test('/oauth/mercadolivre/callback sem parametros nao retorna 5xx', function () {
    $response = smokeClient()->get('/oauth/mercadolivre/callback');

    expect($response->getStatusCode())->toBeLessThan(500);
});

test('/bling/callback sem parametros nao retorna 5xx', function () {
    $response = smokeClient()->get('/bling/callback');

    expect($response->getStatusCode())->toBeLessThan(500);
});

test('/shopee/oauth-callback sem parametros nao retorna 5xx', function () {
    $response = smokeClient()->get('/shopee/oauth-callback');

    expect($response->getStatusCode())->toBeLessThan(500);
});
