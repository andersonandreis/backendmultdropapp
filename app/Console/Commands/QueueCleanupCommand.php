<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Comando temporario para limpeza de backlog de jobs antigos.
 * Uso: php artisan queue:cleanup-old --hours=24 --queue=default --batch=5000
 */
class QueueCleanupCommand extends Command
{
    protected $signature = 'queue:cleanup-old
                            {--hours=24 : Deletar jobs mais antigos que X horas}
                            {--queue=default : Nome da fila}
                            {--batch=5000 : Tamanho do lote por iteracao}
                            {--dry-run : Apenas contar, nao deletar}';

    protected $description = 'Remove jobs antigos da fila para limpar backlog acumulado';

    public function handle(): int
    {
        $hours    = (int) $this->option('hours');
        $queue    = $this->option('queue');
        $batch    = (int) $this->option('batch');
        $dryRun   = $this->option('dry-run');
        $threshold = time() - ($hours * 3600);

        $this->info("=== Queue Cleanup ===");
        $this->info("Fila: {$queue} | Threshold: -${hours}h | Lote: {$batch}");

        // Contagem antes
        $counts = DB::table('jobs')->selectRaw('queue, COUNT(*) as total')->groupBy('queue')->get();
        $this->table(['queue', 'total'], $counts->map(fn($r) => [$r->queue, $r->total])->toArray());

        $toDelete = DB::table('jobs')
            ->where('queue', $queue)
            ->where('created_at', '<', $threshold)
            ->count();

        $this->info("Jobs '{$queue}' com mais de {$hours}h: {$toDelete}");

        if ($dryRun || $toDelete === 0) {
            $this->info($dryRun ? 'Dry-run: nada deletado.' : 'Nada a deletar.');
            return 0;
        }

        $this->info("Iniciando delecao em lotes de {$batch}...");
        $totalDeleted = 0;

        do {
            $deleted = DB::table('jobs')
                ->where('queue', $queue)
                ->where('created_at', '<', $threshold)
                ->limit($batch)
                ->delete();

            $totalDeleted += $deleted;
            $this->line("  Deletados: {$totalDeleted}");
        } while ($deleted > 0);

        $this->info("Total deletado: {$totalDeleted}");

        // Contagem depois
        $afterCounts = DB::table('jobs')->selectRaw('queue, COUNT(*) as total')->groupBy('queue')->get();
        $this->table(['queue', 'total'], $afterCounts->map(fn($r) => [$r->queue, $r->total])->toArray());

        return 0;
    }
}
