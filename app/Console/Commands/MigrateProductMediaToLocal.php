<?php

namespace App\Console\Commands;

use App\Services\ImageDownloadService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Migra product_media externo -> local em api.fornecefy.io/storage.
 *
 * - Pega URLs que NAO sejam locais e NAO tenham local_path setado.
 * - Para cada uma: salva original_url, baixa via ImageDownloadService,
 *   atualiza url + local_path.
 * - Idempotente: re-rodar nao duplica (skip rows ja migradas).
 * - Falhas: loga, segue, deixa row pra retentar na proxima rodada.
 */
class MigrateProductMediaToLocal extends Command
{
    protected $signature = 'images:migrate-to-local
                            {--batch=100 : Tamanho do batch}
                            {--max= : Limite total de imagens (debug; omitir = todas)}
                            {--supplier= : Filtra por supplier_id (omitir = todos)}
                            {--dry-run : So mostra o que faria}';

    protected $description = 'Migra product_media externo para storage local (api.fornecefy.io/storage)';

    public function handle(ImageDownloadService $svc): int
    {
        $batch    = max(1, (int) $this->option('batch'));
        $max      = $this->option('max') ? (int) $this->option('max') : null;
        $supplier = $this->option('supplier') ? (int) $this->option('supplier') : null;
        $dryRun   = (bool) $this->option('dry-run');

        $q = DB::table('product_media as pm')
            ->join('products as p', 'p.id', '=', 'pm.product_id')
            ->whereNull('pm.local_path')
            ->whereNotNull('pm.url')
            ->where('pm.url', 'LIKE', 'http%')
            ->where('pm.url', 'NOT LIKE', '%api.fornecefy.io/storage%')
            ->select('pm.id', 'pm.url', 'pm.product_id', 'p.supplier_id');

        if ($supplier) $q->where('p.supplier_id', $supplier);

        $total = (clone $q)->count();
        $work  = $max ? min($max, $total) : $total;

        $this->info("Total de imagens externas migraveis: {$total}");
        $this->info("Vou processar: {$work} (batch={$batch}, supplier=" . ($supplier ?? 'all') . ')');

        if ($dryRun) {
            $this->warn('[DRY RUN] Listando 10 primeiras URLs:');
            $samples = (clone $q)->limit(10)->get();
            foreach ($samples as $s) {
                $this->line("  - pm#{$s->id} (product {$s->product_id}, supplier {$s->supplier_id}): {$s->url}");
            }
            return self::SUCCESS;
        }

        if ($work === 0) {
            $this->info('Nada para fazer.');
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($work);
        $bar->start();

        $success = 0;
        $failed  = 0;
        $done    = 0;

        $q->orderBy('pm.id')->chunkById($batch, function ($rows) use ($svc, $bar, &$success, &$failed, &$done, $max) {
            foreach ($rows as $row) {
                if ($max !== null && $done >= $max) {
                    return false;
                }
                $done++;

                $result = $svc->downloadAndStoreImage(
                    (string) $row->url,
                    (int) $row->supplier_id,
                    (int) $row->product_id
                );

                if ($result === null) {
                    $failed++;
                    DB::table('product_media')
                        ->where('id', $row->id)
                        ->update(['updated_at' => now()]); // touch pra debug
                } else {
                    DB::table('product_media')
                        ->where('id', $row->id)
                        ->update([
                            'original_url' => $row->url,
                            'url'          => $result['url'],
                            'local_path'   => $result['path'],
                            'updated_at'   => now(),
                        ]);
                    $success++;
                }
                $bar->advance();
            }
        }, 'pm.id', 'id');

        $bar->finish();
        $this->newLine();
        $this->info("Done. Migrados: {$success} | Falharam: {$failed} | Total processado: {$done}");

        return self::SUCCESS;
    }
}
