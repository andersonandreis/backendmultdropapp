<?php

namespace App\Console\Commands;

use App\Services\ImageDownloadService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DownloadProductImages extends Command
{
    protected $signature = 'images:download-local {--dry-run : Show what would be done without changing anything}';
    protected $description = 'Download external product images to local storage and update product_media URLs';

    public function handle(ImageDownloadService $svc): int
    {
        $dryRun = $this->option('dry-run');

        $rows = DB::table('product_media')
            ->whereNotNull('url')
            ->where('url', 'not like', '/storage/%')
            ->where('url', 'not like', '%shopee%')
            ->where('url', 'not like', '%shopee-static%')
            ->select(['id', 'url'])
            ->get();

        $total   = $rows->count();
        $success = 0;
        $failed  = 0;
        $skipped = 0;

        $this->info("Found {$total} external images to process.");

        if ($dryRun) {
            $this->warn('[DRY RUN] No changes will be made.');
            $this->line($rows->take(10)->pluck('url')->implode("\n"));
            return Command::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($rows as $row) {
            if (!preg_match('#^https?://#i', $row->url)) {
                $skipped++;
                $bar->advance();
                continue;
            }

            $localPath = $svc->downloadFromUrl($row->url);

            if ($localPath) {
                DB::table('product_media')
                    ->where('id', $row->id)
                    ->update(['url' => '/storage/' . $localPath]);
                $success++;
            } else {
                $failed++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Done. Success: {$success} | Failed: {$failed} | Skipped: {$skipped}");

        return Command::SUCCESS;
    }
}
