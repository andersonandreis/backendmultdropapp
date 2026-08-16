<?php

/**
 * INF-066: testes da camada central de resiliencia da API da Anthropic
 * (app/Services/Ai/AnthropicClient.php). Usa Http::fake() -- nao bate na
 * API real, nao gasta credito, nao depende de ANTHROPIC_API_KEY real.
 */

use App\Exceptions\Ai\AnthropicPermanentException;
use App\Exceptions\Ai\AnthropicTransientException;
use App\Services\Ai\AnthropicClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Tests\TestCase;

// Precisa do container Laravel (config(), app(), Http::fake()) -- por
// convencao deste repo, tests/Unit soh bootstrap o framework quando o
// arquivo declara uses(Tests\TestCase::class) explicitamente.
uses(TestCase::class);

beforeEach(function () {
    config([
        'services.anthropic.api_key' => 'test-key-123',
        'services.anthropic.base_url' => 'https://api.anthropic.com',
        'services.anthropic.version' => '2023-06-01',
        'services.anthropic.model' => 'claude-haiku-4-5-20251001',
        'services.anthropic.fallback_model' => '',
        // 1 tentativa inicial + 3 retries -- rapido de testar, mesma logica
        // do default de producao (6 = 1 + 5).
        'services.anthropic.max_attempts' => 4,
        'services.anthropic.max_concurrency' => 2,
        'services.anthropic.slot_wait_ms' => 300,
    ]);
});

function fakeMessage(string $text): array
{
    return ['content' => [['type' => 'text', 'text' => $text]]];
}

it('nao chama a API e lanca excecao permanente quando ANTHROPIC_API_KEY nao esta configurada', function () {
    config(['services.anthropic.api_key' => '']);
    Http::fake();

    expect(fn () => app(AnthropicClient::class)->messages([
        'messages' => [['role' => 'user', 'content' => 'oi']],
    ]))->toThrow(AnthropicPermanentException::class);

    Http::assertNothingSent();
});

it('recupera com sucesso apos 1 falha 529 (retry + backoff funcionam)', function () {
    Sleep::fake();
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 529)
            ->push(fakeMessage('ok depois de 1 falha'), 200),
    ]);

    $result = app(AnthropicClient::class)->messages([
        'messages' => [['role' => 'user', 'content' => 'oi']],
    ]);

    expect($result['content'][0]['text'])->toBe('ok depois de 1 falha');
    Http::assertSentCount(2);
});

it('respeita o maximo de tentativas configurado e prova que nao ha loop infinito em 529 persistente', function () {
    Sleep::fake();
    Http::fake([
        'api.anthropic.com/*' => Http::response(['error' => ['type' => 'overloaded_error']], 529),
    ]);

    $start = microtime(true);

    $exception = null;
    try {
        app(AnthropicClient::class)->messages(['messages' => [['role' => 'user', 'content' => 'oi']]]);
    } catch (AnthropicTransientException $e) {
        $exception = $e;
    }

    $elapsedSeconds = microtime(true) - $start;

    expect($exception)->toBeInstanceOf(AnthropicTransientException::class);
    expect($exception->httpStatus)->toBe(529);
    expect($exception->attempts)->toBe(4); // == max_attempts do teste, nunca mais

    // max_attempts=4 configurado no teste -> exatamente 4 chamadas HTTP e nem uma a mais.
    Http::assertSentCount(4);

    // Sleep::fake() torna os 3 backoffs (2s/4s/8s) instantaneos -- se o client
    // tivesse loop infinito ou ignorasse max_attempts, o teste nao terminaria
    // ou levaria os ~14s reais de espera. Termina em bem menos de 2s = prova
    // de que o loop tem fim.
    expect($elapsedSeconds)->toBeLessThan(2.0);
});

it('NAO repete erro permanente (401 auth invalida) -- 1 unica tentativa', function () {
    Sleep::fake();
    Http::fake([
        'api.anthropic.com/*' => Http::response(['error' => ['type' => 'authentication_error']], 401),
    ]);

    $exception = null;
    try {
        app(AnthropicClient::class)->messages(['messages' => [['role' => 'user', 'content' => 'oi']]]);
    } catch (AnthropicPermanentException $e) {
        $exception = $e;
    }

    expect($exception)->toBeInstanceOf(AnthropicPermanentException::class);
    expect($exception->attempts)->toBe(1);
    Http::assertSentCount(1);
});

it('respeita o header Retry-After em vez do backoff padrao quando ele pede mais tempo', function () {
    Sleep::fake();
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(['error' => ['type' => 'rate_limit_error']], 429, ['Retry-After' => '7'])
            ->push(fakeMessage('ok'), 200),
    ]);

    app(AnthropicClient::class)->messages(['messages' => [['role' => 'user', 'content' => 'oi']]]);

    // backoff padrao da 1a tentativa seria ~2s (1.6-2.4s com jitter); o
    // Retry-After de 7s deve vencer -- prova que o header e respeitado.
    Sleep::assertSequence([
        Sleep::usleep(7_000_000),
    ]);
});

it('erro transitorio esgotado vira excecao tipada sem crash e sem vazar status/erro cru ao usuario final', function () {
    Sleep::fake();
    Http::fake([
        'api.anthropic.com/*' => Http::response('Internal Server Error', 503),
    ]);

    $exception = null;
    try {
        app(AnthropicClient::class)->messages(['messages' => [['role' => 'user', 'content' => 'oi']]]);
    } catch (AnthropicTransientException $e) {
        $exception = $e;
    }

    expect($exception)->toBeInstanceOf(AnthropicTransientException::class);
    expect($exception->userMessage())->toBe('Servico de IA temporariamente indisponivel. Tente novamente em alguns minutos.');
    expect($exception->userMessage())->not->toContain('503');
    expect($exception->httpStatus)->toBe(503);
    expect($exception->attempts)->toBeGreaterThan(1);
});

it('preserva progresso do fluxo chamador: ProductAiController nao quebra quando Claude esgota tentativas, segue pro proximo provider', function () {
    Sleep::fake();
    Http::fake([
        'api.anthropic.com/*' => Http::response(['error' => ['type' => 'overloaded_error']], 529),
        'api.openai.com/*' => Http::response([
            'model' => 'gpt-4o-mini',
            'choices' => [['message' => ['content' => '{"title":"Produto Teste","description":"desc","suggested_category":"1","attributes":{"BRAND":"Sem marca"}}']]],
        ], 200),
    ]);

    config([
        'services.openai.api_key' => 'fake-openai-key',
    ]);

    $controller = app(\App\Http\Controllers\Api\V1\ProductAiController::class);
    $ref = new ReflectionMethod($controller, 'callProvider');
    $ref->setAccessible(true);

    // Chama Claude diretamente via reflection (sem key real) -- deve
    // retornar null (nao lancar, nao vazar excecao) preservando o fluxo do
    // provider chain do controller.
    $callClaude = new ReflectionMethod($controller, 'callClaude');
    $callClaude->setAccessible(true);
    $claudeResult = $callClaude->invoke($controller, 'ignored', 'gere um titulo');

    expect($claudeResult)->toBeNull();

    // O restante do fluxo (proximo provider, GPT) continua funcionando --
    // nenhum trabalho foi perdido por causa do 529 do Claude.
    $gptResult = $ref->invoke($controller, ['name' => 'gpt', 'key' => 'fake-openai-key'], 'gere um titulo');
    expect($gptResult['title'])->toBe('Produto Teste');
});

it('limite de concorrencia: com todas as vagas ocupadas por outras chamadas, segue em modo fail-open sem travar (sem deadlock)', function () {
    Sleep::fake();
    Http::fake([
        'api.anthropic.com/*' => Http::response(fakeMessage('ok'), 200),
    ]);

    // Ocupa as 2 vagas configuradas (max_concurrency=2) simulando 2
    // chamadas paralelas ja em andamento.
    $lock0 = Cache::lock('anthropic_client_slot_0', 90);
    $lock1 = Cache::lock('anthropic_client_slot_1', 90);
    expect($lock0->get())->toBeTrue();
    expect($lock1->get())->toBeTrue();

    try {
        $result = app(AnthropicClient::class)->messages([
            'messages' => [['role' => 'user', 'content' => 'oi']],
        ]);

        // fail-open: mesmo sem vaga livre, a chamada e feita e completa com
        // sucesso -- nao trava a requisicao esperando concorrencia infinita.
        expect($result['content'][0]['text'])->toBe('ok');
    } finally {
        $lock0->forceRelease();
        $lock1->forceRelease();
    }
});
