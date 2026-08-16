<?php

namespace App\Console\Commands;

use App\Jobs\PropagateShopeeTokenJob;
use App\Models\MarketplaceAccount;
use App\Services\InstallationConfig;
use App\Services\Integrations\Marketplaces\ShopeeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * NOV-181: handoff da cadeia de tokens Shopee de uma WL pro hub central.
 *
 * Roda NO HUB. Fluxo por loja:
 *   1. Importa a cadeia atual da WL (POST bridge-export, HMAC)
 *   2. Grava na conta local do hub (por shop_id)
 *   3. Renova na Shopee — o hub vira o dono da cadeia (refresh_token e single-use)
 *   4. Propaga a cadeia nova pra todas as WLs espelho (sincrono)
 *   5. Marca centrally_managed=true na WL de origem
 *
 * Se o refresh falhar, NADA e marcado na WL — ela continua dona da cadeia.
 *
 * Uso:
 *   php artisan shopee:bridge-handoff --wl-url=https://api.multdrop.app --shop-id=1582700354
 *   php artisan shopee:bridge-handoff --wl-url=https://api.multdrop.app --shop-id=123 --dry-run
 */
class ShopeeBridgeHandoffCommand extends Command
{
    protected $signature = 'shopee:bridge-handoff
                            {--wl-url= : URL base da WL de origem (ex: https://api.multdrop.app)}
                            {--shop-id= : shop_id Shopee da loja}
                            {--dry-run : So consulta a WL e mostra o estado, sem executar}';

    protected $description = 'Importa a cadeia de tokens Shopee de uma WL pro hub central e passa a gerencia-la (NOV-181)';

    public function handle(InstallationConfig $config): int
    {
        if (! $config->isHub()) {
            $this->error('Este comando so roda no HUB (installation.role=hub). Use installation:setup.');

            return self::FAILURE;
        }

        $wlUrl  = rtrim((string) $this->option('wl-url'), '/');
        $shopId = (string) $this->option('shop-id');

        if ($wlUrl === '' || $shopId === '') {
            $this->error('--wl-url e --shop-id sao obrigatorios.');

            return self::FAILURE;
        }

        $secret = (string) config('services.shopee.bridge_secret', '');
        if ($secret === '') {
            $this->error('SHOPEE_BRIDGE_SECRET ausente no .env do hub.');

            return self::FAILURE;
        }

        // 1. Exportar cadeia da WL
        $export = $this->signedPost($secret, "{$wlUrl}/api/oauth/shopee/bridge-export", ['shop_id' => $shopId]);

        if (! is_array($export) || empty($export['success'])) {
            $this->error('bridge-export falhou na WL: ' . json_encode($export, JSON_UNESCAPED_UNICODE));

            return self::FAILURE;
        }

        $this->table(['Campo', 'Valor (WL)'], [
            ['account_id', $export['account_id'] ?? '-'],
            ['status', $export['status'] ?? '-'],
            ['centrally_managed', ($export['centrally_managed'] ?? false) ? 'SIM' : 'nao'],
            ['token_expires_at', $export['token_expires_at'] ?? '-'],
            ['refresh_token_expires_at', $export['refresh_token_expires_at'] ?? '-'],
            ['last_token_refresh_at', $export['last_token_refresh_at'] ?? '-'],
        ]);

        if (empty($export['refresh_token'])) {
            $this->error('WL nao tem refresh_token pra esta loja — handoff impossivel.');

            return self::FAILURE;
        }

        // 2. Conta local do hub
        $account = MarketplaceAccount::where('platform', 'shopee')
            ->where('shop_id', $shopId)
            ->orderByDesc('id')
            ->first();

        if (! $account) {
            $this->error("Hub nao tem conta shopee com shop_id={$shopId}. Crie/conecte a conta no hub antes do handoff.");

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->warn("[DRY-RUN] Handoff NAO executado. Conta hub alvo: id={$account->id} status={$account->status}.");

            return self::SUCCESS;
        }

        // 3. Importar cadeia na conta do hub
        $fields = [
            'access_token'             => encrypt((string) $export['access_token']),
            'refresh_token'            => encrypt((string) $export['refresh_token']),
            'token_expires_at'         => $export['token_expires_at'],
            'refresh_token_expires_at' => $export['refresh_token_expires_at'],
            'status'                   => 'active',
            'sync_errors_count'        => 0,
            'sync_blocked_at'          => null,
        ];
        if (Schema::hasColumn('marketplace_accounts', 'refresh_errors_count')) {
            $fields['refresh_errors_count'] = 0;
        }
        $account->update($fields);

        $this->info("Cadeia importada na conta hub id={$account->id}. Renovando na Shopee (hub vira dono)...");

        // 4. Refresh — hub assume a cadeia
        try {
            $newToken = app(ShopeeService::class)->refreshToken($account);
        } catch (\Throwable $e) {
            $newToken = null;
            $this->error('refreshToken lancou excecao: ' . $e->getMessage());
        }

        if (! $newToken) {
            $this->error('Refresh FALHOU — a WL continua dona da cadeia (nada foi marcado). Investigar antes de repetir.');
            Log::error('[BridgeHandoff] refresh falhou apos import', ['shop_id' => $shopId, 'account_id' => $account->id]);

            return self::FAILURE;
        }

        $account->refresh();
        $this->info("Hub renovou OK. Novo token_expires_at={$account->token_expires_at}.");

        // 5. Propagar cadeia nova pras WLs espelho (sincrono, deterministico)
        PropagateShopeeTokenJob::dispatchSync($account->id);
        $this->info('Cadeia nova propagada pras WLs espelho (bridge.wl_urls).');

        // 6. Marcar centrally_managed na WL de origem
        $mark = $this->signedPost($secret, "{$wlUrl}/api/oauth/shopee/bridge-mark-managed", [
            'shop_id' => $shopId,
            'managed' => true,
        ]);

        if (! is_array($mark) || empty($mark['success'])) {
            $this->error('ATENCAO: propagacao OK mas bridge-mark-managed falhou na WL — marcar manualmente! ' . json_encode($mark));

            return self::FAILURE;
        }

        Log::info('[BridgeHandoff] Handoff concluido', [
            'shop_id'    => $shopId,
            'wl'         => $wlUrl,
            'account_id' => $account->id,
        ]);

        $this->info("Handoff concluido: hub e o dono da cadeia do shop_id={$shopId}; WL marcada centrally_managed ({$mark['updated']} conta(s)).");

        return self::SUCCESS;
    }

    private function signedPost(string $secret, string $url, array $payload): mixed
    {
        $body = json_encode($payload);
        $sig  = hash_hmac('sha256', $body, $secret);

        try {
            $response = Http::timeout(20)
                ->withHeaders([
                    'X-HubAI-Bridge-Sig' => $sig,
                    'Content-Type'       => 'application/json',
                ])
                ->withBody($body, 'application/json')
                ->post($url);

            return $response->json() ?? ['http_status' => $response->status(), 'body' => substr($response->body(), 0, 300)];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
