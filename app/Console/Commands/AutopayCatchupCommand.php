<?php

namespace App\Console\Commands;

use App\Jobs\TryAutoPayJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * autopay:catchup — MUL-363 (evento unico).
 *
 * O autopay novo dispara no evento "ficou pagavel"; pedidos que JA estavam
 * pagaveis antes do deploy nunca vao transicionar de novo. Este comando
 * one-shot despacha o MESMO executor (TryAutoPayJob) pra esse backlog.
 * NAO e agendado — roda manualmente, com --limit pra lote-piloto.
 * Idempotente: nucleo tem UNIQUE auto_pay:order:<id>.
 */
class AutopayCatchupCommand extends Command
{
    protected $signature = 'autopay:catchup {--limit=20 : Quantos pedidos despachar} {--client= : Limitar a um client_id} {--dry-run : So lista, nao despacha}';

    protected $description = 'Despacha autopay pro backlog de pedidos pagaveis (one-shot, lote controlado)';

    public function handle(): int
    {
        $limit  = (int) $this->option('limit');
        $client = $this->option('client');

        $q = DB::table('orders as o')
            ->join('clients as c', 'c.id', '=', 'o.client_id')
            ->where('c.auto_pay_from_wallet', 1)
            ->whereNull('o.wallet_paid_at')
            ->where('o.supplier_total', '>', 0)
            ->where('o.is_draft', 0)
            ->whereNotIn('o.status', ['cancelled', 'canceled'])
            ->where(function ($w) {
                $w->whereNotNull('o.label_url')
                  ->orWhereNotNull('o.manual_label_path')
                  ->orWhereIn('o.status', ['shipped', 'delivered'])
                  ->orWhereRaw("LOWER(CONCAT_WS(' ', COALESCE(o.carrier_name,''), COALESCE(o.shipping_mode,''), COALESCE(o.channel_name,''))) REGEXP 'fulfillment|fba'");
            })
            ->orderBy('o.id');

        if ($client) {
            $q->where('o.client_id', (int) $client);
        }

        $rows = $q->limit($limit)->get(['o.id', 'o.order_number', 'o.client_id', 'o.supplier_total']);
        $total = round((float) $rows->sum('supplier_total'), 2);

        foreach ($rows as $r) {
            $this->line(sprintf('  pedido %d (%s) client %d — R$ %.2f', $r->id, $r->order_number, $r->client_id, $r->supplier_total));
            if (! $this->option('dry-run')) {
                TryAutoPayJob::dispatch((int) $r->id)->onQueue('default');
            }
        }

        $this->info(sprintf('[autopay:catchup] %d pedido(s), R$ %.2f %s.',
            $rows->count(), $total, $this->option('dry-run') ? 'listados (dry-run)' : 'despachados'));

        return self::SUCCESS;
    }
}
