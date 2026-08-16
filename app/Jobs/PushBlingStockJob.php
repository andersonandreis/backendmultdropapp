<?php

namespace App\Jobs;

use App\Models\MarketplaceAccount;
use App\Models\Product;
use App\Services\Integrations\Erps\Bling\BlingStockPush;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PushBlingStockJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries = 2;

    public function __construct(
        protected int $productId,
        protected int $quantity
    ) {}

    public function handle(BlingStockPush $stockPush): void
    {
        $product = Product::find($this->productId);
        if (!$product) return;

        // Encontra contas Bling vinculadas ao fornecedor deste produto
        $accounts = MarketplaceAccount::where("platform", "bling")
            ->where("supplier_id", $product->supplier_id)
            ->whereNotNull("bling_access_token")
            ->get();

        foreach ($accounts as $account) {
            $stockPush->pushProductStock($account, $product, $this->quantity);
        }
    }
}
