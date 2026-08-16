<?php

namespace App\Console\Commands;

use App\Jobs\CheckMLListingsJob;
use App\Jobs\PublishClientProductToMLJob;
use App\Models\ClientProduct;
use App\Models\MarketplaceAccount;
use App\Services\Integrations\Marketplaces\MercadoLivreService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ml:recover-tokens
 *
 * Tenta recuperar contas ML marcadas como needs_reauth que ainda possuem
 * ml_refresh_token valido.
 *
 * Criado em 2026-06-18 (FOR-016)
 * NOV-082 (2026-06-25): inclui platform='ml' (33 contas legadas ignoradas silenciosamente)
 */
class MlRecoverTokensCommand extends Command
{
    protected $signature = 'ml:recover-tokens
                            {--dry-run : Lista contas elegiveis sem executar}
                            {--limit=50 : Limite de contas por execucao}';

    protected $description = 'Recupera contas ML needs_reauth com refresh_token valido (sem re-auth do usuario)';

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $limit    = (int) $this->option('limit');

        // NOV-082: plataformas cobertas incluem 'ml' alem de 'mercadolivre'/'mercado_livre'
        $this->info('[ML-Recover] Buscando contas needs_reauth com ml_refresh_token valido...');
        $recoveryAccounts = MarketplaceAccount::whereIn('platform', ['mercadolivre', 'mercado_livre', 'ml'])
            // FOR-097: status='needs_reauth' (string) e needs_reauth=1 (bool) sao gravados
            // por pontos diferentes do codigo -- MercadoLivreReconciliationAdapter (FOR-087,
            // 403 em reconciliacao) so grava o bool + sync_blocked_at, nunca o status string.
            // Sem o OR aqui essas contas ficavam invisiveis tanto pra esta busca quanto pra
            // "proativa" abaixo (que exige sync_blocked_at nulo) -- 157 contas confirmadas
            // presas nesse ponto cego em 30/07, 5/5 testadas manualmente recuperaram sozinhas
            // (refresh_token ainda valido, nao precisavam de reauth nenhum).
            ->where(function ($q) {
                $q->where('status', 'needs_reauth')->orWhere('needs_reauth', 1);
            })
            ->whereNotNull('ml_refresh_token')
            // FOR-082: whereNull(sync_blocked_at) removido -- needs_reauth deve tentar refresh
            // mesmo com circuit breaker de sync ativo. Block de sync != block de token refresh.
            ->limit($limit)
            ->get();

        $this->info('[ML-Recover] Buscando contas ativas com token expirando em < 3h (renovacao proativa)...');
        $proactiveAccounts = MarketplaceAccount::whereIn('platform', ['mercadolivre', 'mercado_livre', 'ml'])
            ->whereIn('status', ['active', 'connected'])
            ->whereNotNull('ml_refresh_token')
            ->whereNotNull('ml_token_expires_at')
            ->where('ml_token_expires_at', '<=', now()->addHours(3))
            ->whereNull('sync_blocked_at')
            ->limit($limit)
            ->get();

        // NOV-082: logar contas platform=ml sem refresh_token (irrecuperaveis sem reauth manual)
        $this->checkMlPlatformWithoutRefreshToken($isDryRun);

        $accounts = $recoveryAccounts->merge($proactiveAccounts)->unique('id');

        $this->info('[ML-Recover] ' . $recoveryAccounts->count() . ' needs_reauth + ' . $proactiveAccounts->count() . ' proativas = ' . $accounts->count() . ' total.');

        if ($isDryRun) {
            $this->warn('[DRY-RUN] Nenhuma acao executada.');
            foreach ($accounts as $acc) {
                $tag = $acc->status === 'needs_reauth' ? 'RECOVERY' : 'PROATIVO';
                $this->line('  [' . $tag . '] ID=' . $acc->id . ' platform=' . $acc->platform . ' status=' . $acc->status . ' ml_expires=' . $acc->ml_token_expires_at . ' updated=' . $acc->updated_at);
            }
            return self::SUCCESS;
        }

        if ($accounts->isEmpty()) {
            $this->info('[ML-Recover] Nenhuma conta elegivel.');
            return self::SUCCESS;
        }

        $mlService = app(MercadoLivreService::class);
        $recovered = 0;
        $permanent = 0;
        $temporary = 0;

        foreach ($accounts as $account) {
            $this->line('  Tentando ID=' . $account->id . ' platform=' . $account->platform . '...');

            // Alterar temporariamente para active para permitir refreshToken
            $account->status = 'active';

            $newToken = $mlService->refreshToken($account);
            $account->refresh();

            if ($newToken) {
                $account->update([
                    'status'               => 'active',
                    'sync_errors_count'    => 0,
                    'refresh_errors_count' => 0,
                    'sync_blocked_at'      => null,
                    'last_error_message'   => null,
                    'last_token_refresh_at' => now(),
                ]);
                $recovered++;
                $this->info('    [OK] ID=' . $account->id . ' recuperado. expires=' . $account->fresh()->ml_token_expires_at);
                Log::info('[ML-Recover] Conta recuperada', ['account_id' => $account->id, 'platform' => $account->platform]);

                $this->dispatchPostRecoverySync($account);

            } elseif ($account->status === 'needs_reauth') {
                $permanent++;
                $this->warn('    [PERM] ID=' . $account->id . ' requer reauth manual (invalid_grant).');
                Log::warning('[ML-Recover] Conta requer reauth manual', ['account_id' => $account->id, 'platform' => $account->platform]);
            } else {
                $temporary++;
                $account->update(['status' => 'needs_reauth']);
                $this->warn('    [TEMP] ID=' . $account->id . ' erro temporario, retry depois.');
                Log::info('[ML-Recover] Erro temporario, mantendo needs_reauth', ['account_id' => $account->id, 'platform' => $account->platform]);
            }
        }

        $this->newLine();
        $this->table(
            ['Resultado', 'Qtd'],
            [
                ['Recuperadas com sucesso (voltaram para active)', $recovered],
                ['Reauth permanente necessario (invalid_grant)', $permanent],
                ['Erro temporario (retry depois)', $temporary],
            ]
        );

        Log::info('[ML-Recover] Varredura concluida', [
            'total'     => $accounts->count(),
            'recovered' => $recovered,
            'permanent' => $permanent,
            'temporary' => $temporary,
        ]);

        $this->info('[ML-Recover] Concluido.');
        return self::SUCCESS;
    }

    /**
     * NOV-082: Verifica contas platform=ml sem ml_refresh_token.
     * Irrecuperaveis automaticamente -- precisam de reauth manual.
     * Marca sync_blocked_at para nao tentar em loop a cada ciclo.
     */
    protected function checkMlPlatformWithoutRefreshToken(bool $isDryRun): void
    {
        $withoutToken = MarketplaceAccount::where('platform', 'ml')
            ->where('status', 'needs_reauth')
            ->where(function ($q) {
                $q->whereNull('ml_refresh_token')
                  ->orWhere('ml_refresh_token', '=', '');
            })
            ->whereNull('sync_blocked_at')
            ->get();

        if ($withoutToken->isEmpty()) {
            return;
        }

        $this->warn('[ML-Recover] ' . $withoutToken->count() . ' conta(s) platform=ml SEM refresh_token (reconexao manual necessaria):');

        foreach ($withoutToken as $account) {
            $this->line('  [SEM-TOKEN] ID=' . $account->id . ' client_id=' . $account->client_id . ' shop_id=' . $account->shop_id);
            Log::warning('[ML-Recover] Conta platform=ml sem refresh_token -- reconexao manual necessaria', [
                'account_id' => $account->id,
                'client_id'  => $account->client_id,
                'shop_id'    => $account->shop_id,
            ]);

            if (!$isDryRun) {
                $account->update([
                    'status'             => 'needs_reauth',
                    'sync_blocked_at'    => now(),
                    'last_error_message' => 'platform=ml sem ml_refresh_token: reconexao OAuth manual necessaria',
                ]);
            }
        }
    }

    /**
     * MES-023: Dispara sync automatico apos recuperacao bem-sucedida do token ML.
     */
    protected function dispatchPostRecoverySync(MarketplaceAccount $account): void
    {
        CheckMLListingsJob::dispatch()->onQueue('inventory')->delay(now()->addSeconds(5));
        $this->line('    [SYNC] CheckMLListingsJob enfileirado para conta ID=' . $account->id);
        Log::info('[ML-Recover] CheckMLListingsJob disparado pos-recuperacao', ['account_id' => $account->id]);

        $draftProducts = ClientProduct::where('marketplace_account_id', $account->id)
            ->where('listing_status', 'draft')
            ->whereNotNull('external_listing_id')
            ->select(['id', 'external_listing_id'])
            ->get();

        if ($draftProducts->isNotEmpty()) {
            $this->line('    [SYNC] ' . $draftProducts->count() . ' produto(s) draft com external_listing_id -- enfileirando republish.');
            Log::info('[ML-Recover] Enfileirando republish de drafts', [
                'account_id' => $account->id,
                'count'      => $draftProducts->count(),
                'ids'        => $draftProducts->pluck('id')->toArray(),
            ]);
            foreach ($draftProducts as $product) {
                PublishClientProductToMLJob::dispatch($product->id)
                    ->onQueue('inventory')
                    ->delay(now()->addSeconds(10));
            }
        } else {
            $this->line('    [SYNC] Nenhum produto draft com external_listing_id -- nada a re-publicar.');
        }
    }
}