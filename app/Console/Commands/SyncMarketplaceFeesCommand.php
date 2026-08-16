<?php

namespace App\Console\Commands;

use App\Services\Pricing\MarketplaceFeeService;
use Illuminate\Console\Command;

class SyncMarketplaceFeesCommand extends Command
{
    protected $signature = 'fees:sync
                            {platform? : Plataforma alvo: mercadolivre | all}
                            {--seed   : Seed de defaults para todos os marketplaces}';

    protected $description = 'Sincroniza taxas de marketplace (ML via API OAuth, outros via defaults editaveis)';

    public function handle(MarketplaceFeeService $feeService): int
    {
        $platform = $this->argument('platform');
        $seed     = $this->option('seed');

        // Seed de defaults quando --seed, platform=all, ou sem argumento
        if ($seed || $platform === 'all' || !$platform) {
            $seeded = $feeService->seedDefaultFees();
            $this->info("Defaults: {$seeded} taxas inseridas (firstOrCreate — edições manuais preservadas)");
        }

        // Sync via API do Mercado Livre
        if (!$platform || $platform === 'mercadolivre' || $platform === 'all') {
            $this->info('Sincronizando taxas do Mercado Livre via API OAuth...');
            $synced = $feeService->syncMercadoLivreFees();

            if ($synced === 0) {
                $this->warn('Nenhum listing type sincronizado. Verifique se há conta ML ativa com token válido.');
                $this->line('  → Acesse Configurações > Contas de Marketplace e reconecte o Mercado Livre.');
            } else {
                $this->info("ML: {$synced} listing types sincronizados via API.");
            }
        }

        $this->info('Concluido.');

        return self::SUCCESS;
    }
}
