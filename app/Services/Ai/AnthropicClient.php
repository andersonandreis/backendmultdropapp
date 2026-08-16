<?php

namespace App\Services\Ai;

use App\Exceptions\Ai\AnthropicPermanentException;
use App\Exceptions\Ai\AnthropicTransientException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * INF-066: camada CENTRAL de resiliencia para toda chamada a API da
 * Anthropic (Messages API). Qualquer integracao nova com Claude deve
 * passar por aqui -- nao duplicar retry/backoff/concorrencia em outro
 * lugar do projeto.
 *
 * Cobre:
 * - retry automatico SOMENTE em 429/500/502/503/529 (+ timeout/reset de
 *   conexao, tratados como transitorios pelo mesmo motivo)
 * - backoff exponencial com jitter: 2s, 4s, 8s, 16s, 32s
 * - respeita header Retry-After quando presente (cap de 60s)
 * - erro permanente (401/403/400/422/config ausente) NUNCA e repetido
 * - limite de concorrencia (semaforo via Cache::lock, "fail open" --
 *   se nao conseguir uma vaga a tempo, segue sem derrubar a chamada)
 * - maximo de tentativas configuravel, sem loop infinito
 * - log interno detalhado ao esgotar tentativas
 * - nunca deixa o erro cru chegar ao chamador -- so excecoes tipadas com
 *   userMessage() seguro para o usuario final
 * - fallback opcional para outro modelo Claude (ANTHROPIC_FALLBACK_MODEL),
 *   desligado por padrao para nao mudar custo/comportamento sem OK do Ruan
 */
class AnthropicClient
{
    /** Retry APENAS nesses status -- pedido explicito do Ruan (INF-066). */
    private const RETRYABLE_STATUSES = [429, 500, 502, 503, 529];

    /** Erros de configuracao/autenticacao/payload -- nunca repetir. */
    private const PERMANENT_STATUSES = [400, 401, 403, 404, 405, 422];

    /** 2s, 4s, 8s, 16s, 32s -- pedido explicito do Ruan (INF-066). */
    private const BACKOFF_SCHEDULE_MS = [2000, 4000, 8000, 16000, 32000];

    private const MAX_RETRY_AFTER_S = 60;

    public function key(): ?string
    {
        return config('services.anthropic.api_key');
    }

    public function isConfigured(): bool
    {
        return ! empty($this->key());
    }

    private function baseUrl(): string
    {
        return rtrim(config('services.anthropic.base_url') ?: 'https://api.anthropic.com', '/');
    }

    private function version(): string
    {
        return config('services.anthropic.version') ?: '2023-06-01';
    }

    private function defaultModel(): string
    {
        return config('services.anthropic.model') ?: 'claude-haiku-4-5-20251001';
    }

    private function fallbackModel(): ?string
    {
        $fb = config('services.anthropic.fallback_model');

        return $fb !== '' ? $fb : null;
    }

    /** 1 tentativa inicial + 5 retries (2/4/8/16/32s) = 6 por padrao. */
    private function maxAttempts(): int
    {
        return max(1, (int) (config('services.anthropic.max_attempts') ?? 6));
    }

    private function maxConcurrency(): int
    {
        return max(1, (int) (config('services.anthropic.max_concurrency') ?? 3));
    }

    private function slotWaitMs(): int
    {
        return max(0, (int) (config('services.anthropic.slot_wait_ms') ?? 8000));
    }

    /**
     * Chama POST /v1/messages com resiliencia completa. Retorna o array
     * decodificado da resposta da Anthropic em caso de sucesso.
     *
     * @throws AnthropicPermanentException erro que nunca deve ser repetido
     * @throws AnthropicTransientException tentativas esgotadas em erro transitorio
     */
    public function messages(array $body, array $opts = []): array
    {
        if (! $this->isConfigured()) {
            Log::error('[AnthropicClient] chamada sem ANTHROPIC_API_KEY configurada', [
                'endpoint' => $opts['endpoint'] ?? '/v1/messages',
            ]);

            throw new AnthropicPermanentException(
                message: 'anthropic_not_configured',
                httpStatus: null,
                model: $body['model'] ?? $this->defaultModel(),
                endpoint: $opts['endpoint'] ?? '/v1/messages',
            );
        }

        $endpoint = $opts['endpoint'] ?? '/v1/messages';
        $model = $body['model'] ?? $this->defaultModel();
        $body['model'] = $model;
        $body['max_tokens'] = $body['max_tokens'] ?? 512;

        try {
            return $this->attemptWithModel($body, $endpoint, $model);
        } catch (AnthropicTransientException $e) {
            $fallback = $this->fallbackModel();

            if ($fallback === null || $fallback === $model) {
                throw $e;
            }

            Log::warning('[AnthropicClient] tentativas esgotadas no modelo primario, tentando fallback', [
                'from_model' => $model,
                'to_model' => $fallback,
                'endpoint' => $endpoint,
            ]);

            $fallbackBody = $body;
            $fallbackBody['model'] = $fallback;

            return $this->attemptWithModel($fallbackBody, $endpoint, $fallback);
        }
    }

    /**
     * Executa a chamada HTTP com retry/backoff/jitter/Retry-After dentro do
     * limite de concorrencia local. Lanca excecao tipada em vez de deixar o
     * erro cru escapar.
     */
    private function attemptWithModel(array $body, string $endpoint, string $model): array
    {
        $slot = $this->acquireSlot();
        $startedAt = microtime(true);
        $failedAttempts = 0;
        $totalWaitMs = 0;
        $lastRequestId = null;

        try {
            $response = Http::timeout(60)
                ->withHeaders([
                    'x-api-key' => $this->key(),
                    'anthropic-version' => $this->version(),
                    'content-type' => 'application/json',
                ])
                ->retry(
                    $this->maxAttempts(),
                    function (int $attempt, Throwable $e) use (&$totalWaitMs) {
                        $ms = $this->backoffMs($attempt, $e);
                        $totalWaitMs += $ms;

                        return $ms;
                    },
                    function (Throwable $e, PendingRequest $request) use (&$failedAttempts, &$lastRequestId) {
                        $failedAttempts++;
                        [$status, $requestId] = $this->extractStatusAndRequestId($e);
                        $lastRequestId = $requestId ?? $lastRequestId;

                        return $this->isRetryable($status, $e);
                    },
                    false, // nao lanca sozinho -- inspecionamos a resposta final abaixo
                )
                ->post($this->baseUrl().$endpoint, $body);
        } finally {
            $this->releaseSlot($slot);
        }

        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
        $attempts = max($failedAttempts + ($response->successful() ? 1 : 0), 1);
        $requestId = $response->header('request-id') ?: $response->header('x-request-id') ?: $lastRequestId;

        if ($response->successful()) {
            return $response->json() ?? [];
        }

        $status = $response->status();
        $bodyExcerpt = substr($response->body(), 0, 300);

        if (in_array($status, self::PERMANENT_STATUSES, true)) {
            Log::error('[AnthropicClient] erro permanente -- nao sera repetido', [
                'status' => $status,
                'model' => $model,
                'endpoint' => $endpoint,
                'attempts' => $attempts,
                'at' => now()->toIso8601String(),
                'anthropic_request_id' => $requestId,
                'body_excerpt' => $bodyExcerpt,
            ]);

            throw new AnthropicPermanentException(
                message: "anthropic_permanent_error:{$status}",
                httpStatus: $status,
                model: $model,
                endpoint: $endpoint,
                attempts: $attempts,
                totalWaitMs: $totalWaitMs,
                anthropicRequestId: $requestId,
            );
        }

        // Transitorio (429/500/502/503/529 ou desconhecido) com tentativas esgotadas.
        Log::error('[AnthropicClient] tentativas esgotadas apos erro transitorio', [
            'status' => $status,
            'model' => $model,
            'endpoint' => $endpoint,
            'attempts' => $attempts,
            'max_attempts' => $this->maxAttempts(),
            'at' => now()->toIso8601String(),
            'anthropic_request_id' => $requestId,
            'total_wait_ms' => $totalWaitMs,
            'elapsed_ms' => $elapsedMs,
            'body_excerpt' => $bodyExcerpt,
        ]);

        throw new AnthropicTransientException(
            message: "anthropic_transient_error:{$status}",
            httpStatus: $status,
            model: $model,
            endpoint: $endpoint,
            attempts: $attempts,
            totalWaitMs: $totalWaitMs,
            anthropicRequestId: $requestId,
        );
    }

    /**
     * Backoff exponencial (2/4/8/16/32s) + jitter de +-20% + respeito ao
     * Retry-After quando presente (usa o maior dos dois, com teto de 60s).
     */
    private function backoffMs(int $attempt, Throwable $e): int
    {
        $base = self::BACKOFF_SCHEDULE_MS[$attempt - 1] ?? end(self::BACKOFF_SCHEDULE_MS);

        $jitter = (int) round($base * (random_int(-20, 20) / 100));
        $ms = max(500, $base + $jitter);

        $retryAfterMs = $this->retryAfterMs($e);
        if ($retryAfterMs !== null) {
            $ms = max($ms, $retryAfterMs);
        }

        return min($ms, self::MAX_RETRY_AFTER_S * 1000);
    }

    private function retryAfterMs(Throwable $e): ?int
    {
        $response = $this->responseFromException($e);
        if ($response === null) {
            return null;
        }

        $ra = $response->header('Retry-After') ?: $response->header('retry-after');
        if ($ra === null || $ra === '') {
            return null;
        }

        if (is_numeric($ra)) {
            return (int) round(((float) $ra) * 1000);
        }

        $ts = strtotime($ra);
        if ($ts === false) {
            return null;
        }

        return max(0, $ts - time()) * 1000;
    }

    private function isRetryable(?int $status, Throwable $e): bool
    {
        if ($status !== null) {
            return in_array($status, self::RETRYABLE_STATUSES, true);
        }

        // Sem status = falha de rede/timeout/reset. Nao esta na lista literal
        // de status pedida pelo Ruan, mas e claramente transitoria -- tratar
        // como retryable evita perder trabalho por indisponibilidade momentanea
        // de rede (mesmo objetivo do requisito de resiliencia a 529).
        return $e instanceof ConnectionException;
    }

    private function extractStatusAndRequestId(Throwable $e): array
    {
        $response = $this->responseFromException($e);
        if ($response === null) {
            return [null, null];
        }

        return [$response->status(), $response->header('request-id') ?: $response->header('x-request-id')];
    }

    private function responseFromException(Throwable $e): ?Response
    {
        if ($e instanceof RequestException) {
            return $e->response;
        }

        return null;
    }

    // ─── Semaforo de concorrencia (Cache::lock, fail-open) ────────────

    /**
     * Tenta ocupar uma das N vagas (services.anthropic.max_concurrency) por
     * ate slotWaitMs. Se nao conseguir, segue sem vaga (fail-open) e loga --
     * preferimos degradar a lentidao local a travar a requisicao inteira.
     * Retorna a chave da vaga ocupada, ou null se seguiu sem vaga.
     */
    private function acquireSlot(): ?string
    {
        $max = $this->maxConcurrency();
        $deadline = microtime(true) + ($this->slotWaitMs() / 1000);

        do {
            for ($i = 0; $i < $max; $i++) {
                $key = "anthropic_client_slot_{$i}";
                $lock = Cache::lock($key, 90);
                if ($lock->get()) {
                    return $key;
                }
            }
            Sleep::usleep(150_000);
        } while (microtime(true) < $deadline);

        Log::warning('[AnthropicClient] concorrencia saturada -- seguindo sem vaga (fail-open)', [
            'max_concurrency' => $max,
            'waited_ms' => $this->slotWaitMs(),
        ]);

        return null;
    }

    private function releaseSlot(?string $key): void
    {
        if ($key === null) {
            return;
        }

        // restaurar o mesmo objeto de lock para liberar com seguranca
        optional(Cache::lock($key, 90))->forceRelease();
    }
}
