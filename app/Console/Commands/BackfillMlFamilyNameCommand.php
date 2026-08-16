<?php

namespace App\Console\Commands;

use App\Jobs\BackfillMlFamilyNameJob;
use App\Models\ClientProduct;
use Illuminate\Console\Command;

/**
 * FOR-088 / FOR-080: Dispara backfill de ml_family_name em batches de 200.
 *
 * Uso: php artisan for-088:backfill-ml-family-name [--limit=1000]
 */
class BackfillMlFamilyNameCommand extends Command
{
    protected $signature   = 'for-088:backfill-ml-family-name {--limit=0 : Limitar numero de produtos (0 = todos)}';
    protected $description = 'FOR-088/FOR-080: backfill ml_family_name via ML API em batches de 200';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        $query = ClientProduct::whereNotNull('external_listing_id')
            ->whereNull('ml_family_name');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $total = $query->count();
        $this->info("FOR-088: {$total} produtos para backfill (batches de 200)");

        $dispatched = 0;
        $query->select('id')->chunkById(200, function ($chunk) use (&$dispatched) {
            $ids = $chunk->pluck('id')->toArray();
            BackfillMlFamilyNameJob::dispatch($ids)->onQueue('default');
            $dispatched += count($ids);
        });

        $this->info("FOR-088: {$dispatched} produtos enfileirados para backfill.");
        return self::SUCCESS;
    }
}
