<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * wallet:reconcile — MUL-363 Fase 0.
 *
 * O saldo (client_supplier_balances.balance) e um CACHE derivado do ledger
 * (client_supplier_transactions). Este comando confere o invariante
 * balance == SUM(creditos) - SUM(debitos) para toda wallet e grita quando
 * divergir. Divergencia = incidente financeiro (alguem escreveu fora do
 * WalletLedger ou corrompeu dado) — nunca "corrigir na mao e seguir".
 *
 * Schedule: diario (routes/console.php). Exit 1 se houver divergencia.
 */
class WalletReconcileCommand extends Command
{
    protected $signature = 'wallet:reconcile {--client= : Limita a um client_id}';

    protected $description = 'Confere o invariante saldo == soma do ledger em todas as wallets';

    public function handle(): int
    {
        $q = DB::table('client_supplier_balances as b')
            ->leftJoin('client_supplier_transactions as t', function ($j) {
                $j->on('t.client_id', '=', 'b.client_id')->on('t.supplier_id', '=', 'b.supplier_id');
            })
            ->groupBy('b.id', 'b.client_id', 'b.supplier_id', 'b.balance')
            ->selectRaw("b.client_id, b.supplier_id, b.balance,
                COALESCE(SUM(CASE WHEN t.type='credit' THEN t.amount WHEN t.type='debit' THEN -t.amount ELSE 0 END), 0) AS ledger_sum");

        if ($this->option('client')) {
            $q->where('b.client_id', (int) $this->option('client'));
        }

        $rows = $q->get();
        $divergent = [];

        foreach ($rows as $r) {
            if (abs((float) $r->balance - (float) $r->ledger_sum) > 0.009) {
                $divergent[] = $r;
                $this->error(sprintf(
                    '  DIVERGENTE client %d / supplier %d: saldo %.2f vs ledger %.2f (delta %.2f)',
                    $r->client_id, $r->supplier_id, $r->balance, $r->ledger_sum, $r->balance - $r->ledger_sum
                ));
            }
        }

        $this->info(sprintf('[wallet:reconcile] %d wallets conferidas, %d divergentes.', count($rows), count($divergent)));

        if ($divergent !== []) {
            Log::error('[wallet:reconcile] invariante saldo==SUM(ledger) VIOLADO', [
                'divergentes' => array_map(fn ($r) => [
                    'client_id' => $r->client_id, 'supplier_id' => $r->supplier_id,
                    'balance' => $r->balance, 'ledger_sum' => $r->ledger_sum,
                ], $divergent),
            ]);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
