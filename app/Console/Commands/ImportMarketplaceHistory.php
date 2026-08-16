<?php

namespace App\Console\Commands;

use App\Jobs\ImportMarketplaceAccountDataJob;
use App\Models\ClientProduct;
use App\Models\MarketplaceAccount;
use Illuminate\Console\Command;

/**
 * ImportMarketplaceHistory
 *
 * Roda o ImportMarketplaceAccountDataJob para todas as contas ativas
 * que ainda nao tiveram produtos importados (client_products vazios).
 *
 * Uso:
 *   php artisan import:marketplace-history
 *   php artisan import:marketplace-history --platform=shopee
 *   php artisan import:marketplace-history --account=42
 *   php artisan import:marketplace-history --force   (re-importa mesmo com produtos existentes)
 */
class ImportMarketplaceHistory extends Command
{
    protected $signature = 'import:marketplace-history
                            {--platform= : Filtrar por plataforma (shopee, mercadolivre)}
                            {--account=  : Importar somente conta especifica (ID)}
                            {--force     : Importar mesmo que a conta ja tenha client_products}';

    protected $description = 'Importa produtos e pedidos historicos (90d) das contas marketplace ativas sem dados importados';

    public function handle(): int
    {
        $platform  = $this->option('platform');
        $accountId = $this->option('account');
        $force     = $this->option('force');

        $query = MarketplaceAccount::where('status', 'active')
            ->whereIn('platform', ['shopee', 'mercadolivre']);

        if ($platform) {
            $query->where('platform', $platform);
        }

        if ($accountId) {
            $query->where('id', (int) $accountId);
        }

        $accounts = $query->get();

        if ($accounts->isEmpty()) {
            $this->info('Nenhuma conta ativa encontrada com os filtros informados.');
            return self::SUCCESS;
        }

        $this->info("Contas ativas encontradas: {$accounts->count()}");

        $dispatched = 0;
        $skipped    = 0;

        foreach ($accounts as $account) {
            if (! $force) {
                $hasProducts = ClientProduct::where('marketplace_account_id', $account->id)->exists();
                if ($hasProducts) {
                    $this->line("  [SKIP] Conta #{$account->id} ({$account->platform} / client {$account->client_id}) ja tem produtos importados.");
                    $skipped++;
                    continue;
                }
            }

            ImportMarketplaceAccountDataJob::dispatch($account->id)->onQueue('default');

            $this->info("  [OK] Job despachado para conta #{$account->id} ({$account->platform} / client {$account->client_id} / shop {$account->shop_id})");
            $dispatched++;
        }

        $this->newLine();
        $this->info("Resumo: {$dispatched} jobs despachados, {$skipped} contas ignoradas (ja com dados).");
        $this->info('Acompanhe o progresso em: tail -f storage/logs/marketplace.log');

        return self::SUCCESS;
    }
}
