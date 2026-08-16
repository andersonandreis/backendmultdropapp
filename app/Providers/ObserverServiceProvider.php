<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Inventory;
use App\Models\MarketplaceAccount;
use App\Models\MissedOrderAlert;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Observers\InventoryObserver;
use App\Observers\MarketplaceAccountObserver;
use App\Observers\MissedOrderAlertObserver;
use App\Observers\OrderObserver;
use App\Observers\ProductObserver;
use App\Observers\UserObserver;
use App\Models\ClientSupplierTransaction;
use App\Observers\ClientSupplierTransactionObserver;

class ObserverServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        Product::observe(ProductObserver::class);
        Order::observe(OrderObserver::class);
        Inventory::observe(InventoryObserver::class);
        User::observe(UserObserver::class);
        MissedOrderAlert::observe(MissedOrderAlertObserver::class);
        MarketplaceAccount::observe(MarketplaceAccountObserver::class);
        ClientSupplierTransaction::observe(ClientSupplierTransactionObserver::class);
    }
}
