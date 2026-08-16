<?php

namespace App\Console\Commands;

use App\Models\ErpAccount;
use App\Observers\ProductObserver;
use App\Services\Integrations\Erps\Bling\BlingApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MUL-198 Etapa 2: Backfill products.price=0 via Bling API (preco).
 *
 * Busca os produtos com cost>0 e price=0 do supplier 30.
 * Para cada um:
 *   - Tem preco no Bling (preco>0) -> atualiza products.price
 *   - Sem preco no Bling (preco=0 ou nao encontrado) -> is_active=0 + relatorio
 *
 * Nao cria produtos novos.
 * Nao altera products que ja tem price>0.
 * ProductObserver.$disableSync=true durante execucao (nao dispara sync legado).
 *
 * Uso:
 *   php artisan multdrop:backfill-price --erp-account=1
 *   php artisan multdrop:backfill-price --erp-account=1 --dry-run
 */
class BackfillProductPriceFromBling extends Command
{
    protected $signature = 'multdrop:backfill-price
        {--erp-account=1 : ID do erp_account Bling (padrao: 1)}
        {--dry-run : Simula sem atualizar banco}';

    protected $description = 'MUL-198: Backfill de products.price=0 via Bling API (preco); sem preco -> is_active=0';

    public function handle(BlingApiClient $client): int
    {
        $erpId  = (int) $this->option('erp-account');
        $dryRun = (bool) $this->option('dry-run');

        $account = ErpAccount::find($erpId);
        if (! $account) {
            $this->error("ErpAccount #{$erpId} nao encontrado.");
            return self::FAILURE;
        }

        $this->info("MUL-198 Backfill price via Bling -- ErpAccount #{$erpId} (supplier_id={$account->supplier_id})");
        $this->info("Dry-run: " . ($dryRun ? 'SIM' : 'NAO'));

        $candidateRows = DB::table('products')
            ->where('supplier_id', $account->supplier_id)
            ->where('cost', '>', 0)
            ->where(function ($q) {
                $q->where('price', 0)->orWhereNull('price');
            })
            ->get(['id', 'sku'])
            ->all();

        $this->info("Produtos com cost>0 e price=0/null: " . count($candidateRows));

        if (empty($candidateRows)) {
            $this->info("Nenhum produto candidato. Nada a fazer.");
            return self::SUCCESS;
        }

        // Indexar por SKU para busca rapida
        $skuToId = [];
        foreach ($candidateRows as $row) {
            $skuToId[$row->sku] = $row->id;
            // Variante sem prefixo legado D{deposito}-
            $rawSku = preg_replace('/^D[0-9]+-/', '', $row->sku);
            if ($rawSku !== $row->sku) {
                $skuToId[$rawSku] = $row->id;
            }
        }

        $stats = [
            'updated'          => 0,
            'deactivated'      => 0,
            'skipped_no_match' => 0,
            'errors'           => 0,
            'pages'            => 0,
        ];

        $nopriceReport = []; // SKUs sem preco no Bling
        $page          = 1;
        $blingProds    = [];

        // Anti-loop: nao disparar sync legado durante o update
        $wasDisabled = ProductObserver::$disableSync;
        ProductObserver::$disableSync = true;

        try {
            do {
                try {
                    $response   = $client->listProducts($account, $page);
                    $blingProds = $response['data'] ?? [];

                    if (empty($blingProds)) {
                        break;
                    }

                    foreach ($blingProds as $bp) {
                        $sku   = trim($bp['codigo'] ?? '');
                        $price = (float) ($bp['preco'] ?? 0);

                        if (! $sku || ! isset($skuToId[$sku])) {
                            $stats['skipped_no_match']++;
                            continue;
                        }

                        if ($price > 0) {
                            // Tem preco no Bling -> atualizar
                            if (! $dryRun) {
                                // DB::table nao dispara observers (anti-loop)
                                DB::table('products')
                                    ->where('id', $skuToId[$sku])
                                    ->where('price', '<=', 0)
                                    ->update(['price' => $price, 'updated_at' => now()]);
                            } else {
                                $this->line("  [DRY-RUN] PRICE product_id={$skuToId[$sku]} sku={$sku} price=0 => {$price}");
                            }
                            $stats['updated']++;
                        } else {
                            // Sem preco no Bling -> desativar
                            $nopriceReport[] = ['id' => $skuToId[$sku], 'sku' => $sku, 'bling_price' => 0];
                            if (! $dryRun) {
                                DB::table('products')
                                    ->where('id', $skuToId[$sku])
                                    ->update(['is_active' => 0, 'updated_at' => now()]);
                            } else {
                                $this->line("  [DRY-RUN] DEACTIVATE product_id={$skuToId[$sku]} sku={$sku} (sem preco no Bling)");
                            }
                            $stats['deactivated']++;
                        }

                        unset($skuToId[$sku]);
                        if (empty($skuToId)) {
                            $this->info("Todos os candidatos encontrados -- parando paginacao.");
                            break 2;
                        }
                    }

                    $stats['pages']++;
                    $page++;
                    usleep(350000);

                } catch (\Throwable $e) {
                    $stats['errors']++;
                    Log::error('[multdrop:backfill-price] Erro na pagina ' . $page, ['error' => $e->getMessage()]);
                    $this->warn("Erro pagina {$page}: " . $e->getMessage());
                    if ($stats['errors'] >= 5) {
                        $this->error("Muitos erros consecutivos -- abortando.");
                        break;
                    }
                    $page++;
                    usleep(1000000);
                }
            } while (count($blingProds) >= 100);

            // Produtos candidatos nao encontrados no Bling -> desativar
            foreach ($skuToId as $sku => $productId) {
                $nopriceReport[] = ['id' => $productId, 'sku' => $sku, 'bling_price' => 'NOT_FOUND'];
                if (! $dryRun) {
                    DB::table('products')
                        ->where('id', $productId)
                        ->update(['is_active' => 0, 'updated_at' => now()]);
                } else {
                    $this->line("  [DRY-RUN] DEACTIVATE product_id={$productId} sku={$sku} (nao encontrado no Bling)");
                }
                $stats['deactivated']++;
            }

        } finally {
            ProductObserver::$disableSync = $wasDisabled;
        }

        $this->info('');
        $this->info('=== RESULTADO MUL-198 Backfill Price ===');
        $this->info("updated (price preenchido):    {$stats['updated']}");
        $this->info("deactivated (sem price Bling): {$stats['deactivated']}");
        $this->info("skipped (sem match no banco):  {$stats['skipped_no_match']}");
        $this->info("errors:                        {$stats['errors']}");
        $this->info("pages:                         {$stats['pages']}");

        if (! empty($nopriceReport)) {
            $this->info('');
            $this->info('=== RELATORIO: Produtos desativados (sem preco no Bling) ===');
            foreach ($nopriceReport as $item) {
                $this->line("  product_id={$item['id']} sku={$item['sku']} bling_price={$item['bling_price']}");
            }
        }

        Log::info('[multdrop:backfill-price] concluido', [
            'stats'       => $stats,
            'deactivated' => array_slice($nopriceReport, 0, 50),
        ]);

        return self::SUCCESS;
    }
}
