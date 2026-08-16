<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\SyncLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CleanupSyncLogsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $deleted = SyncLog::where('created_at', '<', Carbon::now()->subDays(30))->delete();
        Log::info("CleanupSyncLogsJob: Apagados {$deleted} logs antigos (> 30 dias).");
    }
}
