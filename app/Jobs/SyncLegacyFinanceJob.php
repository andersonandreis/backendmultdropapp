<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Polling incremental do financeiro do legado.
 * Registrado em routes/console.php: ->everyFiveMinutes()->withoutOverlapping().
 * Roda o comando finance:sync-legacy SEM --wipe (so insere lancamentos novos).
 *
 * Guard: services.legacy.finance_sync_enabled deve ser true para executar.
 * Por padrao false — jobs existentes na fila serao consumidos e descartados silenciosamente.
 */
class SyncLegacyFinanceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('legacy'); // NOV-199: carga do legado nao compete com a fila default
    }

    public function handle(): void
    {
        // Guard: flag desativada — consumir job e sair sem executar o sync.
        // Isso esvazia a fila de jobs acumulados sem causar efeito colateral.
        $enabled = config('services.legacy.finance_sync_enabled', false);
        if (! $enabled) {
            Log::debug('SyncLegacyFinanceJob: flag desativada, job descartado.');
            return;
        }

        Artisan::call('finance:sync-legacy');
        Log::info('SyncLegacyFinanceJob: ' . trim(Artisan::output()));
    }
}
