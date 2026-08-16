<?php

namespace App\Jobs;

use App\Models\ProductListingJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * NOV-072 - Robo de Cadastro v2
 *
 * Dispatcher da fila product_listing_jobs. Roda a cada minuto via Schedule
 * com withoutOverlapping() para evitar processamento duplicado.
 *
 * Logica:
 * 1. Agrupa pending jobs por client_id.
 * 2. Para cada cliente, respeita o limite de velocidade (slow/normal/fast).
 * 3. Despacha ProcessProductListingJob para cada slot disponivel.
 *
 * Rate limits por ciclo (1 min):
 *   slow   -> 1 job/ciclo
 *   normal -> 5 jobs/ciclo
 *   fast   -> 20 jobs/ciclo
 *
 * Queue: sync (dispatcher e rapido, nao bloqueia o worker)
 */
class ProductListingDispatcherJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 60;

    public function handle(): void
    {
        // Obter clientes unicos com jobs pendentes e a velocidade mais frequente
        $clientEntries = ProductListingJob::pending()
            ->select('client_id', 'speed')
            ->selectRaw('COUNT(*) as pending_count')
            ->groupBy('client_id', 'speed')
            ->get();

        if ($clientEntries->isEmpty()) {
            return;
        }

        // Agrupar por client_id (pode ter linhas com speeds diferentes)
        $byClient = $clientEntries->groupBy('client_id');

        foreach ($byClient as $clientId => $rows) {
            // Velocidade dominante (maior count de pendentes)
            $dominantRow = $rows->sortByDesc('pending_count')->first();
            $speed       = $dominantRow->speed ?? 'normal';
            $maxPerCycle = ProductListingJob::maxPerCycleForSpeed($speed);

            // Verificar quantos estao em processamento agora para nao ultrapassar o limite
            $processing = ProductListingJob::processing()
                ->where('client_id', $clientId)
                ->count();

            $available = max(0, $maxPerCycle - $processing);

            if ($available <= 0) {
                Log::channel('queue')->debug('[ProductListingDispatcher] Cliente no limite, pulando', [
                    'client_id'  => $clientId,
                    'processing' => $processing,
                    'max'        => $maxPerCycle,
                ]);
                continue;
            }

            // Pegar os proximos jobs a processar (FIFO por created_at)
            $jobs = ProductListingJob::pending()
                ->where('client_id', $clientId)
                ->orderBy('created_at')
                ->limit($available)
                ->get();

            foreach ($jobs as $listingJob) {
                ProcessProductListingJob::dispatch($listingJob->id)
                    ->onQueue('product-listing');
            }

            Log::channel('queue')->info('[ProductListingDispatcher] Despachou jobs', [
                'client_id'     => $clientId,
                'dispatched'    => $jobs->count(),
                'speed'         => $speed,
                'max_per_cycle' => $maxPerCycle,
            ]);
        }
    }
}
