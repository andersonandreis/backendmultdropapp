<?php

namespace App\Jobs;

use App\Models\ClientProduct;
use App\Services\Integrations\Marketplaces\ShopeeService;
use App\Services\MercadoLivreService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ApplyRepricingPriceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 60;

    public function __construct(
        public readonly int   $clientProductId,
        public readonly float $newPrice
    ) {}

    public function handle(ShopeeService $shopeeService): void
    {
        $cp = ClientProduct::with('marketplaceAccount')->find($this->clientProductId);

        if (!$cp || !$cp->marketplaceAccount) {
            Log::warning('[ApplyRepricing] ClientProduct sem conta marketplace', ['id' => $this->clientProductId]);
            return;
        }

        $account  = $cp->marketplaceAccount;
        $platform = strtolower($account->platform ?? '');

        if (in_array($platform, ['mercadolivre', 'mercado_livre'])) {
            if ($account->sync_blocked_at !== null) {
                Log::warning('[ApplyRepricing] Conta ML bloqueada — repricing ignorado', ['account_id' => $account->id]);
                return;
            }
            PublishClientProductToMLJob::dispatch($this->clientProductId)->onQueue('default');
            Log::info('[ApplyRepricing] ML job despachado', ['client_product_id' => $this->clientProductId, 'price' => $this->newPrice]);
            return;
        }

        if ($platform === 'shopee') {
            if ($account->sync_blocked_at !== null) {
                Log::warning('[ApplyRepricing] Conta Shopee bloqueada — repricing ignorado', ['account_id' => $account->id]);
                return;
            }
            $itemId = (int) ($cp->shopee_external_item_id ?: $cp->external_listing_id);
            if (!$itemId) {
                Log::warning('[ApplyRepricing] Shopee sem item_id', ['client_product_id' => $this->clientProductId]);
                return;
            }
            $shopeeService->updatePriceOnly($account, $itemId, $this->newPrice);
            return;
        }

        Log::info('[ApplyRepricing] Plataforma nao suportada — ignorado', ['platform' => $platform, 'client_product_id' => $this->clientProductId]);
    }
}
