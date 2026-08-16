<?php

namespace App\Jobs;

use App\Models\AutoListingConfig;
use App\Models\AutoListingQueueItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AutoListingDispatcherJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;
    public $timeout = 60;

    public function handle(): void
    {
        // Buscar combinações únicas de client+account com itens pendentes
        $entries = AutoListingQueueItem::processable()
            ->select('client_id', 'marketplace_account_id')
            ->distinct()
            ->get();

        foreach ($entries as $entry) {
            $this->processClientQueue($entry->client_id, $entry->marketplace_account_id);
        }
    }

    private function processClientQueue(int $clientId, int $accountId): void
    {
        $config = AutoListingConfig::getEffective($clientId, $accountId);

        if ($config->status !== 'active') {
            return;
        }

        if (! $config->isWithinActiveHours()) {
            return;
        }

        // Rate limit: verificar quantos já foram processados
        $processedLastHour = AutoListingQueueItem::where('client_id', $clientId)
            ->where('marketplace_account_id', $accountId)
            ->where('started_at', '>=', now()->subHour())
            ->whereIn('status', ['processing', 'completed'])
            ->count();

        if ($processedLastHour >= $config->max_listings_per_hour) {
            return;
        }

        $processedToday = AutoListingQueueItem::where('client_id', $clientId)
            ->where('marketplace_account_id', $accountId)
            ->whereDate('started_at', today())
            ->whereIn('status', ['processing', 'completed'])
            ->count();

        if ($processedToday >= $config->max_listings_per_day) {
            return;
        }

        // Calcular slots disponíveis neste ciclo
        $available = min(
            $config->max_listings_per_hour - $processedLastHour,
            $config->max_listings_per_day - $processedToday,
            5 // max por ciclo do dispatcher
        );

        if ($available <= 0) {
            return;
        }

        // Pegar itens processáveis ordenados por prioridade
        $items = AutoListingQueueItem::processable()
            ->where('client_id', $clientId)
            ->where('marketplace_account_id', $accountId)
            ->orderBy('priority')
            ->orderBy('created_at')
            ->limit($available)
            ->get();

        foreach ($items as $i => $item) {
            ProcessAutoListingItemJob::dispatch($item)
                ->delay(now()->addSeconds($i * $config->delay_between_listings_seconds))
                ->onQueue('auto-listing');
        }
    }
}
