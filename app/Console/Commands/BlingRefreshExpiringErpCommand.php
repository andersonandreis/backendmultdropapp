<?php

namespace App\Console\Commands;

use App\Models\ErpAccount;
use App\Services\Integrations\Erps\Bling\BlingAuthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * MUL-144: Refresh proativo de ErpAccounts Bling com token expirando em < N horas.
 *
 * Usa refreshToken() diretamente (nao o lazy getValidTokenForErp) para
 * garantir renovacao proativa independente do estado do token atual.
 * Se o refresh falhar com invalid_grant, marca needs_reauth.
 *
 * Schedule: everyFourHours (routes/console.php)
 */
class BlingRefreshExpiringErpCommand extends Command
{
    protected $signature = 'bling:refresh-expiring-erp
                            {--hours=6 : Renovar contas com token expirando em N horas}
                            {--dry-run : Lista contas sem executar}';

    protected $description = 'MUL-144: Renova tokens Bling de ErpAccounts antes de expirarem.';

    public function handle(BlingAuthService $auth): int
    {
        $hours   = (int) $this->option('hours');
        $isDry   = (bool) $this->option('dry-run');
        $until   = now()->addHours($hours);

        $accounts = ErpAccount::where('status', 'active')
            ->whereNotNull('refresh_token')
            ->where(function ($q) use ($until) {
                $q->whereNull('token_expires_at')
                  ->orWhere('token_expires_at', '<=', $until);
            })
            ->get();

        $this->info('[Bling-ERP-Refresh] ' . $accounts->count() . ' conta(s) elegivel(eis).');

        if ($isDry) {
            foreach ($accounts as $a) {
                $this->line('  [DRY] ErpAccount #' . $a->id . ' supplier=' . $a->supplier_id . ' expires=' . ($a->token_expires_at ?? 'NULL'));
            }
            return self::SUCCESS;
        }

        if ($accounts->isEmpty()) {
            $this->info('[Bling-ERP-Refresh] Nenhuma conta precisa de renovacao.');
            return self::SUCCESS;
        }

        $renewed   = 0;
        $failed    = 0;

        foreach ($accounts as $account) {
            $this->line('  -> ErpAccount #' . $account->id . ' (supplier=' . $account->supplier_id . ')...');
            try {
                $refreshToken = (string) $account->refresh_token;
                if (! $refreshToken) {
                    $this->warn('     [SKIP] sem refresh_token');
                    continue;
                }
                // Chamar refreshToken() diretamente (proativo, sem lazy check)
                $tokenData = $auth->refreshToken($refreshToken);
                $auth->saveTokensForErp($account, $tokenData);
                $renewed++;
                $account->refresh();
                $this->info('     [OK] expires=' . $account->token_expires_at);
                Log::info('[BlingRefreshExpiringErp] renovado', ['erp_id' => $account->id]);
            } catch (\Throwable $e) {
                $failed++;
                $msg = $e->getMessage();
                $this->warn('     [FAIL] ' . substr($msg, 0, 100));
                if (str_contains($msg, 'invalid_grant')) {
                    $account->update(['status' => 'needs_reauth']);
                    $this->warn('     -> marcado needs_reauth (reconexao OAuth necessaria)');
                }
                Log::error('[BlingRefreshExpiringErp] falha', ['erp_id' => $account->id, 'error' => $e->getMessage()]);
            }
        }

        $this->info('[Bling-ERP-Refresh] Concluido: renewed=' . $renewed . ' failed=' . $failed);

        return self::SUCCESS;
    }
}
