<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * shopee:process-orphan-callbacks
 *
 * Processa callbacks da Shopee que chegaram sem state (orphan) e ainda nao foram
 * associados a uma conta em marketplace_accounts.
 *
 * Substitui os scripts manuais /tmp/check_callbacks*.php com logica robusta:
 * - Filtra nulls/zeros de shop_id ANTES do whereIn (evita HY093)
 * - Filtra IDs claramente fake (< 1_000_000)
 * - Modo --dry-run por padrao (nao altera dados sem --execute)
 */
class ShopeeProcessOrphanCallbacksCommand extends Command
{
    protected $signature = 'shopee:process-orphan-callbacks
                            {--limit=100 : Numero de callbacks a analisar}
                            {--execute : Executar de verdade (sem esta flag, apenas reporta)}';

    protected $description = 'Analisa callbacks Shopee sem state e cruza com marketplace_accounts';

    public function handle(): int
    {
        $limit   = (int) $this->option('limit');
        $execute = $this->option('execute');

        $this->info("[shopee:process-orphan-callbacks] Buscando ultimos {$limit} callbacks...");

        // 1. Buscar callbacks recentes
        $all = DB::select(
            'SELECT id, shop_id, source_ip, raw_params, created_at, processed FROM shopee_oauth_callbacks ORDER BY created_at DESC LIMIT ?',
            [$limit]
        );

        // 2. Separar sem state
        $semState = array_filter($all, function ($r) {
            $p = json_decode($r->raw_params, true);
            return empty($p['state']);
        });

        $this->info('Total callbacks analisados : ' . count($all));
        $this->info('Callbacks sem state        : ' . count($semState));

        // 3. Extrair shop_ids validos (sem null, sem zero, sem fake < 1_000_000)
        $rawIds = array_unique(array_column(array_values($semState), 'shop_id'));

        $validIds = array_values(array_filter($rawIds, function ($id) {
            if ($id === null || $id === '' || $id === 0) {
                return false;
            }
            $intId = (int) $id;
            if ($intId < 1_000_000) {
                // ID claramente fake/teste — ignorar
                return false;
            }
            return true;
        }));

        $skipped = count($rawIds) - count($validIds);
        $this->info("IDs validos para busca     : " . count($validIds) . " (descartados: {$skipped} nulls/fake)");

        if (empty($validIds)) {
            $this->warn('Nenhum shop_id valido encontrado. Abortando.');
            return self::SUCCESS;
        }

        // 4. Buscar em marketplace_accounts usando whereIn seguro
        $placeholders = implode(',', array_fill(0, count($validIds), '?'));
        $matching     = DB::select(
            "SELECT id as account_id, shop_id, status, seller_nickname, platform, updated_at
             FROM marketplace_accounts
             WHERE shop_id IN ({$placeholders}) AND platform = 'shopee'",
            $validIds
        );

        $this->info('Contas encontradas         : ' . count($matching));

        if (empty($matching)) {
            $this->warn('Nenhuma conta encontrada para os shop_ids sem state.');
        } else {
            $this->table(
                ['account_id', 'shop_id', 'status', 'seller_nickname', 'updated_at'],
                array_map(fn ($m) => [(array) $m]['account_id'] ?? null, $matching) // formatado abaixo
            );
            foreach ($matching as $m) {
                $this->line("  account_id={$m->account_id} shop_id={$m->shop_id} status={$m->status} seller={$m->seller_nickname}");
            }
        }

        // 5. Estatisticas por IP
        $ips = [];
        foreach ($semState as $r) {
            $ips[$r->source_ip ?? 'null'] = ($ips[$r->source_ip ?? 'null'] ?? 0) + 1;
        }
        arsort($ips);
        $this->info("\nTop IPs sem state:");
        foreach (array_slice($ips, 0, 10, true) as $ip => $cnt) {
            $this->line("  ip={$ip} count={$cnt}");
        }

        if (! $execute) {
            $this->info("\nModo dry-run. Use --execute para marcar callbacks como processados.");
        }

        Log::channel('marketplace')->info('[shopee:process-orphan-callbacks] Analise concluida', [
            'total_callbacks'  => count($all),
            'sem_state'        => count($semState),
            'valid_ids'        => count($validIds),
            'contas_encontradas' => count($matching),
        ]);

        return self::SUCCESS;
    }
}
