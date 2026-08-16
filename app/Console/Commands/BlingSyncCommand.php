<?php

namespace App\Console\Commands;

use App\Jobs\SyncBlingJob;
use App\Models\MarketplaceAccount;
use Illuminate\Console\Command;

class BlingSyncCommand extends Command
{
    protected $signature = "bling:sync {--account= : ID da marketplace_account} {--type=all : products|orders|stock|all}";
    protected $description = "Sincroniza dados do Bling ERP (produtos, pedidos, estoque)";

    public function handle(): int
    {
        $accountId = $this->option("account");
        $type = $this->option("type");

        if ($accountId) {
            $accounts = MarketplaceAccount::where("id", $accountId)->whereNotNull("bling_access_token")->get();
        } else {
            $accounts = MarketplaceAccount::whereNotNull("bling_access_token")->where("platform", "bling")->get();
        }

        if ($accounts->isEmpty()) {
            $this->error("Nenhuma conta Bling conectada encontrada.");
            return 1;
        }

        foreach ($accounts as $account) {
            $this->info("Sincronizando conta #{$account->id} ({$account->account_name})...");
            SyncBlingJob::dispatch($account->id, $type);
            $this->info("Job dispatched.");
        }

        return 0;
    }
}
