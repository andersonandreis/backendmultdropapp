<?php

namespace App\Console\Commands;

use App\Jobs\SyncInventoryJob;
use App\Models\ClientProduct;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * NOV-081: Retry automatico de listings com sync_status=failed.
 *
 * Problema: listings que falham ao sincronizar estoque/preco ficam em
 * sync_status=failed para sempre, sem nenhum mecanismo de retry.
 *
 * Solucao: este command varre client_products com sync_status=failed,
 * sync_attempt_count < 3 e next_retry_at <= now() (ou NULL), e dispara
 * SyncInventoryJob com jitter para evitar thundering herd.
 *
 * Limite: 500 registros por execucao.
 * Schedule: hourly com withoutOverlapping (definido em routes/console.php).
 */
class RetryFailedSyncsCommand extends Command
{
    protected $signature = 'marketplace:retry-failed-syncs
                            {--dry-run : Lista candidatos sem disparar jobs}
                            {--limit=500 : Limite de registros por execucao (max 500)}
                            {--max-attempts=3 : Numero maximo de tentativas antes de desistir}';

    protected $description = 'Reprocessa listings com sync_status=failed (max 3 tentativas, backoff exponencial)';

    public function handle(): int
    {
        $isDryRun    = $this->option('dry-run');
        $limit       = min((int) $this->option('limit'), 500);
        $maxAttempts = (int) $this->option('max-attempts');

        $this->info("[RetryFailedSyncs] Buscando listings failed (limit={$limit}, max_attempts={$maxAttempts})...");

        $candidates = ClientProduct::where('sync_status', 'failed')
            ->where('sync_attempt_count', '<', $maxAttempts)
            ->where(function ($q) {
                $q->whereNull('next_retry_at')
                  ->orWhere('next_retry_at', '<=', now());
            })
            ->whereNotNull('external_listing_id')
            ->limit($limit)
            ->get();

        $this->info("[RetryFailedSyncs] {$candidates->count()} candidato(s) encontrado(s).");

        if ($isDryRun) {
            $this->warn('[DRY-RUN] Nenhum job disparado.');
            foreach ($candidates as $cp) {
                $this->line(sprintf(
                    '  ID=%-8d platform=%-14s attempt=%d next_retry=%s',
                    $cp->id,
                    $cp->platform ?? 'unknown',
                    $cp->sync_attempt_count,
                    $cp->next_retry_at?->toDateTimeString() ?? 'NULL'
                ));
            }
            return self::SUCCESS;
        }

        if ($candidates->isEmpty()) {
            $this->info('[RetryFailedSyncs] Nada para reprocessar.');
            return self::SUCCESS;
        }

        $dispatched = 0;
        $skipped    = 0;

        foreach ($candidates as $cp) {
            try {
                // Jitter: delay aleatorio entre 0 e 300 segundos para evitar thundering herd
                $jitterSeconds = rand(0, 300);

                // Incrementa attempt_count e define proxima janela de retry (backoff exponencial: 1h, 4h, 24h)
                $backoffHours = match (true) {
                    $cp->sync_attempt_count === 0 => 1,
                    $cp->sync_attempt_count === 1 => 4,
                    default                       => 24,
                };

                $cp->update([
                    'sync_status'        => 'pending',   // volta para pending para o job reprocessar
                    'sync_attempt_count' => $cp->sync_attempt_count + 1,
                    'next_retry_at'      => now()->addHours($backoffHours),
                ]);

                SyncInventoryJob::dispatch($cp->id)->delay(now()->addSeconds($jitterSeconds));

                $dispatched++;
                Log::info('[RetryFailedSyncs] Job despachado', [
                    'client_product_id' => $cp->id,
                    'attempt'           => $cp->sync_attempt_count,
                    'jitter_seconds'    => $jitterSeconds,
                    'next_retry_at'     => now()->addHours($backoffHours)->toDateTimeString(),
                ]);
            } catch (\Throwable $e) {
                $skipped++;
                $this->error("  [ERR] ID={$cp->id}: " . $e->getMessage());
                Log::error('[RetryFailedSyncs] Falha ao despachar job', [
                    'client_product_id' => $cp->id,
                    'error'             => $e->getMessage(),
                ]);
            }
        }

        $this->newLine();
        $this->table(
            ['Resultado', 'Qtd'],
            [
                ['Jobs despachados', $dispatched],
                ['Erros ao despachar', $skipped],
                ['Total candidatos', $candidates->count()],
            ]
        );

        Log::info('[RetryFailedSyncs] Varredura concluida', [
            'dispatched' => $dispatched,
            'skipped'    => $skipped,
            'total'      => $candidates->count(),
        ]);

        $this->info('[RetryFailedSyncs] Concluido.');
        return self::SUCCESS;
    }
}
