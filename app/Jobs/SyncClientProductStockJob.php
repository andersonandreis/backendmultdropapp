<?php

namespace App\Jobs;

use App\Models\ClientProduct;
use App\Models\MarketplaceAccount;
use App\Services\Integrations\Marketplaces\MercadoLivreService;
use App\Services\Integrations\Marketplaces\ShopeeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncClientProductStockJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 60;
    public array $backoff = [30, 120];

    public function __construct(public readonly int $clientProductId)
    {
        $this->onQueue("inventory");
    }

    public function handle(): void
    {
        $cp = ClientProduct::with(["marketplaceAccount", "product"])->find($this->clientProductId);
        if (!$cp) {
            Log::warning("[SyncClientProductStockJob] ClientProduct nao encontrado", ["id" => $this->clientProductId]);
            return;
        }
        $account = $cp->marketplaceAccount;
        if (!$account || $account->status !== "connected") {
            Log::info("[SyncClientProductStockJob] Conta ausente ou desconectada", ["account_id" => $account?->id]);
            return;
        }
        try {
            $stock = match ($account->platform) {
                "mercadolivre" => $this->fetchMLStock($cp, $account),
                "shopee"       => $this->fetchShopeeStock($cp, $account),
                default        => null,
            };
            if ($stock === null) {
                Log::info("[SyncClientProductStockJob] Plataforma sem pull de estoque", ["platform" => $account->platform]);
                return;
            }
            $cp->update(["sync_status" => "synced", "last_sync_at" => now(), "last_sync_error" => null]);
            if ($cp->product) {
                $inv = $cp->product->inventory()->first();
                if ($inv) {
                    $inv->update(["quantity" => $stock]);
                    Log::info("[SyncClientProductStockJob] Estoque atualizado", ["product_id" => $cp->product_id, "stock" => $stock]);
                }
            }
        } catch (\Exception $e) {
            $cp->update(["sync_status" => "failed", "last_sync_error" => $e->getMessage()]);
            Log::error("[SyncClientProductStockJob] Erro", ["error" => $e->getMessage()]);
            $this->fail($e);
        }
    }

    private function fetchMLStock(ClientProduct $cp, MarketplaceAccount $account): ?int
    {
        $itemId = $cp->external_listing_id ?? $cp->ml_external_item_id;
        if (!$itemId) return null;
        $svc   = app(MercadoLivreService::class);
        $token = $svc->getAccessToken($account);
        if (!$token) return null;
        $resp  = Http::withToken($token)->get("https://api.mercadolibre.com/items/" . $itemId, ["attributes" => "available_quantity,status"]);
        if (!$resp->successful()) throw new \RuntimeException("ML GET /items/" . $itemId . " falhou: HTTP " . $resp->status());
        return (int) ($resp->json()["available_quantity"] ?? 0);
    }

    private function fetchShopeeStock(ClientProduct $cp, MarketplaceAccount $account): ?int
    {
        $itemId = $cp->external_listing_id ?? $cp->shopee_external_item_id;
        if (!$itemId) return null;
        $svc    = app(ShopeeService::class);
        $detail = $svc->fetchItemDetail($account, (int) $itemId);
        if (empty($detail) || isset($detail["error"])) throw new \RuntimeException("Shopee fetchItemDetail falhou");
        $stock = $detail["item_list"][0]["stock"]["normal"] ?? $detail["item_list"][0]["stock"][0]["normal"] ?? null;
        return $stock !== null ? (int) $stock : null;
    }
}
