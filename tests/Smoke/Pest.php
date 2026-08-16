<?php

/*
|--------------------------------------------------------------------------
| Smoke Test Suite Bootstrap (NOV-182 / INF-030 Fase 4)
|--------------------------------------------------------------------------
|
| Suite de smoke tests HTTP puro. NAO faz bootstrap do app Laravel, NAO usa
| Tests\TestCase, NAO abre conexao de banco. Todos os testes falam com o
| ambiente ja no ar via Guzzle standalone (helpers smokeBaseUrl()/
| smokeClient() definidos em tests/Pest.php raiz), contra a URL definida
| em SMOKE_BASE_URL.
|
| Por isso este arquivo NAO chama uses(Tests\TestCase::class)->in(__DIR__).
| Os testes rodam com a classe base padrao do Pest, sem qualquer
| acoplamento ao kernel/DB da aplicacao.
|
*/
