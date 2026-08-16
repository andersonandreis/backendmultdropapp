<?php

namespace App\Console\Commands;

use App\Models\ClientProduct;
use App\Models\MarketplaceAccount;
use App\Services\Integrations\Marketplaces\ShopeeService;
use App\Services\Integrations\Marketplaces\MercadoLivreService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BackfillClientProductImageUrlCommand extends Command
{
    protected $signature = 'marketplace:backfill-image-url {--limit=100 : Maximo de registros a processar}';
    protected $description = 'Popula image_url em client_products onde esta NULL';

    public function handle(ShopeeService $shopee, MercadoLivreService $ml): int
    {
        $limit = (int) $this->option('limit');
        $products = ClientProduct::whereNull('image_url')->whereNotNull('external_listing_id')->whereNotNull('marketplace_account_id')->with('marketplaceAccount')->limit($limit)->get();
        $total = $products->count(); $updated = 0; $failed = 0;
        $this->info("[BackfillImageUrl] Processando {$total} registros...");
        foreach ($products->groupBy('marketplace_account_id') as $accountId => $accountProducts) {
            $account = $accountProducts->first()->marketplaceAccount;
            if (! $account) { $failed += $accountProducts->count(); continue; }
            if ($account->platform === 'shopee') {
                [$u, $f] = $this->backfillShopee($account, $accountProducts->all(), $shopee);
            } elseif ($account->platform === 'mercadolivre') {
                [$u, $f] = $this->backfillML($account, $accountProducts->all(), $ml);
            } else { $failed += $accountProducts->count(); continue; }
            $updated += $u; $failed += $f;
        }
        $this->info("[BackfillImageUrl] Concluido: {$updated} atualizados, {$failed} falhas.");
        Log::channel('marketplace')->info('[BackfillImageUrl] Concluida', ['total' => $total, 'updated' => $updated, 'failed' => $failed]);
        return Command::SUCCESS;
    }

    private function backfillShopee(MarketplaceAccount $account, array $products, ShopeeService $shopee): array
    {
        $accessToken = $shopee->getValidAccessToken($account);
        $shopId = $account->shop_id;
        if (! $accessToken || ! $shopId) { return [0, count($products)]; }
        $updated = 0; $failed = 0;
        foreach (array_chunk($products, 50) as $chunk) {
            $itemIds = array_map(fn($p) => (int) $p->external_listing_id, $chunk);
            $idIndex = [];
            foreach ($chunk as $cp) { $idIndex[(int) $cp->external_listing_id] = $cp; }
            try {
                $ref = new \ReflectionMethod($shopee, 'callApi'); $ref->setAccessible(true);
                $response = $ref->invoke($shopee, '/api/v2/product/get_item_base_info', ['item_id_list' => implode(',', $itemIds), 'need_tax_info' => false, 'need_complaint_policy' => false, 'shop_id' => $shopId, 'access_token' => $accessToken], 'GET');
                foreach ($response['response']['item_list'] ?? [] as $item) {
                    $itemId = (int) ($item['item_id'] ?? 0);
                    if (! $itemId || ! isset($idIndex[$itemId])) { continue; }
                    $imageUrl = $item['image']['image_url_list'][0] ?? $item['image']['image_url'] ?? null;
                    if (! $imageUrl) { $failed++; continue; }
                    $idIndex[$itemId]->update(['image_url' => $imageUrl]); $updated++;
                }
            } catch (\Throwable $e) { $failed += count($chunk); }
        }
        return [$updated, $failed];
    }

    private function backfillML(MarketplaceAccount $account, array $products, MercadoLivreService $ml): array
    {
        try { $ref = new \ReflectionMethod($ml, 'getValidAccessToken'); $ref->setAccessible(true); $token = $ref->invoke($ml, $account); }
        catch (\Throwable $e) { return [0, count($products)]; }
        if (! $token) { return [0, count($products)]; }
        $updated = 0; $failed = 0;
        foreach ($products as $clientProduct) {
            $itemId = $clientProduct->external_listing_id;
            try {
                $response = Http::withToken($token)->get("https://api.mercadolibre.com/items/" . $itemId);
                if ($response->failed()) { $failed++; continue; }
                $data = $response->json();
                $imageUrl = $data['thumbnail'] ?? ($data['pictures'][0]['url'] ?? null);
                if (! $imageUrl) { $failed++; continue; }
                $clientProduct->update(['image_url' => $imageUrl]); $updated++;
                usleep(100000);
            } catch (\Throwable $e) { $failed++; }
        }
        return [$updated, $failed];
    }
}
