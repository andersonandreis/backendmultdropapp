<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * NOV-217 — Artisan command equivalente ao cron pg_cron close-whitelabel-cycle-dia1 (jobid=30).
 * Chama o endpoint Laravel POST /api/v1/wl/cycle/close internamente.
 *
 * Agendamento: $schedule->command('wl:close-cycle')->monthlyOn(1, '06:00');
 *
 * ATENCAO: nao remove o pg_cron do Supabase ate este command estar validado em producao.
 */
class WlCloseCycleCommand extends Command
{
    protected $signature   = 'wl:close-cycle {--empresa_id= : Processar apenas uma empresa} {--recalc-only : Recalcular sem mudar status} {--dry-run : Apenas loga, nao executa}';
    protected $description = 'NOV-217: Fecha o ciclo mensal de billing das Whitelabels (equivalente ao close-whitelabel-cycle Supabase).';

    public function handle(): int
    {
        $empresaId  = $this->option('empresa_id');
        $recalcOnly = (bool)$this->option('recalc-only');
        $dryRun     = (bool)$this->option('dry-run');

        $this->info('[wl:close-cycle] Iniciando fechamento de ciclo mensal...');

        if ($dryRun) {
            $this->warn('[wl:close-cycle] DRY RUN — nenhuma alteracao sera feita.');
            return Command::SUCCESS;
        }

        $body = ['recalc_only' => $recalcOnly];
        if ($empresaId) $body['empresa_id'] = (int)$empresaId;

        // Chama o proprio endpoint Laravel (nao precisa de auth externa — chama internamente)
        $url     = config('app.url') . '/api/v1/wl/cycle/close';
        $adminKey = config('services.internal_admin_token', env('INTERNAL_ADMIN_TOKEN', ''));

        $resp = Http::withHeaders([
            'Authorization' => 'Bearer ' . $adminKey,
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ])->timeout(120)->post($url, $body);

        if (!$resp->successful()) {
            $this->error('[wl:close-cycle] Erro HTTP ' . $resp->status() . ': ' . $resp->body());
            Log::error('[wl:close-cycle] Erro: ' . $resp->body());
            return Command::FAILURE;
        }

        $data = $resp->json();
        $this->info('[wl:close-cycle] Processados: ' . ($data['processed'] ?? 0));
        foreach (($data['details'] ?? []) as $d) {
            $this->line("  empresa={$d['empresa_id']} {$d['empresa_nome']}: users={$d['billable_users']} orders={$d['orders_count']} amount_due=R\${$d['amount_due']}");
        }
        if (!empty($data['errors'])) {
            foreach ($data['errors'] as $e) {
                $this->warn('  ERRO: ' . $e);
            }
        }

        Log::info('[wl:close-cycle] Concluido: ' . json_encode($data));
        return Command::SUCCESS;
    }
}
