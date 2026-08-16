<?php

namespace App\Jobs;

use App\Models\Supplier;
use App\Services\Marketplaces\ProductQuestionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * NOV-123: roda no schedule a cada 30min e busca perguntas pendentes por supplier.
 *
 * INF-029-B: timeout aumentado para 600s (antes 300s estourava com 242+ contas ML).
 * tries=1 mantido — falha de timeout nao deve retentar imediatamente.
 *
 * FOR-071: adicionado failed() method para capturar motivo real quando job e
 * eliminado externamente (SIGKILL por timeout do worker). Tambem adicionado log
 * de progresso por supplier para identificar qual conta trava o job.
 */
class SyncProductQuestionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600; // INF-029-B: 300s nao era suficiente com 242 contas ML (fornecefy)
    public int $tries = 1;

    public function __construct(public ?int $supplierId = null) {}

    public function handle(ProductQuestionService $svc): void
    {
        $suppliers = $this->supplierId
            ? Supplier::query()->where('id', $this->supplierId)->get()
            : Supplier::query()->where('is_active', true)->get();

        Log::info('[NOV-123] iniciando sync perguntas', [
            'supplier_id' => $this->supplierId ?? 'all',
            'total_suppliers' => $suppliers->count(),
            'job_id' => $this->job?->getJobId(),
        ]);

        foreach ($suppliers as $supplier) {
            $startedAt = microtime(true);
            try {
                $out = $svc->syncForSupplier($supplier->id);
                $elapsed = round(microtime(true) - $startedAt, 2);
                Log::info('[NOV-123] sync', [
                    'supplier_id' => $supplier->id,
                    'out' => $out,
                    'elapsed_s' => $elapsed,
                ]);
            } catch (\Throwable $e) {
                // INF-029-B: log warning em vez de deixar propagar (nao gerar Sentry por conta individual)
                $elapsed = round(microtime(true) - $startedAt, 2);
                Log::warning('[NOV-123] sync supplier failure', [
                    'supplier_id' => $supplier->id,
                    'exception' => get_class($e),
                    'err' => $e->getMessage(),
                    'elapsed_s' => $elapsed,
                ]);
            }
        }

        Log::info('[NOV-123] sync perguntas concluido', [
            'supplier_id' => $this->supplierId ?? 'all',
        ]);
    }

    /**
     * FOR-071: chamado pelo Laravel quando o job e descartado (max_attempts ou timeout externo).
     * Unica forma de capturar o motivo real quando o worker mata o processo via SIGKILL.
     */
    public function failed(\Throwable $e): void
    {
        Log::error('[NOV-123] FOR-071 FALHA FINAL — job descartado', [
            'supplier_id'   => $this->supplierId ?? 'all',
            'exception'     => get_class($e),
            'message'       => $e->getMessage(),
            'attempts'      => $this->attempts(),
            'trace_lines'   => array_slice(explode(n, $e->getTraceAsString()), 0, 8),
            'hint'          => get_class($e) === \Illuminate\Queue\TimeoutExceededException::class
                ? 'Job ultrapassou timeout=600s — verificar supplier mais lento nos logs INFO acima'
                : (get_class($e) === \Illuminate\Queue\MaxAttemptsExceededException::class
                    ? 'Job esgotou tries — rever tries no supervisor (atualmente --tries=3 sobrescreve tries=1 do job)'
                    : 'Erro inesperado — verificar trace'),
        ]);
    }
}
