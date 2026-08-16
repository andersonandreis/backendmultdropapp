<?php

namespace App\Jobs;

use App\Services\Notifications\SupplierNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/** NOV-139 — Job que roda checks periódicos. */
class SupplierNotificationsCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public function handle(SupplierNotificationService $svc): void
    {
        $out = $svc->dispatchAllChecks();
        Log::info('[NOV-139] check run', $out);
    }
}
