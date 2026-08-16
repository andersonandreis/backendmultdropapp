<?php

namespace App\Console\Commands;

use App\Models\SiteAnalyticsEvent;
use App\Models\SiteSessionRecording;
use Illuminate\Console\Command;

/**
 * INF-030 (Ruan 12/08, ampliação) — "não deixar o disco encher". Gravação de
 * sessão e amostragem de mouse geram MUITO dado (servidor já está em 75% de
 * disco, 145GB de 194GB). Roda diário via schedule (routes/console.php):
 *  - Gravações (site_session_recordings): apaga > 30 dias.
 *  - mousemove_batch (o mais volumoso, é amostragem contínua): apaga > 45 dias.
 *  - click/video_progress/pageview/pageview_end: mantém mais tempo (baixo
 *    volume, é histórico de negócio útil) — apaga > 180 dias.
 * Sempre em lotes (chunkById) pra não travar a tabela numa DELETE gigante
 * de uma vez — servidor já está com load 11 em 8 núcleos.
 */
class AnalyticsCleanupCommand extends Command
{
    protected $signature = 'analytics:cleanup';

    protected $description = 'Limpa gravações de sessão e eventos de analytics antigos (rrweb + site_analytics_events) pra não encher o disco.';

    public function handle(): int
    {
        $recDeleted = $this->deleteInBatches(
            SiteSessionRecording::where('created_at', '<', now()->subDays(30)),
        );
        $this->info("site_session_recordings: {$recDeleted} linhas apagadas (>30 dias).");

        $moveDeleted = $this->deleteInBatches(
            SiteAnalyticsEvent::where('event_type', 'mousemove_batch')->where('created_at', '<', now()->subDays(45)),
        );
        $this->info("site_analytics_events (mousemove_batch): {$moveDeleted} linhas apagadas (>45 dias).");

        $eventsDeleted = $this->deleteInBatches(
            SiteAnalyticsEvent::whereNotIn('event_type', ['mousemove_batch'])->where('created_at', '<', now()->subDays(180)),
        );
        $this->info("site_analytics_events (demais tipos): {$eventsDeleted} linhas apagadas (>180 dias).");

        return self::SUCCESS;
    }

    private function deleteInBatches($query, int $batchSize = 2000): int
    {
        $total = 0;
        do {
            $ids = (clone $query)->limit($batchSize)->pluck('id');
            if ($ids->isEmpty()) break;
            $n = (clone $query)->whereIn('id', $ids)->delete();
            $total += $n;
        } while ($n === $batchSize);

        return $total;
    }
}
