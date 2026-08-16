<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * NOV-217 — Artisan command equivalente ao cron sync-whitelabel-data-daily (jobid=38).
 * Chama o endpoint Laravel POST /api/v1/wl/sync internamente.
 *
 * Agendamento: $schedule->command('wl:sync-snapshot')->daily();
 *
 * ATENCAO: nao remove o pg_cron do Supabase ate este command estar validado em producao.
 */
class WlSyncSnapshotCommand extends Command
{
    protected $signature   = 'wl:sync-snapshot {--dry-run : Apenas loga, nao executa}';
    protected $description = 'NOV-217: Sincroniza snapshots diarios das Whitelabels (equivalente ao sync-whitelabel-data Supabase).';

    public function handle(): int
    {
        $dryRun = (bool)$this->option('dry-run');

        $this->info('[wl:sync-snapshot] Iniciando sincronizacao de snapshots...');

        if ($dryRun) {
            $this->warn('[wl:sync-snapshot] DRY RUN — nenhuma alteracao sera feita.');
            return Command::SUCCESS;
        }

        $url     = config('app.url') . '/api/v1/wl/sync';
        $adminKey = config('services.internal_admin_token', env('INTERNAL_ADMIN_TOKEN', ''));

        $resp = Http::withHeaders([
            'Authorization' => 'Bearer ' . $adminKey,
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ])->timeout(60)->post($url, []);

        if (!$resp->successful()) {
            $this->error('[wl:sync-snapshot] Erro HTTP ' . $resp->status() . ': ' . $resp->body());
            Log::error('[wl:sync-snapshot] Erro: ' . $resp->body());
            return Command::FAILURE;
        }

        $data = $resp->json();
        $this->info('[wl:sync-snapshot] Synced: ' . ($data['synced'] ?? 0) . '/' . ($data['total'] ?? 0) . ', erros: ' . ($data['errors'] ?? 0));

        Log::info('[wl:sync-snapshot] Concluido: ' . json_encode($data));
        return Command::SUCCESS;
    }
}
