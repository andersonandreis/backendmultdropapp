<?php

namespace App\Jobs;

use App\Models\ClientProduct;
use App\Models\MarketplaceAccount;
use App\Services\MercadoLivreService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class MigrateSupplierJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 120;

    public function __construct(
        public readonly int $marketplaceAccountId,
        public readonly int $oldSupplierId,
        public readonly int $newSupplierId
    ) {}

    public function handle(MercadoLivreService $mlService): void
    {
        $account = MarketplaceAccount::findOrFail($this->marketplaceAccountId);

        Log::info("[MigrateSupplierJob] Iniciando migração de fornecedor", [
            'account_id'   => $this->marketplaceAccountId,
            'old_supplier' => $this->oldSupplierId,
            'new_supplier' => $this->newSupplierId,
        ]);

        // Passo 1: Busca produtos do fornecedor antigo nesta conta
        $clientProducts = ClientProduct::where('marketplace_account_id', $this->marketplaceAccountId)
            ->where('supplier_id', $this->oldSupplierId)
            ->get();

        $pausedCount  = 0;
        $failedPauses = 0;

        // Passo 2: Para cada produto, zera estoque e pausa anúncio no ML
        foreach ($clientProducts as $cp) {
            $cp->update([
                'stock_quantity' => 0,
                'sync_status'    => 'paused',
            ]);

            // Passo 3: Tenta pausar no ML (não bloqueia se falhar)
            if ($account->platform === 'mercadolivre' && $cp->external_item_id) {
                try {
                    $token = $mlService->getValidToken($account);

                    $response = Http::withToken($token)
                        ->put("https://api.mercadolibre.com/items/{$cp->external_item_id}", [
                            'status' => 'paused',
                        ]);

                    if ($response->failed()) {
                        Log::warning("[MigrateSupplierJob] Falha ao pausar item ML {$cp->external_item_id}", [
                            'status' => $response->status(),
                        ]);
                        $failedPauses++;
                    } else {
                        $pausedCount++;
                    }
                } catch (\Throwable $e) {
                    Log::warning("[MigrateSupplierJob] Exceção ao pausar item ML {$cp->external_item_id}: " . $e->getMessage());
                    $failedPauses++;
                }
            }
        }

        // Passo 4: Atualiza o fornecedor da conta
        $account->update(['supplier_id' => $this->newSupplierId]);

        Log::info("[MigrateSupplierJob] Migração concluída", [
            'account_id'    => $this->marketplaceAccountId,
            'new_supplier'  => $this->newSupplierId,
            'products_total' => $clientProducts->count(),
            'paused_ml'     => $pausedCount,
            'failed_pauses' => $failedPauses,
        ]);

        // Passo 5: Notificação ao seller
        // $seller = $account->client->user;
        // Notification::send($seller, new SupplierMigratedNotification($account, $clientProducts->count()));
    }
}
