<?php

namespace App\Console\Commands;

use App\Models\MarketplaceAccount;
use App\Services\Integrations\Marketplaces\ShopeeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * shopee:recover-tokens
 *
 * Tenta recuperar contas Shopee marcadas como needs_reauth ou expired que
 * ainda possuem shop_id e refresh_token.
 *
 * Criado em 2026-06-25 -- investigacao de 57 contas needs_reauth
 * NOV-082 (2026-06-25): cobre status 'expired' alem de 'needs_reauth'
 */
class ShopeeRecoverTokensCommand extends Command
{
    protected $signature = 'shopee:recover-tokens
                            {--dry-run : Lista contas elegiveis sem executar}
                            {--limit=50 : Limite de contas por execucao}
                            {--ids= : IDs especificos separados por virgula}';

    protected $description = 'Recupera contas Shopee needs_reauth/expired com refresh_token valido (sem re-auth do usuario)';

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $limit    = (int) $this->option('limit');
        $idsOpt   = $this->option('ids');

        $this->info('[ShopeeRecover] Buscando contas needs_reauth/expired com shop_id e refresh_token...');

        $query = MarketplaceAccount::where('platform', 'shopee')
            ->whereIn('status', ['needs_reauth', 'expired'])
            ->whereNotNull('shop_id')
            ->whereNotNull('refresh_token')
            ->where('refresh_token', '!=', '');

        if ($idsOpt) {
            $ids = array_map('intval', explode(',', $idsOpt));
            $query->whereIn('id', $ids);
        } else {
            // Apenas contas com refresh_token_expires_at ainda valido (ou sem data)
            $query->where(function ($q) {
                $q->whereNull('refresh_token_expires_at')
                  ->orWhere('refresh_token_expires_at', '>', now());
            });
        }

        $accounts = $query->limit($limit)->get();

        $this->info('[ShopeeRecover] ' . $accounts->count() . ' conta(s) elegivel(is).');

        if ($isDryRun) {
            $this->warn('[DRY-RUN] Nenhuma acao executada.');
            foreach ($accounts as $acc) {
                $this->line('  ID=' . $acc->id . ' shop_id=' . $acc->shop_id . ' status=' . $acc->status . ' erros=' . $acc->sync_errors_count . ' refresh_exp=' . $acc->refresh_token_expires_at);
            }
            return self::SUCCESS;
        }

        if ($accounts->isEmpty()) {
            $this->info('[ShopeeRecover] Nenhuma conta elegivel.');
            return self::SUCCESS;
        }

        $service   = app(ShopeeService::class);
        $recovered = 0;
        $permanent = 0;
        $temporary = 0;
        $skipped   = 0;

        // NOV-181: WL em modo bridge nao recupera contas gerenciadas pelo hub central
        $usesBridge = app(\App\Services\InstallationConfig::class)->usesBridge('shopee');

        foreach ($accounts as $account) {
            if ($usesBridge && $account->centrally_managed) {
                $skipped++;
                $this->line('  [SKIP] ID=' . $account->id . ' shop_id=' . $account->shop_id . ' — centrally_managed (hub central renova)');
                Log::info('[ShopeeRecover] PULA conta centrally_managed (bridge mode, hub renova)', [
                    'account_id' => $account->id,
                    'shop_id'    => $account->shop_id,
                ]);
                continue;
            }

            $this->line('  Tentando ID=' . $account->id . ' shop_id=' . $account->shop_id . ' (status=' . $account->status . ')...');

            // Mudar temporariamente para active para permitir refreshToken()
            $account->status = 'active';

            try {
                $newToken = $service->refreshToken($account);
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
                    $this->info('    [OK] ID=' . $account->id . ' recuperado. Novo token_expires_at=' . $account->fresh()->token_expires_at);
                    Log::info('[ShopeeRecover] Conta recuperada', ['account_id' => $account->id, 'shop_id' => $account->shop_id]);
                } else {
                    // refresh retornou null = erro permanente (refresh_token_expired ou shop_no_linked)
                    $permanent++;
                    // HUB-182: Shopee confirmou refresh_token morto — marcar expirado
                    // pra sair da elegibilidade (antes: re-tentava a cada 15min pra sempre).
                    // Reauth manual via OAuth grava tokens+datas novos e volta ao normal.
                    $account->update(['status' => 'needs_reauth', 'refresh_token_expires_at' => now()]);
                    $this->warn('    [PERM] ID=' . $account->id . ' requer reauth manual (refresh_token expirado ou shop desvinculado).');
                    Log::warning('[ShopeeRecover] Conta requer reauth manual', ['account_id' => $account->id, 'shop_id' => $account->shop_id]);
                }
            } catch (\Throwable $e) {
                $temporary++;
                $account->update(['status' => 'needs_reauth']);
                $this->error('    [ERR] ID=' . $account->id . ': ' . $e->getMessage());
                Log::error('[ShopeeRecover] Excecao ao tentar recuperar', [
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
                ['Reauth permanente necessario (token expirado / shop desvinculado)', $permanent],
                ['Erro temporario / excecao', $temporary],
                ['Puladas (centrally_managed / bridge)', $skipped],
            ]
        );

        Log::info('[ShopeeRecover] Varredura concluida', [
            'total'     => $accounts->count(),
            'recovered' => $recovered,
            'permanent' => $permanent,
            'temporary' => $temporary,
        ]);

        $this->info('[ShopeeRecover] Concluido.');
        return self::SUCCESS;
    }
}