<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessScheduledSync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hubai:sync-schedules';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispara sincronizacoes rotineiras da arquitetura HubAI (Lojas, Auto-Listing, Cleanup)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        Log::info("ProcessScheduledSync Started.");

        // 1. Desencadeia Auto-listing pros lojistas "full_auto"
        \App\Jobs\AutoListSupplierProductsJob::dispatch();

        // 2. Desencadeia SyncMarketplaceCategories semanal/mensal dependendo da flag
        \App\Jobs\SyncMarketplaceCategoriesJob::dispatch();

        Log::info("ProcessScheduledSync Core Dispatched.");
        $this->info("HubAI Central Sync Dispatched into Queues.");
    }
}
