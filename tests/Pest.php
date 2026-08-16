<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Arquivo raiz de configuracao do Pest. tests/Unit e tests/Feature seguem
| o comportamento pre-existente do repo (nao alterado por NOV-182).
|
| As funcoes smokeBaseUrl()/smokeClient() abaixo sao usadas SOMENTE pela
| suite tests/Smoke (NOV-182 / INF-030 Fase 4) — smoke tests HTTP puro
| contra o ambiente ja no ar, sem bootstrap de app/DB. Ficam aqui porque
| Pest so inclui automaticamente o Pest.php da raiz de tests/; um Pest.php
| dentro de tests/Smoke/ nao e auto-incluido para funcoes soltas.
|
*/

if (! function_exists('smokeBaseUrl')) {
    /**
     * Base URL do site a ser testado. Definido via env var SMOKE_BASE_URL.
     * Ex.: SMOKE_BASE_URL=https://api.hubai.io ./vendor/bin/pest tests/Smoke
     */
    function smokeBaseUrl(): string
    {
        $url = getenv('SMOKE_BASE_URL') ?: 'https://api.hubai.io';

        return rtrim($url, '/');
    }
}

if (! function_exists('smokeClient')) {
    /**
     * Cliente Guzzle standalone (sem container Laravel) pra chamadas HTTP
     * puras. http_errors=false pra inspecionar status codes sem exception.
     */
    function smokeClient(): \GuzzleHttp\Client
    {
        return new \GuzzleHttp\Client([
            'base_uri' => smokeBaseUrl(),
            'timeout' => 15,
            'connect_timeout' => 10,
            'http_errors' => false,
            'allow_redirects' => [
                'max' => 5,
                'track_redirects' => true,
            ],
            'verify' => true,
        ]);
    }
}
