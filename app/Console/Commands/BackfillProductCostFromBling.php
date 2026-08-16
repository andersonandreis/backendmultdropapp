<?php

namespace App\Console\Commands;

use App\Models\ErpAccount;
use App\Services\Integrations\Erps\Bling\BlingApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MUL-161 Item 4: Backfill products.cost via Bling API (precoCusto).
 *
 * Paginacao Bling (100/pagina), rate limit 3 req/s (350ms entre paginas).
 * Idempotente: so atualiza produtos com cost=0; produtos com cost>0 sao pulados.
 *
 * Uso:
 *   php artisan multdrop:backfill-cost                  # erp_account id=1 (padrao)
 *   php artisan multdrop:backfill-cost --erp-account=1   # explicito
 *   php artisan multdrop:backfill-cost --dry-run         # so mostra o que atualizaria
 */
class BackfillProductCostFromBling extends Command
{
    protected $signature = 'multdrop:backfill-cost
        {--erp-account=1 : ID do erp_account Bling (padrao: 1)}
        {--dry-run : Simula sem atualizar banco}';

    protected $description = 'MUL-161: Backfill de products.cost=0 via Bling API (precoCusto)';

    public function handle(BlingApiClient $client): int
    {
        $erpId  = (int) $this->option('erp-account');
        $dryRun = (bool) $this->option('dry-run');

        $account = ErpAccount::find($erpId);
        if (! $account) {
            $this->error("ErpAccount #{$erpId} nao encontrado.");
            return self::FAILURE;
        }

        $this->info("MUL-161 Backfill cost via Bling -- ErpAccount #{$erpId} (supplier_id={$account->supplier_id})");
        $this->info("Dry-run: " . ($dryRun ? 'SIM' : 'NAO'));

        $candidateRows = DB::table('products')
            ->where('supplier_id', $account->supplier_id)
            ->where('cost', 0)
            ->get(['id', 'sku'])
            ->all();

        $this->info("Produtos com cost=0: " . count($candidateRows));

        if (empty($candidateRows)) {
            $this->info("Nenhum produto com cost=0. Nada a fazer.");
            return self::SUCCESS;
        }

        // MUL-161: produtos do legado tem SKU prefixado com D{deposito}-
        // O Bling retorna SKU sem esse prefixo. Indexamos por SKU exato e sem prefixo.
        $skuToId = [];
        foreach ($candidateRows as $row) {
            $skuToId[$row->sku] = $row->id;
            $rawSku = preg_replace('/^D[0-9]+-/', '', $row->sku);
            if ($rawSku !== $row->sku) { $skuToId[$rawSku] = $row->id; }
        }

        $stats       = ['updated' => 0, 'skipped_no_match' => 0, 'skipped_zero_cost' => 0, 'errors' => 0, 'pages' => 0];
        $page        = 1;
        $blingProds  = [];

        do {
            try {
                $response    = $client->listProducts($account, $page);
                $blingProds  = $response['data'] ?? [];

                if (empty($blingProds)) {
                    break;
                }

                foreach ($blingProds as $bp) {
                    $sku  = trim($bp['codigo'] ?? '');
                    $cost = (float) ($bp['precoCusto'] ?? 0);

                    if (! $sku || ! isset($skuToId[$sku])) {
                        $stats['skipped_no_match']++;
                        continue;
                    }

                    if ($cost <= 0) {
                        $stats['skipped_zero_cost']++;
                        continue;
                    }

                    $productId = $skuToId[$sku];

                    if (! $dryRun) {
                        DB::table('products')
                            ->where('id', $productId)
                            ->update(['cost' => $cost, 'updated_at' => now()]);
                    } else {
                        $this->line("  [DRY-RUN] product_id={$productId} sku={$sku} cost=0 => {$cost}");
                    }

                    $stats['updated']++;
                    unset($skuToId[$sku]);

                    if (empty($skuToId)) {
                        $this->info("Todos os candidatos processados -- parando paginacao.");
                        break 2;
                    }
                }

                $stats['pages']++;
                $page++;
                usleep(350000);

            } catch (\Throwable $e) {
                $stats['errors']++;
                Log::error('[multdrop:backfill-cost] Erro na pagina ' . $page, ['error' => $e->getMessage()]);
                $this->warn("Erro pagina {$page}: " . $e->getMessage());
                if ($stats['errors'] >= 5) {
                    $this->error("5 erros -- abortando.");
                    break;
                }
                usleep(1000000);
                $page++;
            }
        } while (count($blingProds) >= 100);

        $this->table(
            ['Stat', 'Valor'],
            array_map(fn($k, $v) => [$k, $v], array_keys($stats), $stats)
        );

        Log::info('[multdrop:backfill-cost] Concluido', array_merge($stats, [
            'erp_account_id' => $erpId,
            'dry_run'        => $dryRun,
        ]));

        return self::SUCCESS;
    }
}
