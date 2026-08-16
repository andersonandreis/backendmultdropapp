<?php

namespace App\Observers;

use App\Jobs\ImportMarketplaceAccountDataJob;
use App\Jobs\SyncShopeeOrdersJob;
use App\Models\MarketplaceAccount;
use Illuminate\Support\Facades\Log;

/**
 * MarketplaceAccountObserver
 *
 * Dispara sync automatico quando uma conta marketplace vai para status active:
 *
 * SyncShopeeOrdersJob (Shopee only, delay 5s):
 *   - Sync rapido dos pedidos dos ultimos 7 dias para exibicao imediata
 *
 * ImportMarketplaceAccountDataJob (Shopee + ML, delay 10s):
 *   - Importacao completa: produtos ativos + pedidos 90 dias
 *   - Roda apos o SyncShopeeOrders para nao competir
 */
class MarketplaceAccountObserver
{
    public function created(MarketplaceAccount $account): void
    {
        if (! in_array($account->platform, ["shopee", "mercadolivre"])) {
            return;
        }

        Log::channel("marketplace")->info("[MarketplaceAccountObserver] Nova conta criada", [
            "account_id" => $account->id,
            "platform"   => $account->platform,
            "client_id"  => $account->client_id,
            "status"     => $account->status,
        ]);

        if ($account->status === "active" && $account->access_token) {
            // Shopee: sync rapido (7d) imediato + importacao historica completa
            if ($account->platform === "shopee" && $account->shop_id) {
                // MUL-311: puxar pedido ao conectar/reconectar conta esta desligado no sistema.
                if (config('imports.auto_orders_on_connect', false)) {
                    dispatch(new SyncShopeeOrdersJob($account->id))->delay(now()->addSeconds(5));
                }
                Log::channel("marketplace")->info("[MarketplaceAccountObserver] SyncShopeeOrdersJob despachado", [
                    "account_id" => $account->id,
                    "trigger"    => "created",
                ]);
            }

            // Importacao historica completa (produtos + 90d pedidos) - Shopee e ML
            dispatch(new ImportMarketplaceAccountDataJob($account->id))->delay(now()->addSeconds(10));
            Log::channel("marketplace")->info("[MarketplaceAccountObserver] ImportMarketplaceAccountDataJob despachado", [
                "account_id" => $account->id,
                "platform"   => $account->platform,
                "trigger"    => "created",
            ]);
        }
    }

    public function updated(MarketplaceAccount $account): void
    {
        // MUL-190: sync bidirecional das configs de importacao Bling hub->WL (modo relay)
        if ($account->platform === 'bling'
            && ($account->wasChanged('allowed_integrations')
                || $account->wasChanged('data_inicial_import'))) {
            \App\Services\Integrations\Erps\Bling\BlingConfigSyncPusher::push($account);
        }

        if (! in_array($account->platform, ["shopee", "mercadolivre"])) {
            return;
        }

        // Dispara apenas quando status muda para active
        if (! $account->isDirty("status") || $account->status !== "active") {
            return;
        }

        Log::channel("marketplace")->info("[MarketplaceAccountObserver] Status mudou para active - despachando jobs", [
            "account_id"      => $account->id,
            "platform"        => $account->platform,
            "client_id"       => $account->client_id,
            "previous_status" => $account->getOriginal("status"),
        ]);

        // Shopee: sync rapido (7d) imediato
        if ($account->platform === "shopee") {
            // MUL-311: puxar pedido ao conectar/reconectar conta esta desligado no sistema.
            if (config('imports.auto_orders_on_connect', false)) {
                dispatch(new SyncShopeeOrdersJob($account->id))->delay(now()->addSeconds(5));
            }
        }

        // Importacao historica completa (produtos + 90d pedidos) - Shopee e ML
        dispatch(new ImportMarketplaceAccountDataJob($account->id))->delay(now()->addSeconds(10));
        Log::channel("marketplace")->info("[MarketplaceAccountObserver] ImportMarketplaceAccountDataJob despachado", [
            "account_id" => $account->id,
            "platform"   => $account->platform,
            "trigger"    => "updated",
        ]);
    }
}
