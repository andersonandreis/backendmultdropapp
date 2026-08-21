<?php

namespace App\Jobs;

use App\Models\MarketplaceAccount;
use App\Services\Integrations\Erps\Bling\BlingProductSync;
use App\Services\Integrations\Erps\Bling\BlingOrderSync;
use App\Services\Integrations\Erps\Bling\BlingStockPush;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncBlingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800; // MUL-091: 30 min (sync inicial com muitos pedidos)
    public int $tries = 3; // HUB-xxx FIX-3: aumentado de 2 para 3 (para erros transitórios reais)
    public bool $failOnTimeout = true; // HUB-xxx FIX-3: timeout vai direto para failed_jobs sem retry (nao gera MaxAttempts)

    // HUB-xxx FIX-3: backoff progressivo — mais tempo entre tentativas para respeitar Bling rate limit
    public function backoff(): array
    {
        return [120, 300, 600]; // 2min, 5min, 10min
    }

    public function __construct(
        protected int $accountId,
        protected string $syncType = "all" // "products", "orders", "stock", "all"
    ) {}

    public function handle(BlingProductSync $productSync, BlingOrderSync $orderSync, BlingStockPush $stockPush): void
    {
        $account = MarketplaceAccount::find($this->accountId);

        if (!$account || !$account->bling_access_token) {
            Log::warning("SyncBlingJob: account not found or not connected", ["id" => $this->accountId]);
            return;
        }

        // INF-029-B: pular contas bloqueadas ou marcadas como needs_reauth
        if ($account->needs_reauth) {
            Log::info("SyncBlingJob: conta needs_reauth — skip (aguarda reconexao do seller)", [
                "account_id" => $this->accountId,
                "name" => $account->account_name,
            ]);
            return;
        }

        if ($account->sync_blocked_at) {
            Log::info("SyncBlingJob: conta sync_blocked_at definido — skip", [
                "account_id" => $this->accountId,
            ]);
            return;
        }

        // INF-029-B: verificar token expirado sem refresh_token disponivel (sair limpo)
        if ($account->token_expires_at && $account->token_expires_at->isPast()) {
            if (empty($account->bling_refresh_token) && empty($account->refresh_token)) {
                Log::warning("SyncBlingJob: token expirado sem refresh_token — marcando needs_reauth", [
                    "account_id" => $this->accountId,
                    "token_expires_at" => $account->token_expires_at,
                ]);
                $account->update(['needs_reauth' => true]);
                return;
            }
        }

        $results = [];

        try {
            if (in_array($this->syncType, ["products", "all"])) {
                $results["products"] = $productSync->syncAll($account);
                Log::info("Bling product sync done", $results["products"]);
            }

            if (in_array($this->syncType, ["stock", "all"])) {
                $results["stock"] = $productSync->syncStock($account);
                Log::info("Bling stock sync done", $results["stock"]);
            }

            if (in_array($this->syncType, ["orders", "all"])) {
                // MUL-212 F2: guard por instalacao (banco) — so o trecho de PEDIDOS;
                // products/stock seguem normais
                $cfg = app(\App\Services\InstallationConfig::class);
                if ($cfg->pullsOrders('bling')) { // fix: WL puxa contas centrally_managed do Bling (hub nao puxa Bling - MUL-311)
                    $results["orders"] = $orderSync->syncAll($account);
                    Log::info("Bling order sync done", $results["orders"]);
                } else {
                    Log::info("SyncBlingJob: pull de pedidos desativado nesta instalacao (MUL-212 F2) — skip orders", [
                        "account_id" => $this->accountId,
                    ]);
                }
            }

            if (in_array($this->syncType, ["push_stock"])) {
                $results["push_stock"] = $stockPush->pushAll($account);
                Log::info("Bling stock push done", $results["push_stock"]);
            }

            Log::info("SyncBlingJob complete", ["account" => $this->accountId, "results" => $results]);
        } catch (\RuntimeException $e) {
            // INF-029-B: erro 401 irrecuperavel do BlingApiClient = marcar needs_reauth, nao retentar
            if (str_contains($e->getMessage(), '[401]') || str_contains($e->getMessage(), 'Unauthorized')) {
                Log::warning("SyncBlingJob: 401 irrecuperavel — marcando needs_reauth e abortando", [
                    "account_id" => $this->accountId,
                    "error" => $e->getMessage(),
                ]);
                $account->update(['needs_reauth' => true]);
                return; // sai limpo, sem propagar (evita MaxAttempts no Sentry)
            }
            // Para outros erros, propagar para que o Laravel retente (backoff exponencial)
            throw $e;
        }
    }
}
