<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reconcilia saldos de client_supplier_balances com o legado (conta_corrente).
 * Saldo autoritativo = SUM(C) - SUM(D) na conta_corrente do legado.
 *
 * Se houver divergência >= R$0,01, cria ajuste em client_supplier_transactions
 * e atualiza client_supplier_balances.
 *
 * Idempotente: não duplica ajuste se já existe transaction_type='reconciliation'
 * com o mesmo valor criado no mesmo dia.
 *
 *   --supplier-id=N : supplier_id alvo (padrão: LOCAL_SUPPLIER_ID do .env)
 *   --dry-run       : só mostra, não grava
 *
 * MUL-060 — reconciliação de saldos pós-importação
 */
class ReconcileLegacyBalances extends Command
{
    protected $signature = 'finance:reconcile-legacy
        {--supplier-id= : supplier_id alvo (padrão: LOCAL_SUPPLIER_ID do .env)}
        {--dry-run      : só mostra divergências, não grava ajustes}';

    protected $description = 'Reconcilia saldos de clientes com o saldo autoritativo do legado';

    public function handle(): int
    {
        $supplierId = (int) ($this->option('supplier-id') ?: env('LOCAL_SUPPLIER_ID', 1));
        $dry        = (bool) $this->option('dry-run');

        $this->line(sprintf(
            '[finance:reconcile-legacy] supplier_id=%d | dry-run=%s',
            $supplierId,
            $dry ? 'SIM' : 'nao'
        ));

        // MUL-269 fase 2: nome do seller vem do user (clients.company_name removido).
        $clients = DB::table('clients as c')
            ->join('client_supplier_balances as b', 'b.client_id', '=', 'c.id')
            ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->whereNotNull('c.legacy_id_login')
            ->where('c.is_active', 1)
            ->where('b.supplier_id', $supplierId)
            ->get(['c.id as client_id', 'c.legacy_id_login', DB::raw("COALESCE(NULLIF(u.full_name,''), u.name) as company_name"), 'b.balance']);

        $this->line("Clientes a verificar: " . $clients->count());

        $adjustCount = 0;
        $skipped     = 0;

        foreach ($clients as $cli) {
            $lid     = (int) $cli->legacy_id_login;
            $balNovo = round((float) $cli->balance, 2);

            // Saldo autoritativo do legado
            $row = DB::connection('legacy')
                ->selectOne(
                    'SELECT ROUND(SUM(CASE WHEN tipo="C" THEN valor ELSE 0 END)'
                    . ' - SUM(CASE WHEN tipo="D" THEN valor ELSE 0 END), 2) as saldo'
                    . ' FROM conta_corrente WHERE id_login=? AND status=1',
                    [$lid]
                );
            $balLegado = round((float) ($row->saldo ?? 0), 2);
            $diff      = round($balLegado - $balNovo, 2);

            if (abs($diff) < 0.01) {
                $this->line(sprintf(
                    '  OK    cid=%d %-25s saldo=%.2f',
                    $cli->client_id,
                    mb_substr($cli->company_name, 0, 25),
                    $balNovo
                ));
                continue;
            }

            $this->warn(sprintf(
                '  DIFF  cid=%d %-25s legado=%.2f novo=%.2f diff=%+.2f',
                $cli->client_id,
                mb_substr($cli->company_name, 0, 25),
                $balLegado,
                $balNovo,
                $diff
            ));

            if ($dry) {
                $adjustCount++;
                continue;
            }

            // Verificar se já existe ajuste de reconciliação criado hoje para não duplicar
            $today = now()->toDateString();
            $alreadyAdjusted = DB::table('client_supplier_transactions')
                ->where('client_id', $cli->client_id)
                ->where('supplier_id', $supplierId)
                ->where('transaction_type', 'reconciliation')
                ->whereDate('created_at', $today)
                ->exists();

            if ($alreadyAdjusted) {
                $this->line("    SKIP — ajuste de reconciliação já existe hoje para cid={$cli->client_id}");
                $skipped++;
                continue;
            }

            DB::transaction(function () use ($cli, $supplierId, $diff, $balLegado) {
                $now  = now();
                $type = $diff > 0 ? 'credit' : 'debit';
                DB::table('client_supplier_transactions')->insert([
                    'client_id'        => $cli->client_id,
                    'supplier_id'      => $supplierId,
                    'type'             => $type,
                    'amount'           => abs($diff),
                    'description'      => 'Ajuste de reconciliação migração legado',
                    'reference'        => 'legacy_reconciliation',
                    'transaction_type' => 'reconciliation',
                    'legacy_cc_id'     => null,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ]);
                DB::table('client_supplier_balances')
                    ->where('client_id', $cli->client_id)
                    ->where('supplier_id', $supplierId)
                    ->update(['balance' => $balLegado, 'updated_at' => $now]);
            });

            $this->info(sprintf(
                '    AJUSTE cid=%d tipo=%s valor=%.2f => saldo=%.2f',
                $cli->client_id,
                $diff > 0 ? 'credit' : 'debit',
                abs($diff),
                $balLegado
            ));
            $adjustCount++;
        }

        $this->info(sprintf(
            '[finance:reconcile-legacy] Concluído. Verificados=%d | Ajustes=%d | Skipped=%d',
            $clients->count(), $adjustCount, $skipped
        ));
        Log::info("finance:reconcile-legacy supplier_id={$supplierId} adjustments={$adjustCount}");

        return self::SUCCESS;
    }
}
