<?php

namespace App\Jobs;

use App\Models\ClientProduct;
use App\Models\MarketplaceAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * FOR-088 / FOR-080: Backfill ml_family_name para client_products com external_listing_id.
 *
 * Dispatched em batches de 200 pelo artisan for-088:backfill-ml-family-name.
 * Faz GET /items/{id}?attributes=family_name via ML API e persiste o resultado.
 * Skipa produtos sem marketplace_account_id ou sem token valido.
 */
class BackfillMlFamilyNameJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries  = 2;

    /**
     * @param array<int> $clientProductIds IDs a processar neste batch
     */
    public function __construct(public array $clientProductIds) {}

    public function handle(): void
    {
        $products = ClientProduct::with('marketplaceAccount')
            ->whereIn('id', $this->clientProductIds)
            ->whereNotNull('external_listing_id')
            ->get();

        $tokenCache = [];
        $updated    = 0;
        $skipped    = 0;

        foreach ($products as $cp) {
            $itemId = $cp->external_listing_id;

            // Obter token via marketplace_account
            $accountId = $cp->marketplace_account_id;
            if (!$accountId) {
                $skipped++;
                continue;
            }

            if (!isset($tokenCache[$accountId])) {
                $account = MarketplaceAccount::find($accountId);
                if (!$account || !$account->access_token) {
                    $skipped++;
                    continue;
                }
                $tokenCache[$accountId] = $account->access_token;
            }

            $token = $tokenCache[$accountId];

            try {
                $resp = Http::withToken($token)
                    ->timeout(10)
                    ->get("https://api.mercadolibre.com/items/{}", [
                        'attributes' => 'id,family_name',
                    ]);

                if (!$resp->successful()) {
                    $skipped++;
                    continue;
                }

                $familyName = $resp->json('family_name');

                // NULL significa que nao e catalogo — armazenar NULL explicitamente
                if ($cp->ml_family_name !== $familyName) {
                    $cp->update(['ml_family_name' => $familyName]);
                    $updated++;
                }
            } catch (\Throwable $e) {
                Log::warning('[FOR-088] BackfillMlFamilyName erro', [
                    'client_product_id' => $cp->id,
                    'item_id'           => $itemId,
                    'err'               => $e->getMessage(),
                ]);
                $skipped++;
            }
        }

        Log::info('[FOR-088] BackfillMlFamilyName batch concluido', [
            'batch_size' => count($this->clientProductIds),
            'updated'    => $updated,
            'skipped'    => $skipped,
        ]);
    }
}
