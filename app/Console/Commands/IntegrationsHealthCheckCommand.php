<?php

namespace App\Console\Commands;

use App\Models\MarketplaceAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class IntegrationsHealthCheckCommand extends Command
{
    protected $signature   = 'integrations:health-check
                                {--platform= : Testar so uma plataforma (shopee|mercadolivre)}
                                {--account=  : Testar so uma conta especifica (ID)}
                                {--json      : Saida em JSON}';

    protected $description = 'Verifica token e API de cada integracao ativa (Shopee + ML)';

    private array $results = [];

    public function handle(): int
    {
        $onlyPlatform = $this->option('platform');
        $onlyAccount  = $this->option('account');
        $jsonOutput   = $this->option('json');

        $query = MarketplaceAccount::where('status', 'active')
                                   ->whereNull('sync_blocked_at');

        if ($onlyPlatform) {
            $query->where('platform', $onlyPlatform);
        } else {
            $query->whereIn('platform', ['shopee', 'mercadolivre']);
        }

        if ($onlyAccount) {
            $query->where('id', (int) $onlyAccount);
        }

        $accounts = $query->get();

        if ($accounts->isEmpty()) {
            $this->line($jsonOutput
                ? json_encode(['status' => 'SEM_CONTAS', 'message' => 'Nenhuma conta ativa encontrada'], JSON_PRETTY_PRINT)
                : '[integrations:health-check] Nenhuma conta ativa encontrada.'
            );
            return self::SUCCESS;
        }

        foreach ($accounts as $account) {
            $result = match ($account->platform) {
                'shopee'       => $this->checkShopee($account),
                'mercadolivre' => $this->checkMercadoLivre($account),
                default        => ['status' => 'IGNORADO', 'reason' => 'plataforma nao suportada'],
            };

            $result['account_id']   = $account->id;
            $result['platform']     = $account->platform;
            $result['account_name'] = $account->account_name ?? '-';
            $result['client_id']    = $account->client_id;

            $this->results[] = $result;
        }

        if ($jsonOutput) {
            $this->line(json_encode($this->results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->renderTable();
        }

        $hasError = collect($this->results)->whereIn('status', ['ERRO_TOKEN', 'ERRO_API'])->isNotEmpty();

        if ($hasError) {
            Log::warning('[IntegrationsHealthCheck] Uma ou mais integracoes com problema', [
                'results' => $this->results,
            ]);
        }

        return $hasError ? self::FAILURE : self::SUCCESS;
    }

    private function checkShopee(MarketplaceAccount $account): array
    {
        $shopId = $account->shop_id;
        $token  = $account->access_token;

        try { if ($token) { $token = decrypt($token); } } catch (\Exception $e) {}

        if (!$shopId || !$token) {
            return [
                'status' => 'ERRO_TOKEN',
                'reason' => 'shop_id ou access_token ausente - OAuth nao concluido',
                'token_expires_at' => $account->token_expires_at?->toDateTimeString(),
            ];
        }

        if ($account->token_expires_at && now()->gte($account->token_expires_at)) {
            return [
                'status'           => 'ERRO_TOKEN',
                'reason'           => 'access_token expirado em ' . $account->token_expires_at->toDateTimeString(),
                'token_expires_at' => $account->token_expires_at->toDateTimeString(),
            ];
        }

        try {
            $partnerId  = (int) config('services.shopee.partner_id');
            $partnerKey = config('services.shopee.partner_key');

            if (!$partnerId || !$partnerKey) {
                return ['status' => 'ERRO_CONFIG', 'reason' => 'SHOPEE_PARTNER_ID ou SHOPEE_PARTNER_KEY nao configurados no .env'];
            }

            $path      = '/api/v2/shop/get_shop_info';
            $timestamp = time();
            $sign      = hash_hmac('sha256', $partnerId . $path . $timestamp . $token . (int) $shopId, $partnerKey);

            $response = Http::timeout(10)->get('https://partner.shopeemobile.com/api/v2/shop/get_shop_info', [
                'partner_id'   => $partnerId,
                'timestamp'    => $timestamp,
                'access_token' => $token,
                'shop_id'      => (int) $shopId,
                'sign'         => $sign,
            ]);

            $body  = $response->json();
            $error = $body['error'] ?? '';

            if ($response->failed() || ($error && $error !== '')) {
                return [
                    'status'      => 'ERRO_API',
                    'reason'      => 'Shopee API: ' . $error . ' - ' . ($body['message'] ?? $response->status()),
                    'http_status' => $response->status(),
                ];
            }

            $shopName = $body['response']['shop_name'] ?? 'desconhecido';
            return [
                'status'           => 'OK',
                'reason'           => 'API respondeu. Loja: ' . $shopName,
                'token_expires_at' => $account->token_expires_at?->toDateTimeString(),
            ];
        } catch (\Exception $e) {
            return ['status' => 'ERRO_API', 'reason' => 'Excecao: ' . $e->getMessage()];
        }
    }

    private function checkMercadoLivre(MarketplaceAccount $account): array
    {
        $token = $account->ml_access_token ?? $account->access_token;

        try { if ($token) { $token = decrypt($token); } } catch (\Exception $e) {}

        if (!$token) {
            return [
                'status'           => 'ERRO_TOKEN',
                'reason'           => 'access_token ausente - OAuth nao concluido',
                'token_expires_at' => ($account->ml_token_expires_at ?? $account->token_expires_at)?->toDateTimeString(),
            ];
        }

        $expiresAt = $account->ml_token_expires_at ?? $account->token_expires_at;
        if ($expiresAt && now()->gte($expiresAt)) {
            return [
                'status'           => 'ERRO_TOKEN',
                'reason'           => 'access_token expirado em ' . $expiresAt->toDateTimeString(),
                'token_expires_at' => $expiresAt->toDateTimeString(),
            ];
        }

        try {
            $response = Http::withToken($token)->timeout(10)->get('https://api.mercadolibre.com/users/me');
            $body     = $response->json();

            if ($response->failed()) {
                return [
                    'status'      => 'ERRO_API',
                    'reason'      => 'ML API: ' . ($body['message'] ?? $body['error'] ?? $response->status()),
                    'http_status' => $response->status(),
                ];
            }

            $nickname = $body['nickname'] ?? 'desconhecido';
            return [
                'status'           => 'OK',
                'reason'           => 'API respondeu. Vendedor: ' . $nickname,
                'token_expires_at' => $expiresAt?->toDateTimeString(),
            ];
        } catch (\Exception $e) {
            return ['status' => 'ERRO_API', 'reason' => 'Excecao: ' . $e->getMessage()];
        }
    }

    private function renderTable(): void
    {
        $this->info('[integrations:health-check] Resultado:');
        $this->table(
            ['ID', 'Plataforma', 'Conta', 'Client', 'Status', 'Detalhe'],
            collect($this->results)->map(fn($r) => [
                $r['account_id'],
                $r['platform'],
                $r['account_name'],
                $r['client_id'],
                $r['status'],
                substr($r['reason'] ?? '', 0, 80),
            ])->toArray()
        );

        $ok    = collect($this->results)->where('status', 'OK')->count();
        $total = count($this->results);
        $this->info($ok . '/' . $total . ' contas OK.');
    }
}
