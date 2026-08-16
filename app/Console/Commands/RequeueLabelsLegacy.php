<?php

namespace App\Console\Commands;

use App\Jobs\FetchShippingLabelJob;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RequeueLabelsLegacy extends Command
{
    protected $signature   = 'labels:requeue-legacy {--limit=0 : Limite de pedidos (0=todos)}';
    protected $description = 'Re-enfileira pedidos com label_url do sistemagrupoonline para download local';

    public function handle(): int
    {
        $query = Order::where('label_url', 'like', '%sistemagrupoonline%');
        $total = $query->count();
        $limit = (int) $this->option('limit');

        $this->info("Total de pedidos com URL legada: {$total}");

        if ($total === 0) {
            $this->info('Nenhum pedido a processar.');
            return 0;
        }

        if ($limit > 0) {
            $this->info("Limitando a {$limit} pedidos");
            $query->limit($limit);
        }

        $count = 0;
        $stopped = false;
        $query->chunkById(200, function ($orders) use (&$count, &$stopped, $limit) {
            foreach ($orders as $order) {
                if ($limit > 0 && $count >= $limit) {
                    $stopped = true;
                    return false; // interrompe o chunk
                }
                FetchShippingLabelJob::dispatch($order->id, 'backfill')->onQueue('default');
                $count++;
            }
            $this->info("Enfileirados {$count} ate agora...");
            return !$stopped;
        });

        $this->info("Concluido: {$count} jobs despachados de {$total} pedidos com URL legada.");
        Log::info("[labels:requeue-legacy] {$count} jobs despachados", ['total_legadas' => $total, 'limit' => $limit]);
        return 0;
    }
}
