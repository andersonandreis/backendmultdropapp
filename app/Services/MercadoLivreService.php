<?php

namespace App\Services;

use App\Models\MarketplaceAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Serviço OAuth dedicado ao Mercado Livre (Authorization Code + PKCE).
 * Distinto de App\Services\Integrations\MercadoLivreService (sync de produtos).
 */
class MercadoLivreService
{
    protected string $baseUrl     = 'https://api.mercadolibre.com';
    protected string $authBaseUrl = 'https://auth.mercadolivre.com.br';

    protected string $appId;
    protected string $secretKey;
    protected string $redirectUri;

    public function __construct()
    {
        $this->appId       = config('services.mercadolivre.app_id',       env('ML_APP_ID'));
        $this->secretKey   = config('services.mercadolivre.secret_key',   env('ML_SECRET_KEY'));
        $this->redirectUri = config('services.mercadolivre.redirect_uri', env('ML_REDIRECT_URI'));
    }

    // -------------------------------------------------------------------------
    // PKCE helpers
    // -------------------------------------------------------------------------

    public function generateCodeVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function generateCodeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    // -------------------------------------------------------------------------
    // OAuth flow
    // -------------------------------------------------------------------------

    /**
     * Gera URL de autorização com PKCE e state = account id.
     */
    public function getAuthUrl(MarketplaceAccount $account): string
    {
        $verifier  = $this->generateCodeVerifier();
        $challenge = $this->generateCodeChallenge($verifier);

        // Salva o verifier na session para validar no callback
        session([
            'ml_code_verifier_' . $account->id => $verifier,
        ]);

        $params = http_build_query([
            'response_type'         => 'code',
            'client_id'             => $this->appId,
            'redirect_uri'          => $this->redirectUri,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
            'state'                 => $account->id,
        ]);

        return "{$this->authBaseUrl}/authorization?{$params}";
    }

    /**
     * Troca o authorization code pelo par access_token + refresh_token.
     */
    public function exchangeCode(string $code, string $codeVerifier): array
    {
        $response = Http::asForm()->post("{$this->baseUrl}/oauth/token", [
            'grant_type'    => 'authorization_code',
            'client_id'     => $this->appId,
            'client_secret' => $this->secretKey,
            'code'          => $code,
            'redirect_uri'  => $this->redirectUri,
            'code_verifier' => $codeVerifier,
        ]);

        if ($response->failed()) {
            Log::error('[ML-OAuth] Falha ao trocar code por token', [
                'status'   => $response->status(),
                'body'     => $response->body(),
            ]);
            throw new \RuntimeException('Falha ao obter token do Mercado Livre: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Renova o access_token usando o refresh_token armazenado na conta.
     *
     * Retorna true em caso de sucesso, false em invalid_grant/erro permanente.
     * Lança RuntimeException apenas para erros temporários (429/502/503/504)
     * para que o ProactiveTokenRefreshCommand não incremente o circuit breaker.
     *
     * (HUB-073, 2026-06-19)
     */
    public function refreshToken(MarketplaceAccount $account): bool
    {
        $refreshToken = decrypt($account->ml_refresh_token);

        $response = Http::asForm()->post("{$this->baseUrl}/oauth/token", [
            'grant_type'    => 'refresh_token',
            'client_id'     => $this->appId,
            'client_secret' => $this->secretKey,
            'refresh_token' => $refreshToken,
        ]);

        if ($response->failed()) {
            $status    = $response->status();
            $body      = $response->body();
            $errorCode = $response->json('error') ?? '';

            // Erros temporários: não incrementar circuit breaker
            if (in_array($status, [429, 502, 503, 504])) {
                Log::warning('[ML-OAuth] Erro temporário ao renovar token', [
                    'account_id' => $account->id,
                    'status'     => $status,
                ]);
                throw new \RuntimeException("Erro temporário ML HTTP {$status}: {$body}");
            }

            // invalid_grant = token genuinamente expirado ou revogado — marcar needs_reauth
            if ($errorCode === 'invalid_grant' || $status === 401) {
                Log::warning('[ML-OAuth] invalid_grant — marcando needs_reauth', [
                    'account_id' => $account->id,
                    'status'     => $status,
                ]);
                $account->update([
                    'status'             => 'needs_reauth',
                    'last_error_message' => mb_substr($body, 0, 255),
                ]);
                return false;
            }

            Log::error('[ML-OAuth] Falha ao renovar token', [
                'account_id' => $account->id,
                'status'     => $status,
                'body'       => $body,
            ]);
            return false;
        }

        $data = $response->json();

        $account->update([
            'ml_access_token'     => encrypt($data['access_token']),
            'ml_refresh_token'    => encrypt($data['refresh_token']),
            'ml_token_expires_at' => now()->addSeconds($data['expires_in'] ?? 21600),
        ]);

        return true;
    }

    /**
     * Busca dados básicos do usuário autenticado na API ML.
     */
    public function getMe(string $token): array
    {
        $response = Http::withToken($token)->get("{$this->baseUrl}/users/me");

        if ($response->failed()) {
            Log::error('[ML-OAuth] Falha ao obter dados do usuário', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException('Falha ao obter dados do usuário ML.');
        }

        return $response->json();
    }

    /**
     * Retorna um access_token válido para a conta, renovando se necessário.
     */
    public function getValidToken(MarketplaceAccount $account): string
    {
        // NOV-061: lazy refresh — se ml_token_expires_at NULL ou expirado, renovar proativamente
        if (!$account->ml_token_expires_at || now()->greaterThanOrEqualTo($account->ml_token_expires_at)) {
            $this->refreshToken($account);
            $account->refresh();
        }

        return decrypt($account->ml_access_token);
    }

    // -------------------------------------------------------------------------
    // Categorias
    // -------------------------------------------------------------------------

    /**
     * Busca categorias raiz do site MLB.
     *
     * @return array [['id' => 'MLB5672', 'name' => 'Acessórios para Veículos'], ...]
     */
    public function getRootCategories(string $token): array
    {
        $response = Http::withToken($token)
            ->get("{$this->baseUrl}/sites/MLB/categories");

        return $response->successful() ? $response->json() : [];
    }

    /**
     * Busca subcategorias de uma categoria pai.
     */
    public function getChildCategories(string $token, string $categoryId): array
    {
        $response = Http::withToken($token)
            ->get("{$this->baseUrl}/categories/{$categoryId}");

        if ($response->failed()) return [];

        return $response->json()['children_categories'] ?? [];
    }

    /**
     * Usa o domain_discovery do ML para predizer a melhor categoria
     * a partir do título/descrição do produto.
     *
     * Retorna array com as categorias sugeridas:
     * [['category_id' => 'MLB277532', 'category_name' => 'Extensores de Torneiras', 'domain_name' => '...'], ...]
     */
    public function predictCategory(string $token, string $query): array
    {
        $response = Http::withToken($token)
            ->get("{$this->baseUrl}/sites/MLB/domain_discovery/search", [
                'q' => $query,
            ]);

        if ($response->failed()) {
            Log::warning('[ML-Category] Falha no domain_discovery', [
                'query'  => $query,
                'status' => $response->status(),
            ]);
            return [];
        }

        return collect($response->json())
            ->map(fn($item) => [
                'category_id'   => $item['category_id'] ?? null,
                'category_name' => $item['category_name'] ?? '',
                'domain_name'   => $item['domain_name'] ?? '',
            ])
            ->filter(fn($item) => $item['category_id'])
            ->values()
            ->toArray();
    }

    /**
     * Busca path completo de uma categoria (breadcrumb).
     * Ex: "Casa, Móveis e Decoração > Banheiro > Torneiras > Extensores"
     */
    public function getCategoryPath(string $token, string $categoryId): string
    {
        $response = Http::withToken($token)
            ->get("{$this->baseUrl}/categories/{$categoryId}");

        if ($response->failed()) return $categoryId;

        $path = $response->json()['path_from_root'] ?? [];

        return collect($path)->pluck('name')->implode(' > ');
    }

    // =========================================================================
    // Edicao de anuncios (NOV-012)
    // =========================================================================

    /**
     * Busca detalhes de um item no Mercado Livre.
     * Retorna payload normalizado: title, description, images, price, stock, attributes.
     */
    public function fetchItemDetail(MarketplaceAccount $account, string $itemId): array
    {
        $token = $this->getValidToken($account);

        $resp = Http::withToken($token)
            ->timeout(10)
            ->get("https://api.mercadolibre.com/items/{$itemId}");

        if ($resp->failed()) {
            Log::error('[ML-Item] Falha ao buscar item', [
                'item_id' => $itemId,
                'status'  => $resp->status(),
                'body'    => $resp->body(),
            ]);
            return ['error' => 'fetch_failed', 'status' => $resp->status(), 'body' => $resp->body()];
        }

        $data = $resp->json();

        // Buscar descricao separada (endpoint distinto no ML)
        $descResp = Http::withToken($token)
            ->timeout(5)
            ->get("https://api.mercadolibre.com/items/{$itemId}/description");
        $description = $descResp->successful() ? ($descResp->json()['plain_text'] ?? null) : null;

        return [
            'title'       => $data['title'] ?? null,
            'description' => $description,
            'images'      => collect($data['pictures'] ?? [])->pluck('url')->toArray(),
            'video_url'   => null, // ML nao expoe via API basica
            'price'       => $data['price'] ?? null,
            'stock'       => $data['available_quantity'] ?? null,
            'attributes'  => $data['attributes'] ?? [],
            'status'      => $data['status'] ?? null,
            'condition'   => $data['condition'] ?? null,
            'category_id' => $data['category_id'] ?? null,
        ];
    }

    /**
     * Atualiza campos de um item no Mercado Livre.
     * Payload aceito: title, price, stock, images, attributes, description.
     */
    public function updateItemDetail(MarketplaceAccount $account, string $itemId, array $payload): bool
    {
        $token = $this->getValidToken($account);

        $mlPayload = array_filter([
            'title'              => $payload['title'] ?? null,
            'price'              => isset($payload['price']) ? (float) $payload['price'] : null,
            'available_quantity' => isset($payload['stock']) ? (int) $payload['stock'] : null,
            'attributes'         => $payload['attributes'] ?? null,
        ], fn ($v) => $v !== null);

        // Imagens
        if (!empty($payload['images'])) {
            $mlPayload['pictures'] = array_map(fn ($url) => ['source' => $url], $payload['images']);
        }

        if (!empty($mlPayload)) {
            $resp = Http::withToken($token)
                ->timeout(10)
                ->put("https://api.mercadolibre.com/items/{$itemId}", $mlPayload);

            if ($resp->failed()) {
                Log::error('[ML-Item] Falha ao atualizar item', [
                    'item_id' => $itemId,
                    'status'  => $resp->status(),
                    'body'    => $resp->body(),
                ]);
                return false;
            }
        }

        // Descricao (endpoint separado no ML)
        if (!empty($payload['description'])) {
            Http::withToken($token)
                ->timeout(10)
                ->put("https://api.mercadolibre.com/items/{$itemId}/description", [
                    'plain_text' => $payload['description'],
                ]);
        }

        return true;
    }

}
