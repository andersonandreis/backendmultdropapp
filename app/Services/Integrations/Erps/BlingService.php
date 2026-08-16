<?php

namespace App\Services\Integrations\Erps;

use App\Models\ErpAccount;
use App\Models\MarketplaceAccount;
use App\Models\Product;
use App\Models\Order;
use App\Services\Integrations\Contracts\ErpInterface;
use App\Services\Integrations\Erps\Bling\BlingApiClient;
use App\Services\Integrations\Erps\Bling\BlingOrderSync;
use App\Services\Integrations\Erps\Bling\BlingProductSync;
use App\Services\Integrations\Erps\Bling\BlingStockPush;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BlingService implements ErpInterface
{
    protected string $baseUrl = 'https://www.bling.com.br/Api/v3';
    protected string $clientId;
    protected string $clientSecret;
    protected string $redirectUri;

    protected ?BlingApiClient $apiClient = null;
    protected ?BlingOrderSync $orderSync = null;
    protected ?BlingProductSync $productSync = null;
    protected ?BlingStockPush $stockPush = null;

    public function __construct()
    {
        $this->clientId = config('services.bling.client_id', env('BLING_CLIENT_ID'));
        $this->clientSecret = config('services.bling.client_secret', env('BLING_CLIENT_SECRET'));
        $this->redirectUri = config('services.bling.redirect_uri', env('BLING_REDIRECT_URI'));
    }

    // ---------------------------------------------------------------
    // Lazy-loaded sub-service accessors
    // ---------------------------------------------------------------

    protected function apiClient(): BlingApiClient
    {
        return $this->apiClient ??= app(BlingApiClient::class);
    }

    protected function orderSync(): BlingOrderSync
    {
        return $this->orderSync ??= app(BlingOrderSync::class);
    }

    protected function productSync(): BlingProductSync
    {
        return $this->productSync ??= app(BlingProductSync::class);
    }

    protected function stockPush(): BlingStockPush
    {
        return $this->stockPush ??= app(BlingStockPush::class);
    }

    // ---------------------------------------------------------------
    // ErpInterface implementation
    // ---------------------------------------------------------------

    public function authenticate(ErpAccount $account): string|array
    {
        $state = $account->id;
        $url = "https://www.bling.com.br/Api/v3/oauth/authorize?client_id={$this->clientId}&response_type=code&state={$state}&redirect_uri={$this->redirectUri}";

        return [
            'status' => 'redirect',
            'url' => $url
        ];
    }

    /**
     * Sync order from HubAI to Bling (export) for NF-e emission.
     * Delegates to BlingOrderSync::exportOrder.
     */
    public function syncOrder(ErpAccount $account, Order $order): bool|array
    {
        $result = $this->orderSync()->exportOrder($account, $order);

        if ($result === false) {
            return false;
        }

        return $result;
    }

    /**
     * Fetch NF-e data from Bling for a given order.
     * Looks up by invoice_number on the Order model, or by the order's bling_order_id.
     */
    public function fetchNfe(ErpAccount $account, Order $order): ?array
    {
        try {
            // Strategy 1: Fetch by invoice number if available
            if ($order->invoice_number) {
                $response = $this->apiClient()->getNfeByNumero($account, $order->invoice_number);
                $nfeList = $response['data'] ?? [];

                if (!empty($nfeList)) {
                    $nfe = is_array($nfeList[0] ?? null) ? $nfeList[0] : $nfeList;
                    return $this->formatNfeResponse($nfe);
                }
            }

            // Strategy 2: If the order has a bling_order_id, fetch order details
            // and extract NF-e reference from the order's transporte/notas
            $blingOrderId = null;

            // Check bling_order_id column
            if (isset($order->bling_order_id) && $order->bling_order_id) {
                $blingOrderId = (int) $order->bling_order_id;
            } elseif ($order->source === 'bling' && $order->external_order_id) {
                $blingOrderId = (int) $order->external_order_id;
            }

            if ($blingOrderId) {
                $orderResponse = $this->apiClient()->getOrder($account, $blingOrderId);
                $orderData = $orderResponse['data'] ?? [];

                // Bling may include nota fiscal info in the order details
                $notas = $orderData['notas'] ?? $orderData['notasFiscais'] ?? [];
                if (!empty($notas)) {
                    $notaId = $notas[0]['id'] ?? null;
                    if ($notaId) {
                        $nfeResponse = $this->apiClient()->getNfe($account, (int) $notaId);
                        $nfe = $nfeResponse['data'] ?? [];
                        return $this->formatNfeResponse($nfe);
                    }
                }
            }

            Log::info('[BlingService] No NF-e found for order', [
                'order_id' => $order->id,
                'invoice_number' => $order->invoice_number,
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::error('[BlingService] fetchNfe failed', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Sync product to/from Bling.
     * - 'export': App -> Bling (create/update product)
     * - 'import': not implemented at facade level (use BlingProductSync::syncAll directly)
     */
    public function syncProduct(ErpAccount|MarketplaceAccount $account, Product $product, string $direction = 'export'): bool|array
    {
        if ($direction === 'export') {
            $blingProductId = $this->productSync()->exportProduct($account, $product);

            if ($blingProductId === null) {
                return false;
            }

            return [
                'success'         => true,
                'bling_product_id' => $blingProductId,
            ];
        }

        // Import direction: not supported via facade (use BlingProductSync::syncAll)
        Log::warning('[BlingService] syncProduct import via facade not supported, use BlingProductSync::syncAll');
        return false;
    }

    // ---------------------------------------------------------------
    // Additional public methods (stock push, NF-e helpers)
    // ---------------------------------------------------------------

    /**
     * Push stock quantity for a product to Bling.
     */
    public function pushStock(ErpAccount|MarketplaceAccount $account, Product $product, int $quantity, ?int $depositId = null): bool
    {
        return $this->stockPush()->pushProductStock($account, $product, $quantity, $depositId);
    }

    // ---------------------------------------------------------------
    // Token management
    // ---------------------------------------------------------------

    /**
     * Get a valid access token, refreshing if expired.
     * Public so BlingApiClient can call it.
     *
     * Duck-typed: aceita ErpAccount (cast `encrypted` automático) ou MarketplaceAccount
     * (encrypt manual no controller). readTokenField() lida com ambos.
     */
    /**
     * NOV-172: para ErpAccount delega para BlingAuthService::getValidTokenForErp
     * (lock anti-corrida + margem 5min). Para MarketplaceAccount mantém lógica existente
     * via readTokenField (usada em contextos legacy externos ao BlingApiClient).
     */
    public function getValidAccessToken(ErpAccount|MarketplaceAccount $account): ?string
    {
        if ($account instanceof ErpAccount) {
            try {
                return app(\App\Services\Integrations\Erps\Bling\BlingAuthService::class)->getValidTokenForErp($account);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('[BlingService] getValidAccessToken (ERP) falhou', [
                    'erp_account_id' => $account->id,
                    'error'          => $e->getMessage(),
                ]);
                return null;
            }
        }

        // MarketplaceAccount — lógica legada mantida para compatibilidade
        $accessToken = $this->readTokenField($account, 'access_token');
        if (! $accessToken) {
            return null;
        }

        $expiresAt = $account->token_expires_at ?? null;
        // Se expira em menos de 5min (300s), refresca preventivamente.
        if ($expiresAt && $expiresAt->copy()->subMinutes(5)->isPast()) {
            return $this->refreshToken($account);
        }

        return $accessToken;
    }

    public function refreshToken(ErpAccount|MarketplaceAccount $account): ?string
    {
        // MUL-133: o refresh token do Bling é rotativo (uso único). Dois refresh
        // concorrentes (schedule × request) fazem o segundo receber invalid_grant
        // falso, derrubando a conta pra needs_reauth sem necessidade — daí o lock.
        $lockKey = 'bling:refresh:' . class_basename($account) . ':' . $account->id;
        $lock = Cache::lock($lockKey, 60);

        if (! $lock->get()) {
            // Outro processo já está renovando — espera terminar e reusa o token dele.
            try {
                $lock->block(20);
                $lock->release();
            } catch (\Throwable $e) {
                Log::warning('[BlingService] refreshToken: timeout aguardando lock concorrente', ['account_id' => $account->id]);
                return null;
            }
            return $this->readTokenField($account->refresh(), 'access_token');
        }

        try {
            return $this->doRefreshToken($account);
        } finally {
            try {
                $lock->release();
            } catch (\Throwable $e) {
                // lock já expirado — ok
            }
        }
    }

    protected function doRefreshToken(ErpAccount|MarketplaceAccount $account): ?string
    {
        $refreshToken = $this->readTokenField($account, 'refresh_token');

        if (! $refreshToken) {
            Log::warning('[BlingService] refreshToken: refresh_token ausente', ['account_id' => $account->id]);
            return null;
        }

        $basicAuth = base64_encode("{$this->clientId}:{$this->clientSecret}");

        $response = Http::withHeaders([
            'Authorization' => "Basic {$basicAuth}",
        ])->post("{$this->baseUrl}/oauth/token", [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        if ($response->failed()) {
            Log::error('[BlingService] refreshToken falhou', [
                'account_id' => $account->id,
                'status'     => $response->status(),
                'body'       => $response->body(),
            ]);
            // MUL-078: erros permanentes (refresh_token expirado/revogado) — marca needs_reauth
            // e bloqueia sync. invalid_grant e' o caso classico do Bling.
            // Incluimos invalid_request e invalid_token como cinto-e-suspensorio.
            $body = $response->body();
            $isPermanentError = $response->status() === 400 && (
                str_contains($body, 'invalid_grant')
                || str_contains($body, 'invalid_request')
                || str_contains($body, 'invalid_token')
            );
            if ($isPermanentError) {
                try {
                    $updateData = ['status' => 'needs_reauth'];
                    // MarketplaceAccount tem campos extras de monitoramento; ErpAccount nao.
                    if ($account instanceof MarketplaceAccount) {
                        $updateData['sync_blocked_at']    = now();
                        $updateData['last_error_message'] = 'Bling refresh permanente: ' . substr($body, 0, 200);
                    }
                    $account->update($updateData);
                    Log::warning('[BlingService] needs_reauth aplicado em refresh permanente', [
                        'account_id' => $account->id,
                        'class'      => get_class($account),
                    ]);
                } catch (\Throwable $e) {
                    // best effort
                }
            }
            return null;
        }

        $data = $response->json();
        $newAccessToken  = $data['access_token']  ?? null;
        $newRefreshToken = $data['refresh_token'] ?? $refreshToken;
        $expiresIn       = (int) ($data['expires_in'] ?? 21600);

        if (! $newAccessToken) {
            Log::error('[BlingService] refreshToken: resposta sem access_token', ['account_id' => $account->id]);
            return null;
        }

        $this->writeTokenFields($account, $newAccessToken, $newRefreshToken, $expiresIn);

        Log::info('[BlingService] Token renovado', ['account_id' => $account->id, 'class' => get_class($account)]);

        return $newAccessToken;
    }

    /**
     * Lê um campo de token (access/refresh) de qualquer dos dois modelos.
     *
     * - ErpAccount usa cast `encrypted` → atributo já volta descriptografado.
     * - MarketplaceAccount salva via encrypt() manual → precisa decrypt().
     *
     * Tenta primeiro o atributo bruto; se parecer payload criptografado (não-JSON,
     * não-token Bling), tenta decrypt(). Compatível com ambos os fluxos.
     */
    private function readTokenField(ErpAccount|MarketplaceAccount $account, string $field): ?string
    {
        $value = $account->{$field} ?? null;
        if (! $value || ! is_string($value)) {
            return null;
        }

        // ErpAccount: cast encrypted já devolveu plain text — usar direto.
        if ($account instanceof ErpAccount) {
            return $value;
        }

        // MarketplaceAccount: pode ter sido salvo via encrypt() ou estar plain text legado.
        try {
            return decrypt($value);
        } catch (DecryptException $e) {
            // Valor já é plain text (legado) — usar direto.
            return $value;
        }
    }

    /**
     * Persiste tokens novos respeitando o padrão de cada modelo.
     */
    private function writeTokenFields(
        ErpAccount|MarketplaceAccount $account,
        string $accessToken,
        string $refreshToken,
        int $expiresIn
    ): void {
        if ($account instanceof ErpAccount) {
            // Cast encrypted cuida da criptografia.
            $account->update([
                'access_token'     => $accessToken,
                'refresh_token'    => $refreshToken,
                'token_expires_at' => now()->addSeconds($expiresIn),
                'status'           => 'active',
            ]);
            return;
        }

        // MarketplaceAccount: encrypt manual + também atualiza campos bling_* legados.
        // MUL-133: refresh OK = conta saudável. Sem limpar sync_blocked_at aqui, um
        // needs_reauth transitório vira bloqueio órfão e o schedule (whereNull
        // sync_blocked_at) exclui a conta do sync pra sempre.
        $fields = [
            'access_token'           => encrypt($accessToken),
            'refresh_token'          => encrypt($refreshToken),
            'token_expires_at'       => now()->addSeconds($expiresIn),
            'bling_access_token'     => encrypt($accessToken),
            'bling_refresh_token'    => encrypt($refreshToken),
            'bling_token_expires_at' => now()->addSeconds($expiresIn),
            'last_token_refresh_at'  => now(),
            'sync_blocked_at'        => null,
            'last_error_message'     => null,
        ];
        // Só reativa quem estava needs_reauth — não ressuscita conta desativada manualmente.
        if ($account->status === 'needs_reauth') {
            $fields['status'] = 'active';
        }
        $account->update($fields);
    }

    // ---------------------------------------------------------------
    // Private helpers
    // ---------------------------------------------------------------

    /**
     * Format raw NF-e data from Bling into a standardized response.
     */
    protected function formatNfeResponse(array $nfe): array
    {
        return [
            'id'              => $nfe['id'] ?? null,
            'numero'          => $nfe['numero'] ?? null,
            'serie'           => $nfe['serie'] ?? null,
            'chaveAcesso'     => $nfe['chaveAcesso'] ?? null,
            'situacao'        => $nfe['situacao'] ?? null,
            'xml'             => $nfe['xml'] ?? null,
            'pdf_url'         => $nfe['linkDanfe'] ?? $nfe['linkPDF'] ?? null,
            'xml_url'         => $nfe['linkXml'] ?? $nfe['linkXML'] ?? null,
            'dataEmissao'     => $nfe['dataEmissao'] ?? null,
        ];
    }
}
