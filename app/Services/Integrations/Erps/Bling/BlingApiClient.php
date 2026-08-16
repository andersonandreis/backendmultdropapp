<?php

namespace App\Services\Integrations\Erps\Bling;

use App\Models\ErpAccount;
use App\Models\MarketplaceAccount;
use App\Services\Integrations\Erps\BlingService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\Response;

class BlingApiClient
{
    protected string $baseUrl;

    public function __construct(
        protected ?BlingAuthService $authService = null
    ) {
        $this->baseUrl = config("bling.api_base", "https://www.bling.com.br/Api/v3");
    }

    // ---------------------------------------------------------------
    // Token resolution — supports both ErpAccount and MarketplaceAccount
    // ---------------------------------------------------------------

    /**
     * Resolve a valid access token from either ErpAccount or MarketplaceAccount.
     */
    protected function resolveToken(ErpAccount|MarketplaceAccount $account): string
    {
        if ($account instanceof MarketplaceAccount) {
            if (!$this->authService) {
                $this->authService = app(BlingAuthService::class);
            }
            return $this->authService->getValidToken($account);
        }

        // ErpAccount — usa BlingAuthService::getValidTokenForErp (lock anti-corrida + margem 5min)
        // NOV-172: unificado aqui para evitar duplicidade com BlingService::getValidAccessToken
        if (!$this->authService) {
            $this->authService = app(BlingAuthService::class);
        }
        return $this->authService->getValidTokenForErp($account);
    }

    // ---------------------------------------------------------------
    // HTTP verbs with rate-limit handling and logging
    // ---------------------------------------------------------------

    /**
     * GET request with auto-auth, rate-limit retry, and logging.
     */
    public function get(ErpAccount|MarketplaceAccount $account, string $endpoint, array $query = []): array
    {
        return $this->request('GET', $account, $endpoint, query: $query);
    }

    /**
     * POST request with auto-auth, rate-limit retry, and logging.
     */
    public function post(ErpAccount|MarketplaceAccount $account, string $endpoint, array $data = []): array
    {
        return $this->request('POST', $account, $endpoint, data: $data);
    }

    /**
     * PUT request with auto-auth, rate-limit retry, and logging.
     */
    public function put(ErpAccount|MarketplaceAccount $account, string $endpoint, array $data = []): array
    {
        return $this->request('PUT', $account, $endpoint, data: $data);
    }

    /**
     * PATCH request with auto-auth, rate-limit retry, and logging.
     */
    public function patch(ErpAccount|MarketplaceAccount $account, string $endpoint, array $data = []): array
    {
        return $this->request('PATCH', $account, $endpoint, data: $data);
    }

    /**
     * DELETE request with auto-auth, rate-limit retry, and logging.
     */
    public function delete(ErpAccount|MarketplaceAccount $account, string $endpoint): array
    {
        return $this->request('DELETE', $account, $endpoint);
    }

    // ---------------------------------------------------------------
    // Core request engine
    // ---------------------------------------------------------------

    /**
     * Execute an HTTP request against the Bling v3 API.
     *
     * - Resolves Bearer token from the account
     * - Retries up to 3 times on 429 (rate limit) with exponential back-off
     * - Logs every request and response at debug level
     * - Throws RuntimeException on non-retriable failures
     */
    protected function request(
        string $method,
        ErpAccount|MarketplaceAccount $account,
        string $endpoint,
        array $data = [],
        array $query = [],
        int $maxRetries = 3,
    ): array {
        $token = $this->resolveToken($account);
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($endpoint, '/');

        $attempt = 0;

        while (true) {
            $attempt++;

            $pending = Http::withToken($token)
                ->acceptJson()
                ->timeout(30);

            $response = match (strtoupper($method)) {
                'GET'    => $pending->get($url, $query),
                'POST'   => $pending->post($url, $data),
                'PUT'    => $pending->put($url, $data),
                'PATCH'  => $pending->patch($url, $data),
                'DELETE' => $pending->delete($url),
                default  => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
            };

            Log::debug("[BlingApiClient] {$method} {$endpoint}", [
                'attempt'  => $attempt,
                'status'   => $response->status(),
                'query'    => $query ?: null,
                'payload'  => $data ?: null,
                'response' => mb_substr($response->body(), 0, 1000),
            ]);

            // Rate limit — 429: back off and retry
            if ($response->status() === 429) {
                if ($attempt >= $maxRetries) {
                    throw new \RuntimeException(
                        "Bling rate limit exceeded after {$maxRetries} retries on {$method} {$endpoint}"
                    );
                }

                $delay = $attempt * 1; // 1s, 2s, 3s
                Log::warning("[BlingApiClient] Rate limited, retrying in {$delay}s", [
                    'attempt' => $attempt,
                    'endpoint' => $endpoint,
                ]);
                sleep($delay);
                continue;
            }

            // Token expired mid-flight (401) — force-refresh once and retry
            // NOV-172: ambos os tipos usam BlingAuthService (lock anti-corrida)
            if ($response->status() === 401 && $attempt === 1) {
                Log::info("[BlingApiClient] 401 received, force-refreshing token and retrying");
                if (!$this->authService) {
                    $this->authService = app(BlingAuthService::class);
                }
                try {
                    if ($account instanceof ErpAccount) {
                        // Forcar refresh imediato: zerar token_expires_at temporariamente nao e seguro;
                        // em vez disso, chamar refreshToken diretamente via BlingAuthService
                        $tokenData = $this->authService->refreshToken((string) $account->refresh_token);
                        $this->authService->saveTokensForErp($account, $tokenData);
                        $token = $tokenData['access_token'];
                    } else {
                        $token = $this->authService->getValidToken($account);
                    }
                    continue;
                } catch (\Throwable $refreshEx) {
                    Log::warning("[BlingApiClient] 401 refresh failed: " . $refreshEx->getMessage(), [
                        'account_id' => $account->id,
                        'class'      => get_class($account),
                    ]);
                    // deixar cair no failed() abaixo
                }
            }

            if ($response->failed()) {
                // HUB-182: 404 em GET = recurso deletado no Bling (esperado, caller trata) — warning.
                $level = ($response->status() === 404 && $method === 'GET') ? 'warning' : 'error';
                Log::log($level, "[BlingApiClient] Request failed", [
                    'method'   => $method,
                    'endpoint' => $endpoint,
                    'status'   => $response->status(),
                    'body'     => mb_substr($response->body(), 0, 2000),
                ]);

                throw new \RuntimeException(
                    "Bling API error [{$response->status()}] on {$method} {$endpoint}: " . mb_substr($response->body(), 0, 500)
                );
            }

            // Respect the 3 req/sec limit — sleep 340ms between calls
            usleep(340000);

            return $response->json() ?? [];
        }
    }

    // ---------------------------------------------------------------
    // Convenience shortcuts for common endpoints
    // ---------------------------------------------------------------

    public function listProducts(ErpAccount|MarketplaceAccount $account, int $page = 1): array
    {
        return $this->get($account, "/produtos", ["pagina" => $page, "limite" => 100]);
    }

    public function getProduct(ErpAccount|MarketplaceAccount $account, int $id): array
    {
        return $this->get($account, "/produtos/{$id}");
    }

    public function createProduct(ErpAccount|MarketplaceAccount $account, array $data): array
    {
        return $this->post($account, "/produtos", $data);
    }

    public function updateProduct(ErpAccount|MarketplaceAccount $account, int $id, array $data): array
    {
        return $this->put($account, "/produtos/{$id}", $data);
    }

    public function listOrders(ErpAccount|MarketplaceAccount $account, int $page = 1, ?string $startDate = null, ?int $idLoja = null): array
    {
        $params = ["pagina" => $page, "limite" => 100];
        if ($startDate) {
            $params["dataInicial"] = $startDate;
        }
        // MUL-138: a v3 filtra por loja/canal via idLoja SINGULAR — o idsIntegracoes[]
        // usado antes (MUL-082) não existe na API e era silenciosamente ignorado,
        // fazendo o Bling devolver pedidos de TODOS os canais.
        if ($idLoja) {
            $params["idLoja"] = $idLoja;
        }
        return $this->get($account, "/pedidos/vendas", $params);
    }

    /**
     * MUL-082: lista canais de venda (integracoes) configurados no Bling.
     * Usado no /integracoes do MultDrop para o seller escolher de quais canais importar.
     * Bling v3: GET /canais-venda -> {data: [{id, descricao, situacao}]}
     */
    public function listSalesChannels(ErpAccount|MarketplaceAccount $account): array
    {
        return $this->get($account, "/canais-venda");
    }

    public function getOrder(ErpAccount|MarketplaceAccount $account, int $id): array
    {
        return $this->get($account, "/pedidos/vendas/{$id}");
    }

    public function createOrder(ErpAccount|MarketplaceAccount $account, array $data): array
    {
        return $this->post($account, "/pedidos/vendas", $data);
    }

    public function listStock(ErpAccount|MarketplaceAccount $account, int $page = 1, array $productIds = []): array
    {
        // Bling v3 /estoques/saldos exige idsProdutos — sem filtro retorna VALIDATION_ERROR.
        // Se nao ha IDs, retorna vazio sem fazer request.
        if (empty($productIds)) {
            return ["data" => []];
        }
        // idsProdutos precisa ir como ARRAY. Juntar com virgula devolve so o primeiro produto
        // do lote — a resposta vem 200, sem erro, com os outros faltando (medido em 06/08:
        // 3 ids -> 1 linha no formato antigo, 3 linhas passando array).
        $params = ["pagina" => $page, "idsProdutos" => array_map("intval", array_values($productIds))];
        return $this->get($account, "/estoques/saldos", $params);
    }

    public function createStock(ErpAccount|MarketplaceAccount $account, array $data): array
    {
        return $this->post($account, "/estoques", $data);
    }

    public function listDeposits(ErpAccount|MarketplaceAccount $account): array
    {
        return $this->get($account, "/depositos");
    }

    public function getNfe(ErpAccount|MarketplaceAccount $account, int $id): array
    {
        return $this->get($account, "/nfe/{$id}");
    }

    public function getNfeByNumero(ErpAccount|MarketplaceAccount $account, string $numero): array
    {
        return $this->get($account, "/nfe", ["numero" => $numero]);
    }

    // ---------------------------------------------------------------
    // MUL-096: Account plan
    // ---------------------------------------------------------------

    /**
     * MUL-096: Busca dados do plano Bling da conta autenticada.
     *
     * GET /usuarios/me retorna info do usuario incluindo plano.
     * Cache de 6h para evitar bater na API a cada refresh de tela.
     *
     * Retorna:
     *   plan_name   string|null  Nome do plano (ex: "Profissional", "Essencial")
     *   expires_at  string|null  Data de expiracao no formato "YYYY-MM-DD"
     *   store_id    int|null     ID da loja no Bling
     *   store_name  string|null  Nome da loja
     *   user_name   string|null  Nome do usuario
     *   user_email  string|null  Email do usuario
     *   fetched_at  string       ISO8601 de quando foi buscado
     */
    public function getAccountPlan(ErpAccount|MarketplaceAccount $account): array
    {
        $cacheKey = 'bling_account_plan_'
            . ($account instanceof MarketplaceAccount ? 'ma' : 'erp')
            . '_' . $account->id;

        // MUL-360 item 15: sucesso cacheia 6h; erro cacheia so 2min. Antes o erro ficava
        // 6h no cache e o painel continuava vazio mesmo depois do token renovado/reconectado.
        $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $response = $this->get($account, '/usuarios/me');
            $data  = $response['data'] ?? [];
            $loja  = $data['loja'] ?? [];
            $plano = $loja['plano'] ?? [];

            $result = [
                'plan_name'   => $plano['nome'] ?? null,
                'expires_at'  => $plano['dataExpiracao'] ?? null,
                'store_id'    => $loja['id'] ?? null,
                'store_name'  => $loja['nome'] ?? null,
                'user_name'   => $data['nome'] ?? null,
                'user_email'  => $data['email'] ?? null,
                'user_status' => $data['situacao'] ?? null,
                'fetched_at'  => now()->toIso8601String(),
            ];
            \Illuminate\Support\Facades\Cache::put($cacheKey, $result, 6 * 3600);
        } catch (\Throwable $e) {
            Log::warning('[BlingApiClient] getAccountPlan failed', [
                'account_id' => $account->id,
                'error'      => $e->getMessage(),
            ]);
            $result = [
                'plan_name'  => null,
                'expires_at' => null,
                'error'      => $e->getMessage(),
                'fetched_at' => now()->toIso8601String(),
            ];
            \Illuminate\Support\Facades\Cache::put($cacheKey, $result, 120);
        }

        return $result;
    }
}
