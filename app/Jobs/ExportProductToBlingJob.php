<?php

namespace App\Jobs;

use App\Models\ClientProduct;
use App\Services\Integrations\Erps\Bling\BlingProductSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * SEL-423: Exporta um produto do catalogo do seller para o Bling ERP nativo.
 *
 * Disparado por MarketplaceController::publish() quando platform=bling.
 * Usa BlingProductSync::exportProduct() — create or update pelo SKU.
 */
class ExportProductToBlingJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 60;
    public int $uniqueFor = 1800;

    public function uniqueId(): string
    {
        return 'bling-export-cp-' . $this->clientProductId;
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function __construct(
        public readonly int $clientProductId
    ) {}

    public function handle(BlingProductSync $blingSync): void
    {
        $cp = ClientProduct::with(['product', 'marketplaceAccount'])->find($this->clientProductId);

        if (! $cp) {
            Log::warning('[ExportProductToBling] ClientProduct nao encontrado', [
                'client_product_id' => $this->clientProductId,
            ]);
            return;
        }

        $account = $cp->marketplaceAccount;

        if (! $account || $account->platform !== 'bling') {
            Log::warning('[ExportProductToBling] Conta Bling nao encontrada para ClientProduct', [
                'client_product_id' => $this->clientProductId,
                'account_id'        => $account?->id,
                'platform'          => $account?->platform,
            ]);
            $cp->update([
                'sync_status'     => 'error',
                'last_sync_error' => 'Conta Bling nao conectada ou plataforma incorreta.',
                'last_sync_at'    => now(),
            ]);
            return;
        }

        if ($account->status !== 'active') {
            Log::warning('[ExportProductToBling] Conta Bling inativa - skip', [
                'client_product_id' => $this->clientProductId,
                'account_status'    => $account->status,
            ]);
            $cp->update([
                'sync_status'     => 'error',
                'last_sync_error' => 'Conta Bling nao esta ativa. Reconecte a integracao.',
                'last_sync_at'    => now(),
            ]);
            return;
        }

        $product = $cp->product;

        if (! $product) {
            Log::warning('[ExportProductToBling] Product nao encontrado', [
                'client_product_id' => $this->clientProductId,
                'product_id'        => $cp->product_id,
            ]);
            $cp->update([
                'sync_status'     => 'error',
                'last_sync_error' => 'Produto nao encontrado no catalogo.',
                'last_sync_at'    => now(),
            ]);
            return;
        }

        $cp->update(['sync_status' => 'syncing', 'last_sync_at' => now()]);

        try {
            $blingId = $blingSync->exportProduct($account, $product);

            if ($blingId) {
                $cp->update([
                    'sync_status'     => 'synced',
                    'last_sync_at'    => now(),
                    'last_sync_error' => null,
                ]);
                Log::info('[ExportProductToBling] Produto exportado com sucesso', [
                    'client_product_id' => $this->clientProductId,
                    'bling_id'          => $blingId,
                    'sku'               => $product->sku,
                ]);
            } else {
                $cp->update([
                    'sync_status'     => 'error',
                    'last_sync_error' => 'Bling retornou vazio - ver logs BlingProductSync.',
                    'last_sync_at'    => now(),
                ]);
                Log::error('[ExportProductToBling] exportProduct retornou null', [
                    'client_product_id' => $this->clientProductId,
                    'sku'               => $product->sku,
                ]);
            }
        } catch (\Throwable $e) {
            $cp->update([
                'sync_status'     => 'error',
                'last_sync_error' => mb_substr($e->getMessage(), 0, 500),
                'last_sync_at'    => now(),
            ]);
            Log::error('[ExportProductToBling] Excecao ao exportar', [
                'client_product_id' => $this->clientProductId,
                'error'             => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
