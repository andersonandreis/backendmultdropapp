<?php

namespace App\Console\Commands;

use App\Models\ErpAccount;
use App\Services\Integrations\Erps\Bling\BlingProductSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * NOV-153: Sincroniza catálogo Bling do FORNECEDOR (ERP) → Product do supplier.
 *
 * Diferente de bling:sync (que opera em MarketplaceAccount → ClientProduct do lojista),
 * este comando opera em ErpAccount (Bling do próprio fornecedor) e atualiza apenas
 * a tabela `products` do supplier — sem criar ClientProduct.
 *
 * Uso:
 *  - bling:sync-supplier-erp                    → todas as contas ERP Bling ativas
 *  - bling:sync-supplier-erp --account=42       → apenas a ErpAccount #42
 *  - bling:sync-supplier-erp --supplier=25      → apenas a conta ERP do supplier 25
 */
class BlingSyncSupplierErpCommand extends Command
{
    protected $signature = 'bling:sync-supplier-erp
                            {--account= : ID da erp_account específica}
                            {--supplier= : ID do supplier (sincroniza a conta ERP dele)}';

    protected $description = 'Sincroniza catálogo Bling do fornecedor (ErpAccount) → Product do supplier.';

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
            $this->info('[bling:sync-supplier-erp] Nenhuma ErpAccount Bling ativa neste backend — skip (exit 0).');
            return 0;
        }

        $this->info("Iniciando sync de {$accounts->count()} conta(s) ERP Bling...");
        Log::info('[BlingSyncSupplierErpCommand] start', ['count' => $accounts->count()]);

        $totalStats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'linked' => 0, 'pages' => 0];

        foreach ($accounts as $account) {
            $this->info("→ ErpAccount #{$account->id} (supplier {$account->supplier_id}, {$account->account_name})...");
            try {
                $stats = $productSync->syncForSupplierErp($account);
                foreach ($stats as $k => $v) {
                    $totalStats[$k] = ($totalStats[$k] ?? 0) + $v;
                }
                $this->line(sprintf(
                    '  created=%d updated=%d linked=%d skipped=%d errors=%d pages=%d',
                    $stats['created']  ?? 0,
                    $stats['updated']  ?? 0,
                    $stats['linked']   ?? 0,
                    $stats['skipped']  ?? 0,
                    $stats['errors']   ?? 0,
                    $stats['pages']    ?? 0
                ));
                Log::info('[BlingSyncSupplierErpCommand] account synced', [
                    'erp_account_id' => $account->id,
                    'supplier_id'    => $account->supplier_id,
                    'stats'          => $stats,
                ]);
            } catch (\Throwable $e) {
                $this->error("  Falhou: {$e->getMessage()}");
                Log::error('[BlingSyncSupplierErpCommand] account failed', [
                    'erp_account_id' => $account->id,
                    'error'          => $e->getMessage(),
                ]);
                $totalStats['errors']++;
            }
        }

        $this->info('TOTAL: ' . json_encode($totalStats));
        Log::info('[BlingSyncSupplierErpCommand] done', ['total' => $totalStats]);

        return 0;
    }
}
