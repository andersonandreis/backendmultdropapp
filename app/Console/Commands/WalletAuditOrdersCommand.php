<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * wallet:audit-orders — MUL-362 F4 (cinto e suspensório do reconcile).
 *
 * O wallet:reconcile confere saldo == SUM(ledger), mas DUPLA de cobrança é
 * internamente consistente (debitou 2x, saldo caiu 2x) e passa invisível.
 * Este comando audita POR PEDIDO: débitos − créditos vs custo atual.
 * Achou pedido cobrado acima do custo → Log::error + exit 1 (incidente).
 *
 * Todos os caminhos de cobrança já têm guarda (MUL-363 fases 1-3); isto aqui
 * existe pra pegar o caso que ninguém previu.
 */
class WalletAuditOrdersCommand extends Command
{
    protected $signature = 'wallet:audit-orders {--days=30 : Janela de transacoes analisada}';

    protected $description = 'Audita cobranca acima do custo por pedido (duplas e excessos)';

    public function handle(): int
    {
        $days = (int) $this->option('days');

        $rows = DB::select("
            SELECT o.id, o.order_number, o.client_id, o.supplier_total custo,
                   x.net, x.n_deb
            FROM orders o
            JOIN (
                SELECT order_id,
                       SUM(CASE WHEN type='debit' THEN amount ELSE -amount END) net,
                       COUNT(CASE WHEN type='debit' THEN 1 END) n_deb,
                       MAX(created_at) last_tx
                FROM client_supplier_transactions
                WHERE order_id IS NOT NULL
                GROUP BY order_id
            ) x ON x.order_id = o.id
            WHERE x.net > o.supplier_total + 0.01
              AND o.supplier_total > 0
              AND x.last_tx >= NOW() - INTERVAL {$days} DAY
        ");

        foreach ($rows as $r) {
            $this->error(sprintf(
                '  EXCESSO pedido %d (%s) client %d: custo %.2f, cobrado(net) %.2f (%d debitos)',
                $r->id, $r->order_number, $r->client_id, $r->custo, $r->net, $r->n_deb
            ));
        }

        $this->info(sprintf('[wallet:audit-orders] janela %dd: %d pedido(s) cobrados acima do custo.', $days, count($rows)));

        if ($rows !== []) {
            Log::error('[wallet:audit-orders] pedidos cobrados acima do custo detectados', [
                'quantidade' => count($rows),
                'pedidos'    => array_map(fn ($r) => [
                    'order_id' => $r->id, 'client_id' => $r->client_id,
                    'custo' => $r->custo, 'net' => $r->net, 'n_deb' => $r->n_deb,
                ], array_slice($rows, 0, 50)),
            ]);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
