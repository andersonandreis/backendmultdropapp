<?php

namespace App\Console\Commands;

use App\Jobs\SyncTenantSupplierCatalogJob;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Dispatcha sincronizacao de catalogo (produtos + estoque) para um tenant/whitelabel.
 *
 * Uso:
 *   php artisan tenant:sync-catalog fornecefy
 *   php artisan tenant:sync-catalog multdrop.app --force
 *
 * O comando dispara um SyncTenantSupplierCatalogJob por supplier vinculado ao tenant.
 * A implementacao real do envio fica no Job — este command e apenas o ponto de entrada.
 */
class SyncTenantCatalogCommand extends Command
{
    protected $signature = 'tenant:sync-catalog
        {tenant_slug : Slug do tenant (ex: fornecefy, multdrop.app)}
        {--force : Forcamandar mesmo que sync recente exista}';

    protected $description = 'Sync products and inventory to a whitelabel tenant via webhook';

    public function handle(): int
    {
        $slug   = $this->argument('tenant_slug');
        $tenant = Tenant::where('slug', $slug)->first();

        if (! $tenant) {
            $this->error("Tenant '{$slug}' nao encontrado.");
            return self::FAILURE;
        }

        if ($tenant->status !== Tenant::STATUS_ACTIVE) {
            $this->warn("Tenant '{$slug}' esta com status={$tenant->status}. Abortando sync.");
            return self::FAILURE;
        }

        $suppliers = $tenant->suppliers;

        if ($suppliers->isEmpty()) {
            $this->warn("Tenant '{$tenant->slug}' nao tem fornecedores vinculados (tenant_supplier vazio).");
            $this->line('Acesse o painel admin > Whitelabels > Editar e selecione os fornecedores.');
            return self::SUCCESS;
        }

        $this->info("Sincronizando catalogo para tenant: {$tenant->name}");
        $this->info("Fornecedores: {$suppliers->count()}");
        $this->newLine();

        $bar = $this->output->createProgressBar($suppliers->count());
        $bar->start();

        foreach ($suppliers as $supplier) {
            $this->newLine();
            $label = $supplier->display_name ?: $supplier->company_name;
            $this->line("  -> Supplier: {$label} (id: {$supplier->id}, legacy_id: {$supplier->legacy_id})");

            SyncTenantSupplierCatalogJob::dispatch($tenant->id, $supplier->id);

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('Jobs despachados com sucesso para a fila.');
        $this->line('Acompanhe em: php artisan queue:work --once (ou pelo Horizon se configurado)');

        return self::SUCCESS;
    }
}
