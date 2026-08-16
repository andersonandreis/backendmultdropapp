<?php

namespace App\Console\Commands;

use App\Models\ErpAccount;
use App\Observers\ProductObserver;
use App\Services\Integrations\Erps\Bling\BlingProductSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * MUL-198: Sincroniza APENAS estoque (inventory.quantity) do Bling ERP -> HubAI.
 *
 * Opera em ErpAccount, nao MarketplaceAccount.
 * Nao importa produtos novos, nao altera price/cost, nao altera is_active.
 * Respeita ProductObserver.$disableSync para nao disparar loop legado.
 *
 * Uso:
 *   bling:sync-stock-erp               -> todas as contas ERP Bling ativas
 *   bling:sync-stock-erp --account=1   -> ErpAccount especifica
 *   bling:sync-stock-erp --supplier=30 -> pelo supplier_id
 */
class BlingStockSyncErpCommand extends Command
{
    protected $signature = 'bling:sync-stock-erp
                            {--account= : ID da ErpAccount especifica}
                            {--supplier= : ID do supplier}';

    protected $description = 'MUL-198: Sincroniza APENAS inventory.quantity do Bling ERP -> HubAI (sem alterar produtos).';

    public function handle(BlingProductSync $productSync): int
    {
        $query = ErpAccount::query()
            ->where('platform', 'bling')
            ->where('status', 'active');

        if ($accountId = $this->option('account')) {
            $query->where('id', (int) $accountId);
        }

        if ($supplierId = $this->option('supplier')) {
            $query->where('supplier_id', (int) $supplierId);
        }

        $accounts = $query->get();

        if ($accounts->isEmpty()) {
            $this->info('[bling:sync-stock-erp] Nenhuma ErpAccount Bling ativa encontrada — exit 0.');
            return 0;
        }

        $this->info("Iniciando sync de estoque de {$accounts->count()} conta(s) ERP Bling...");
        Log::info('[BlingStockSyncErpCommand] start', ['count' => $accounts->count()]);

        // Anti-loop: desabilita sync legado durante a atualizacao de inventory
        // Motivo: inventory.save() dispara InventoryObserver -> SyncInventoryJob (legado)
        // O $disableSync=true suprime isso (regra 16 do 00-INDEX).
        // IMPORTANTE: restaurar ao final.
        $wasDisabled = ProductObserver::$disableSync;
        ProductObserver::$disableSync = true;

        try {
            foreach ($accounts as $account) {
                $this->info("-> ErpAccount #{$account->id} (supplier {$account->supplier_id}, {$account->account_name})...");
                try {
                    $stats = $productSync->syncStockForErpAccount($account);
                    $this->line(sprintf(
                        '  updated=%d skipped=%d errors=%d pages=%d',
                        $stats['updated'] ?? 0,
                        $stats['skipped'] ?? 0,
                        $stats['errors']  ?? 0,
                        $stats['pages']   ?? 0,
                    ));
                    Log::info('[BlingStockSyncErpCommand] done', [
                        'erp_account_id' => $account->id,
                        'supplier_id'    => $account->supplier_id,
                        'stats'          => $stats,
                    ]);
                } catch (\Throwable $e) {
                    $this->error("  Falhou: {$e->getMessage()}");
                    Log::error('[BlingStockSyncErpCommand] account failed', [
                        'erp_account_id' => $account->id,
                        'error'          => $e->getMessage(),
                    ]);
                }
            }
        } finally {
            ProductObserver::$disableSync = $wasDisabled;
        }

        $this->info('Sync de estoque concluido.');
        return 0;
    }
}
