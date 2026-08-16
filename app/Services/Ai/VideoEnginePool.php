<?php
namespace App\Services\Ai;

use App\Models\VideoEngine;
use Illuminate\Support\Facades\Log;

/**
 * SEL-425/SEL-429 -- Backward compat wrapper para VideoEnginePool.
 *
 * A partir do SEL-429 a logica real esta em AiEnginePool::for('video').
 * Este arquivo e mantido para zero downtime -- todos os Jobs/Services
 * que chamam VideoEnginePool::generate() continuam funcionando.
 *
 * REMOVER esta classe apos confirmar que nenhum codigo referencia
 * VideoEnginePool diretamente (auditar em SEL-429 F2).
 */
class VideoEnginePool
{
    public function __construct(private AiEnginePool $pool) {}

    /**
     * Delega para AiEnginePool::for('video')->generate().
     * Interface identica ao SEL-425.
     */
    public function generate(string $taskId, string $kind, array $payload): array
    {
        Log::debug('[SEL-429][VideoEnginePool] delegando para AiEnginePool::for(video)', [
            'task_id' => $taskId,
            'kind'    => $kind,
        ]);
        return $this->pool->for('video')->generate($taskId, $kind, $payload);
    }
}
