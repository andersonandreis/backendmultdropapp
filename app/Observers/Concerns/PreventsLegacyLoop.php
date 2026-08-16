<?php

namespace App\Observers\Concerns;

/**
 * PreventsLegacyLoop — guard reutilizável para observers que disparam sync legado.
 *
 * Uso:
 *   class MinhaObserver {
 *       use PreventsLegacyLoop;
 *       public function updated($model): void {
 *           if ($this->isLegacySyncInProgress()) return;
 *           // ... dispatch jobs
 *       }
 *   }
 */
trait PreventsLegacyLoop
{
    protected function isLegacySyncInProgress(): bool
    {
        return \App\Observers\ProductObserver::$disableSync === true;
    }
}
