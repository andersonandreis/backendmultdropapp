<?php

namespace App\Providers;

use App\Services\Marketplace\Reconciliation\BlingReconciliationAdapter;
use App\Services\Marketplace\Reconciliation\MercadoLivreReconciliationAdapter;
use App\Services\Marketplace\Reconciliation\ShopeeReconciliationAdapter;
use Illuminate\Support\ServiceProvider;

/**
 * Registra os adapters de reconciliacao no container do Laravel.
 *
 * Tag 'reconciliation.adapters' permite que o Job de reconciliacao
 * itere todos os adapters sem conhecer as classes concretas:
 *
 *   foreach (app()->tagged('reconciliation.adapters') as $adapter) { ... }
 */
class MarketplaceReconciliationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ShopeeReconciliationAdapter::class, ShopeeReconciliationAdapter::class);
        $this->app->bind(MercadoLivreReconciliationAdapter::class, MercadoLivreReconciliationAdapter::class);
        $this->app->bind(BlingReconciliationAdapter::class, BlingReconciliationAdapter::class);

        $this->app->tag([
            ShopeeReconciliationAdapter::class,
            MercadoLivreReconciliationAdapter::class,
            BlingReconciliationAdapter::class,
        ], 'reconciliation.adapters');
    }

    public function boot(): void {}
}
