<?php

namespace App\Console\Commands;

use App\Models\MarketplaceAccount;
use App\Services\Integrations\Erps\Bling\BlingAuthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * bling:recover-tokens
 *
 * Tenta recuperar contas Bling marcadas como needs_reauth que ainda possuem
 * bling_refresh_token valido (antes de expirar em 30 dias).
 *
 * Equivalente ao ml:recover-tokens para o ERP Bling.
 * Criado em 2026-06-25 (NOV-081)
 *
 * Schedule: everyFifteenMinutes (console.php)
 */
class BlingRecoverTokensCommand extends Command
{
    protected $signature = 'bling:recover-tokens
                            {--dry-run : Lista contas elegiveis sem executar}
                            {--limit=50 : Limite de contas por execucao}';

    protected $description = 'Recupera contas Bling needs_reauth com bling_refresh_token valido (sem re-auth do usuario)';

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $limit    = (int) $this->option('limit');

        $this->info('[Bling-Recover] Buscando contas needs_reauth com bling_refresh_token valido...');

        $accounts = MarketplaceAccount::where('platform', 'bling')
            ->where('status', 'needs_reauth')
            ->whereNotNull('bling_refresh_token')
            ->whereNull('sync_blocked_at')
            ->limit($limit)
            ->get();

        $this->info('[Bling-Recover] ' . $accounts->count() . ' conta(s) elegivel(eis) encontrada(s).');

        if ($isDryRun) {
            $this->warn('[DRY-RUN] Nenhuma acao executada.');
            foreach ($accounts as $account) {
                $this->line('  [RECOVERY] ID=' . $account->id
                    . ' account_name=' . $account->account_name
                    . ' client_id=' . $account->client_id
                    . ' bling_expires=' . $account->bling_token_expires_at
                    . ' updated=' . $account->updated_at);
            }
            return self::SUCCESS;
        }

        if ($accounts->isEmpty()) {
            $this->info('[Bling-Recover] Nenhuma conta needs_reauth elegivel. Seguindo para a fase 2.');
        }

        $authService = app(BlingAuthService::class);
        $recovered   = 0;
        $permanent   = 0;
        $temporary   = 0;

        foreach ($accounts as $account) {
            $this->line('  Tentando ID=' . $account->id . ' account_name=' . $account->account_name . '...');

            try {
                $refreshToken = decrypt($account->bling_refresh_token);
                $tokenData    = $authService->refreshToken($refreshToken);
                $authService->saveTokens($account, $tokenData);

                $account->update([
                    'status'               => 'active',
                    'sync_errors_count'    => 0,
                    'sync_blocked_at'      => null,
                    'last_error_message'   => null,
                    'last_token_refresh_at' => now(),
                ]);

                $recovered++;
                $this->info('    [OK] ID=' . $account->id . ' recuperado. bling_expires=' . $account->fresh()->bling_token_expires_at);
                Log::info('[Bling-Recover] Conta recuperada com sucesso', [
                    'account_id'    => $account->id,
                    'client_id'     => $account->client_id,
                    'bling_expires' => $account->fresh()->bling_token_expires_at,
                ]);

            } catch (\RuntimeException $e) {
                if (str_contains($e->getMessage(), 'invalid_grant')
                    || str_contains($e->getMessage(), 'Invalid refresh token')
                    || str_contains($e->getMessage(), '401')
                ) {
                    $permanent++;
                    $account->update([
                        'sync_blocked_at'    => now(),
                        'last_error_message' => 'Bling refresh_token invalido/expirado: reconexao OAuth manual necessaria. Erro: ' . $e->getMessage(),
                    ]);
                    $this->warn('    [PERM] ID=' . $account->id . ' requer reauth manual (token invalido/expirado).');
                    Log::warning('[Bling-Recover] Conta requer reauth manual', [
                        'account_id' => $account->id,
                        'error'      => $e->getMessage(),
                    ]);
                } else {
                    $temporary++;
                    $this->warn('    [TEMP] ID=' . $account->id . ' erro temporario, retry no proximo ciclo. Erro: ' . $e->getMessage());
                    Log::info('[Bling-Recover] Erro temporario, retry depois', [
                        'account_id' => $account->id,
                        'error'      => $e->getMessage(),
                    ]);
                }

            } catch (\Exception $e) {
                $permanent++;
                $account->update([
                    'sync_blocked_at'    => null,
                    'last_error_message' => 'Erro inesperado ao recuperar token Bling: ' . $e->getMessage(),
                ]);
                $this->error('    [ERR] ID=' . $account->id . ' erro inesperado: ' . $e->getMessage());
                Log::error('[Bling-Recover] Erro inesperado', [
                    'account_id' => $account->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        // ── Fase 2 (MUL-360 item 15 / MUL-292): contas ACTIVE com token vencido ──
        // Conta com sync_blocked_at (ex.: importacao desligada na MUL-299) nunca recebe
        // chamada de API, entao o refresh lazy nunca dispara: o access_token vence, o
        // /health da WL quebra e em 30 dias o refresh_token morre de vez. Aqui renovamos
        // SO o token — sync_blocked_at NAO e tocado, a importacao continua desligada.
        // centrally_managed fica de fora: essas recebem token por push do hub.
        $expiring = MarketplaceAccount::where('platform', 'bling')
            ->where('status', 'active')
            ->whereNotNull('bling_refresh_token')
            ->where(function ($q) { $q->whereNull('centrally_managed')->orWhere('centrally_managed', false); })
            ->where('bling_token_expires_at', '<', now()->addMinutes(30))
            ->limit($limit)
            ->get();

        $refreshed = 0;
        $refreshFailed = 0;
        foreach ($expiring as $account) {
            try {
                $authService->getValidToken($account);
                $refreshed++;
                $this->info('  [FASE2-OK] ID=' . $account->id . ' token renovado (sync_blocked_at preservado).');
            } catch (\Throwable $e) {
                $refreshFailed++;
                $this->warn('  [FASE2-ERR] ID=' . $account->id . ': ' . $e->getMessage());
                Log::info('[Bling-Recover] Fase 2: refresh de conta active falhou', [
                    'account_id' => $account->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        $this->newLine();
        $this->table(
            ['Resultado', 'Qtd'],
            [
                ['Recuperadas com sucesso (voltaram para active)', $recovered],
                ['Reauth permanente necessario (token invalido/expirado)', $permanent],
                ['Erro temporario (retry no proximo ciclo)', $temporary],
                ['Fase 2: tokens de contas active renovados', $refreshed],
                ['Fase 2: falhas de renovacao', $refreshFailed],
            ]
        );

        Log::info('[Bling-Recover] Varredura concluida', [
            'total'     => $accounts->count(),
            'recovered' => $recovered,
            'permanent' => $permanent,
            'temporary' => $temporary,
        ]);

        $this->info('[Bling-Recover] Concluido.');
        return self::SUCCESS;
    }
}
