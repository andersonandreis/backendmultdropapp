<?php

namespace App\Console\Commands;

use App\Services\Integrations\TokenRefreshService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * tokens:proactive-refresh
 *
 * Delega para TokenRefreshService (fonte unica de verdade para refresh de tokens).
 * Agenda: a cada 15 minutos (routes/console.php).
 * Complementa o RefreshTokensJob (90/120min) com maior frequencia.
 *
 * IMPORTANTE: toda logica de circuit breaker e contagem de erros esta no TokenRefreshService.
 * Este comando NAO duplica a logica - apenas aciona o service com antecedencia.
 */
class ProactiveTokenRefreshCommand extends Command
{
    protected $signature = 'tokens:proactive-refresh
                            {--dry-run : Mostra contas elegiveis sem renovar}';

    protected $description = 'Renova proativamente tokens Shopee + ML (delega ao TokenRefreshService)';

    public function handle(): int
    {
        $this->info('[ProactiveRefresh] Iniciando refresh proativo de tokens...');
        Log::info('[ProactiveRefresh] Disparado pelo scheduler (15min)');

        try {
            $service = app(TokenRefreshService::class);
            $service->refreshExpiringTokens();
            $this->info('[ProactiveRefresh] Concluido com sucesso.');
        } catch (\Throwable $e) {
            $this->error('[ProactiveRefresh] Erro: ' . $e->getMessage());
            Log::error('[ProactiveRefresh] Erro ao executar', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
