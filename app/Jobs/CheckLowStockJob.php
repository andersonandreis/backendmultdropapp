<?php

namespace App\Jobs;

use App\Models\Inventory;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * NOV-118 -- Verificacao diaria de estoque abaixo do minimo.
 *
 * Para cada inventory com stock_alert_threshold definido:
 *  - Se quantity <= threshold: envia notificacao Filament para o supplier admin
 *  - Anti-duplicata: nao re-notifica o mesmo inventory dentro de 24h (via cache)
 */
class CheckLowStockJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries   = 1;

    public function handle(): void
    {
        $items = Inventory::query()
            ->withoutGlobalScopes()
            ->whereNotNull('stock_alert_threshold')
            ->where('stock_alert_threshold', '>', 0)
            ->whereColumn('quantity', '<=', 'stock_alert_threshold')
            ->with(['product:id,name,sku', 'producer:id,company_name'])
            ->get();

        if ($items->isEmpty()) {
            Log::info('[NOV-118] CheckLowStockJob: sem itens com estoque baixo.');
            return;
        }

        Log::info('[NOV-118] CheckLowStockJob: ' . $items->count() . ' itens com estoque baixo.');

        foreach ($items as $inventory) {
            $this->notifySupplierAdmins($inventory);
        }
    }

    private function notifySupplierAdmins(Inventory $inventory): void
    {
        $cacheKey = 'low_stock_notified:' . $inventory->id;

        // Anti-duplicata: nao notifica o mesmo inventory mais de uma vez em 24h
        if (Cache::has($cacheKey)) {
            return;
        }

        $supplierId = $inventory->producer_id ?? $inventory->warehouse_id;
        if (!$supplierId) {
            return;
        }

        // Buscar usuarios supplier admin vinculados a este fornecedor
        $adminUsers = User::query()
            ->where('role', 'supplier')
            ->whereHas('supplier', fn ($q) => $q->where('id', $supplierId))
            ->get();

        if ($adminUsers->isEmpty()) {
            // Fallback: super_admins recebem o alerta
            $adminUsers = User::query()->where('role', 'super_admin')->get();
        }

        $productName = $inventory->product?->name ?? 'Produto #' . $inventory->product_id;
        $sku         = $inventory->product?->sku   ?? '---';
        $qty         = (int) $inventory->quantity;
        $threshold   = (int) $inventory->stock_alert_threshold;

        foreach ($adminUsers as $user) {
            try {
                Notification::make()
                    ->title('Estoque baixo: ' . $productName)
                    ->body("SKU: {$sku} | Estoque atual: {$qty} | Minimo: {$threshold}")
                    ->warning()
                    ->sendToDatabase($user);
            } catch (\Throwable $e) {
                Log::warning('[NOV-118] Falha ao notificar usuario', [
                    'user_id'      => $user->id,
                    'inventory_id' => $inventory->id,
                    'error'        => $e->getMessage(),
                ]);
            }
        }

        // Marcar como notificado por 24h
        Cache::put($cacheKey, true, now()->addHours(24));
    }
}
