<?php

namespace App\Services\Drop\Shopify;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP para a API REST do Shopify.
 *
 * Rate limiting: máx 2 requests/s (leaky bucket Shopify: 40 créditos, 2/s)
 * Retry automático em 429 com backoff exponencial — máx 3 tentativas
 * Token armazenado criptografado; descriptografado apenas na hora da requisição
 */
class ShopifyApiClient
{
    protected string $shopDomain;
    protected string $encryptedToken;
    protected string $baseUrl;

    /** Timestamp do último request para throttle manual */
    private float $lastRequestAt = 0.0;

    public function __construct(string $shopDomain, string $encryptedToken)
    {
        $this->shopDomain     = $shopDomain;
        $this->encryptedToken = $encryptedToken;
        $this->baseUrl        = "https://{$shopDomain}/admin/api/" . config('drop.shopify_api_version');
    }

    /**
     * GET com query params opcionais.
     */
    public function get(string $endpoint, array $params = []): array
    {
        return $this->request('GET', $endpoint, $params);
    }

    /**
     * POST com body JSON.
     */
    public function post(string $endpoint, array $data): array
    {
        return $this->request('POST', $endpoint, $data);
    }

    /**
     * PUT com body JSON.
     */
    public function put(string $endpoint, array $data): array
    {
        return $this->request('PUT', $endpoint, $data);
    }

    /**
     * DELETE. Retorna true se 2xx, false caso contrário.
     */
    public function delete(string $endpoint): bool
    {
        try {
            $this->request('DELETE', $endpoint);
            return true;
        } catch (\RuntimeException $e) {
            return false;
        }
    }

    /**
     * Itera todas as páginas de um endpoint usando cursor-based pagination (Link header).
     * Yield de cada página como array.
     *
     * @return \Generator<int, array>
     */
    public function paginate(string $endpoint, array $params = []): \Generator
    {
        $url = "{$this->baseUrl}/{$endpoint}";

        while ($url) {
            $this->throttle();

            $response = Http::withToken(decrypt($this->encryptedToken))
                ->get($url, $params);

            // Limpa params após a primeira página — a URL do Link já os contém
            $params = [];

            if ($response->failed()) {
                Log::error('[ShopifyApiClient] paginate falhou', [
                    'shop'   => $this->shopDomain,
                    'url'    => $url,
                    'status' => $response->status(),
                    'body'   => substr($response->body(), 0, 500),
                ]);
                return;
            }

            yield $response->json();

            // Extrai next page do Link header
            $url = $this->extractNextPageUrl($response->header('Link'));
        }
    }

    /**
     * Método base para todas as requisições HTTP.
     * Implementa rate limiting (máx 2 req/s) e retry em 429 com backoff exponencial.
     *
     * @throws \RuntimeException em caso de falha após todas as tentativas
     */
    public function request(string $method, string $endpoint, array $data = []): array
    {
        $url         = "{$this->baseUrl}/{$endpoint}";
        $maxAttempts = 3;
        $attempt     = 0;

        while ($attempt < $maxAttempts) {
            $this->throttle();
            $attempt++;

            $http = Http::withToken(decrypt($this->encryptedToken))
                ->acceptJson()
                ->contentType('application/json');

            $response = match (strtoupper($method)) {
                'GET'    => $http->get($url, $data),
                'POST'   => $http->post($url, $data),
                'PUT'    => $http->put($url, $data),
                'DELETE' => $http->delete($url),
                default  => throw new \InvalidArgumentException("Método HTTP inválido: {$method}"),
            };

            // Sucesso
            if ($response->successful()) {
                return $response->json() ?? [];
            }

            // Rate limit — backoff exponencial antes de retry
            if ($response->status() === 429) {
                $retryAfter = (int) ($response->header('Retry-After') ?? 0);
                $sleep      = max($retryAfter, (int) pow(2, $attempt)); // 2s, 4s, 8s

                Log::warning('[ShopifyApiClient] Rate limit 429 — aguardando', [
                    'shop'        => $this->shopDomain,
                    'endpoint'    => $endpoint,
                    'attempt'     => $attempt,
                    'sleep_secs'  => $sleep,
                ]);

                if ($attempt < $maxAttempts) {
                    sleep($sleep);
                    continue;
                }
            }

            // Erro definitivo após última tentativa (ou não-429)
            Log::error('[ShopifyApiClient] Requisição falhou', [
                'shop'     => $this->shopDomain,
                'method'   => $method,
                'endpoint' => $endpoint,
                'status'   => $response->status(),
                'body'     => substr($response->body(), 0, 500),
            ]);

            throw new \RuntimeException(
                "[ShopifyApiClient] {$method} {$endpoint} => HTTP {$response->status()}: " . substr($response->body(), 0, 300)
            );
        }

        // Nunca deve chegar aqui, mas satisfaz o type checker
        throw new \RuntimeException("[ShopifyApiClient] Máximo de tentativas atingido para {$endpoint}");
    }

    // -------------------------------------------------------------------------
    // Helpers privados
    // -------------------------------------------------------------------------

    /**
     * Garante no máximo 2 requests/s usando microtime como referência.
     * 500ms de intervalo mínimo entre requisições.
     */
    private function throttle(): void
    {
        $now      = microtime(true);
        $elapsed  = $now - $this->lastRequestAt;
        $minGap   = 0.5; // 500ms = 2 req/s

        if ($this->lastRequestAt > 0 && $elapsed < $minGap) {
            $waitUs = (int) (($minGap - $elapsed) * 1_000_000);
            usleep($waitUs);
        }

        $this->lastRequestAt = microtime(true);
    }

    /**
     * Extrai a URL de próxima página do cabeçalho Link do Shopify.
     * Formato: <https://...>; rel="next", <https://...>; rel="previous"
     */
    private function extractNextPageUrl(?string $linkHeader): ?string
    {
        if (!$linkHeader) {
            return null;
        }

        // Exemplo: <https://shop.myshopify.com/admin/api/.../products.json?page_info=abc>; rel="next"
        preg_match('/<([^>]+)>;\s*rel="next"/', $linkHeader, $matches);

        return $matches[1] ?? null;
    }
}
