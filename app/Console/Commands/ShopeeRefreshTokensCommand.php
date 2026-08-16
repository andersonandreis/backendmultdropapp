<?php

namespace App\Console\Commands;

use App\Models\MarketplaceAccount;
use App\Services\Integrations\Marketplaces\ShopeeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * shopee:refresh-tokens
 *
 * Renova tokens Shopee com expiry NULL (tokens legados migrados sem data)
 * ou proximos de vencer (< 4h).
 *
 * Uso:
 *   php artisan shopee:refresh-tokens           -- todos os elegíveis
 *   php artisan shopee:refresh-tokens --dry-run -- só lista, não renova
 *   php artisan shopee:refresh-tokens --null-only -- só os com expiry NULL
 *
 * Agendado diariamente em routes/console.php.
 *
 * Criado em 17/06/2026 — MUL-021
 */
class ShopeeRefreshTokensCommand extends Command
{
    protected $signature = 'shopee:refresh-tokens
                            {--dry-run : Lista contas elegíveis sem renovar}
                            {--null-only : Processa apenas contas com token_expires_at NULL}
                            {--force : Força renovação mesmo de tokens válidos}';

    protected $description = 'Renova tokens Shopee expirados ou sem data de expiração (tokens legados)';

    // Threshold: renova se faltam menos de 4 horas
    private const EXPIRY_THRESHOLD_HOURS = 4;

    public function handle(): int
    {
        $isDryRun  = $this->option('dry-run');
        $nullOnly  = $this->option('null-only');
        $force     = $this->option('force');

        $this->info('[ShopeeRefresh] Iniciando varredura de tokens Shopee...');

        // 1. Contas com token_expires_at NULL (legados — nunca tiveram expiry preenchido)
        $nullQuery = MarketplaceAccount::where('platform', 'shopee')
            ->where('status', 'active')
            ->whereNull('sync_blocked_at')
            ->whereNull('token_expires_at')
            ->whereNotNull('refresh_token');

        // 2. Contas com token próximo de vencer (< 4h)
        $expiringQuery = MarketplaceAccount::where('platform', 'shopee')
            ->where('status', 'active')
            ->whereNull('sync_blocked_at')
            ->whereNotNull('token_expires_at')
            ->where('token_expires_at', '<=', now()->addHours(self::EXPIRY_THRESHOLD_HOURS))
            ->whereNotNull('refresh_token');

        if ($force) {
            // No --force, pega todas as ativas com refresh_token
            $accounts = MarketplaceAccount::where('platform', 'shopee')
                ->where('status', 'active')
                ->whereNotNull('refresh_token')
                ->get();
        } elseif ($nullOnly) {
            $accounts = $nullQuery->get();
        } else {
            $accounts = $nullQuery->union($expiringQuery)->get();
        }

        $totalNull     = $nullQuery->count();
        $totalExpiring = $expiringQuery->count();
        $totalAll      = $accounts->count();

        $this->table(
            ['Métrica', 'Qtd'],
            [
                ['Tokens com expiry NULL (legados)', $totalNull],
                ['Tokens expirando em < 4h', $totalExpiring],
                ['Total elegíveis para renovação', $totalAll],
            ]
        );

        if ($isDryRun) {
            $this->warn('[DRY-RUN] Nenhuma ação executada.');
            foreach ($accounts as $acc) {
                $this->line("  shop_id={$acc->shop_id} | status={$acc->status} | expires={$acc->token_expires_at} | refresh_token=" . ($acc->refresh_token ? 'OK' : 'NULL'));
            }
            return self::SUCCESS;
        }

        if ($totalAll === 0) {
            $this->info('[ShopeeRefresh] Nenhum token elegível para renovação.');
            return self::SUCCESS;
        }

        $shopeeService = app(ShopeeService::class);
        $renewed    = 0;
        $failed     = 0;
        $needsReauth = 0;
        $skipped    = 0;

        // NOV-181: WL em modo bridge nao renova contas gerenciadas pelo hub central
        $usesBridge = app(\App\Services\InstallationConfig::class)->usesBridge('shopee');

        foreach ($accounts as $account) {
            if ($usesBridge && $account->centrally_managed) {
                $skipped++;
                $this->line("  [SKIP] shop_id={$account->shop_id} — centrally_managed (hub central renova)");
                Log::info('[ShopeeRefresh] PULA conta centrally_managed (bridge mode, hub renova)', [
                    'account_id' => $account->id,
                    'shop_id'    => $account->shop_id,
                ]);
                continue;
            }

            try {
                $newToken = $shopeeService->refreshToken($account);

                if ($newToken) {
                    $renewed++;
                    $this->info("  [OK] shop_id={$account->shop_id} renovado. Novo expires_at={$account->fresh()->token_expires_at}");
                    Log::info('[ShopeeRefresh] Token renovado', [
                        'account_id' => $account->id,
                        'shop_id'    => $account->shop_id,
                    ]);
                } else {
                    // refresh_token inválido — token legado expirado (>30 dias sem uso)
                    $needsReauth++;
                    $account->update(['status' => 'needs_reauth']);
                    $this->warn("  [REAUTH] shop_id={$account->shop_id} — refresh_token inválido. Marcado como needs_reauth.");
                    Log::warning('[ShopeeRefresh] Token requer reauth', [
                        'account_id' => $account->id,
                        'shop_id'    => $account->shop_id,
                    ]);
                }
            } catch (\Throwable $e) {
                $failed++;
                $account->increment('sync_errors_count');

                // Circuit breaker: bloquear após 3 falhas
                if ($account->fresh()->sync_errors_count >= 3) {
                    $account->update(['sync_blocked_at' => now()]);
                    $this->error("  [BLOCKED] shop_id={$account->shop_id} — {$e->getMessage()} (circuit breaker)");
                } else {
                    $this->error("  [ERROR] shop_id={$account->shop_id} — {$e->getMessage()}");
                }

                Log::error('[ShopeeRefresh] Falha ao renovar token', [
                    'account_id' => $account->id,
                    'shop_id'    => $account->shop_id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        $this->newLine();
        $this->table(
            ['Resultado', 'Qtd'],
            [
                ['Renovados com sucesso', $renewed],
                ['Precisam de reauth (refresh_token expirado)', $needsReauth],
                ['Erro técnico (circuit breaker)', $failed],
                ['Puladas (centrally_managed / bridge)', $skipped],
            ]
        );

        Log::info('[ShopeeRefresh] Varredura concluída', [
            'total'      => $totalAll,
            'renewed'    => $renewed,
            'needs_reauth' => $needsReauth,
            'failed'     => $failed,
        ]);

        $this->info('[ShopeeRefresh] Concluído.');

        return self::SUCCESS;
    }
}